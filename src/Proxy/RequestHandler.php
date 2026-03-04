<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Model\Provider;
use CcSwitch\Model\ProxyConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Main request processing pipeline for the proxy server.
 *
 * Flow: Request -> detectAppType -> ModelMapper -> CircuitBreaker check
 *       -> FormatConverter -> forward request -> process response
 *       -> CircuitBreaker record -> UsageLogger
 */
class RequestHandler
{
    /** @var array<string, mixed> */
    private array $proxyOptions = [];

    public function __construct(
        private readonly FailoverManager $failoverManager,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly ModelMapper $modelMapper,
        private readonly FormatConverter $formatConverter,
        private readonly StreamHandler $streamHandler,
        private readonly UsageLogger $usageLogger,
        private readonly ProxyConfigRepository $configRepo,
        private readonly ?SettingsRepository $settingsRepo = null,
    ) {
    }

    /**
     * Set global proxy options for Guzzle clients.
     *
     * @param array<string, mixed> $options e.g. ['proxy' => 'http://...']
     */
    public function setProxyOptions(array $options): void
    {
        $this->proxyOptions = $options;
        $this->streamHandler->setProxyOptions($options);
    }

    /**
     * Handle an incoming Swoole HTTP request.
     */
    public function handle(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';
        $method = strtoupper($request->server['request_method'] ?? 'GET');

        // Health check
        if ($path === '/health' && $method === 'GET') {
            $this->respondJson($response, 200, [
                'status' => 'healthy',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return;
        }

        // Status endpoint
        if ($path === '/status' && $method === 'GET') {
            $this->respondJson($response, 200, ['status' => 'running']);
            return;
        }

        // Only POST for API endpoints
        if ($method !== 'POST') {
            $this->respondJson($response, 405, ['error' => 'Method not allowed']);
            return;
        }

        // Detect app type from URL path
        $appType = $this->detectAppType($path, $request->header ?? []);
        if ($appType === null) {
            $this->respondJson($response, 404, ['error' => 'Unknown API endpoint']);
            return;
        }

        // Parse request body
        $rawBody = $request->rawContent();
        $body = json_decode($rawBody ?: '{}', true);
        if (!is_array($body)) {
            $this->respondJson($response, 400, ['error' => 'Invalid JSON body']);
            return;
        }

        // Load proxy config
        $config = $this->loadConfig($appType);
        if ($config === null || !$config->enabled) {
            $this->respondJson($response, 503, ['error' => "Proxy not enabled for {$appType}"]);
            return;
        }

        // Resolve provider (with circuit breaker + failover)
        $provider = $this->failoverManager->resolve($appType);
        if ($provider === null) {
            $this->respondJson($response, 503, [
                'error' => 'All providers are unavailable',
                'type' => 'overloaded_error',
            ]);
            return;
        }

        $startTime = microtime(true);

        // Decode provider config
        $providerConfig = json_decode($provider->settings_config, true);
        if (!is_array($providerConfig)) {
            $providerConfig = [];
        }

        // Apply model mapping
        $mappingResult = $this->modelMapper->apply($body, $providerConfig);
        $body = $mappingResult['body'];
        $originalModel = $mappingResult['originalModel'];
        $mappedModel = $mappingResult['mappedModel'];
        $effectiveModel = $mappedModel ?? $originalModel ?? 'unknown';

        // Determine provider's API format and if conversion is needed
        $meta = $provider->decodeMeta();
        $providerFormat = $meta->apiFormat ?? $this->inferProviderFormat($appType);
        $requestFormat = $this->detectRequestFormat($path, $body);

        // Format conversion if needed
        if ($requestFormat === 'anthropic' && $providerFormat === 'openai') {
            $body = $this->formatConverter->anthropicToOpenAI($body);
        } elseif ($requestFormat === 'openai' && $providerFormat === 'anthropic') {
            $body = $this->formatConverter->openAIToAnthropic($body);
        }

        // Determine if streaming
        $isStreaming = (bool) ($body['stream'] ?? false);

        // Build upstream URL and headers
        $upstreamUrl = $this->buildUpstreamUrl($provider, $providerConfig, $path);
        $upstreamHeaders = $this->buildUpstreamHeaders($provider, $providerConfig, $request->header ?? []);

        if ($isStreaming) {
            $this->handleStreamingRequest(
                $response, $provider, $upstreamHeaders, $upstreamUrl,
                $body, $config, $appType, $effectiveModel, $originalModel,
                $startTime, $meta->costMultiplier ?? '1.0', $meta->providerType
            );
        } else {
            $this->handleNonStreamingRequest(
                $response, $provider, $upstreamHeaders, $upstreamUrl,
                $body, $config, $appType, $effectiveModel, $originalModel,
                $startTime, $meta->costMultiplier ?? '1.0', $meta->providerType
            );
        }
    }

    /**
     * Detect application type from URL path and headers.
     */
    public function detectAppType(string $path, array $headers): ?string
    {
        // Normalize path
        $path = rtrim($path, '/');

        // Claude / Anthropic Messages API
        if (str_contains($path, '/v1/messages') || str_contains($path, '/claude/')) {
            return 'claude';
        }

        // OpenAI Chat Completions (Codex)
        if (str_contains($path, '/v1/chat/completions') || str_contains($path, '/chat/completions')
            || str_contains($path, '/codex/')) {
            return 'codex';
        }

        // OpenAI Responses API (Codex)
        if (str_contains($path, '/v1/responses') || str_contains($path, '/responses')) {
            return 'codex';
        }

        // Gemini
        if (str_contains($path, '/v1beta/') || str_contains($path, '/gemini/')) {
            return 'gemini';
        }

        // Models endpoint - could be any, try to detect from headers
        if (str_contains($path, '/v1/models')) {
            $authHeader = $headers['authorization'] ?? $headers['x-api-key'] ?? '';
            if (str_contains($authHeader, 'anthropic')) {
                return 'claude';
            }
            return 'codex'; // Default to codex for /v1/models
        }

        return null;
    }

    private function handleStreamingRequest(
        Response $response,
        Provider $provider,
        array $headers,
        string $url,
        array $body,
        ProxyConfig $config,
        string $appType,
        string $model,
        ?string $requestModel,
        float $startTime,
        string $costMultiplier,
        ?string $providerType,
    ): void {
        $result = $this->streamHandler->forward(
            $response, $provider, $headers, $url,
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $config,
        );

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Record circuit breaker result
        if ($result['error'] !== null || $result['status'] >= 500) {
            $this->circuitBreaker->recordFailure($provider->id, $appType, $result['error'] ?? 'HTTP ' . $result['status']);
        } else {
            $this->circuitBreaker->recordSuccess($provider->id, $appType);
        }

        // Parse usage from stream events
        $usage = $this->usageLogger->parseClaudeStreamUsage($result['events']);

        // Log usage
        if ($config->enable_logging) {
            $this->usageLogger->log([
                'provider_id' => $provider->id,
                'app_type' => $appType,
                'model' => $model,
                'request_model' => $requestModel ?? $model,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cache_read_tokens' => $usage['cache_read_tokens'],
                'cache_creation_tokens' => $usage['cache_creation_tokens'],
                'latency_ms' => $latencyMs,
                'first_token_ms' => $result['firstTokenMs'],
                'duration_ms' => $result['durationMs'],
                'status_code' => $result['status'],
                'error_message' => $result['error'],
                'is_streaming' => true,
                'cost_multiplier' => $costMultiplier,
                'provider_type' => $providerType,
            ]);
        }
    }

    private function handleNonStreamingRequest(
        Response $response,
        Provider $provider,
        array $headers,
        string $url,
        array $body,
        ProxyConfig $config,
        string $appType,
        string $model,
        ?string $requestModel,
        float $startTime,
        string $costMultiplier,
        ?string $providerType,
    ): void {
        $client = new Client(array_merge([
            'timeout' => $config->non_streaming_timeout,
            'connect_timeout' => 10,
            'verify' => true,
            'http_errors' => false,
        ], $this->proxyOptions));

        $statusCode = 502;
        $responseBody = '';
        $error = null;

        try {
            $upstreamResponse = $client->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
            ]);

            $statusCode = $upstreamResponse->getStatusCode();
            $responseBody = (string) $upstreamResponse->getBody();
        } catch (GuzzleException $e) {
            $error = $e->getMessage();
            $statusCode = 502;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $statusCode = 502;
        }

        // Rectifier retry: on 400 errors from Anthropic-format requests, try rectifiers
        if ($statusCode === 400 && $error === null && $appType === 'claude') {
            $retryResult = $this->tryRectifyAndRetry($client, $url, $headers, $body, $responseBody);
            if ($retryResult !== null) {
                $statusCode = $retryResult['statusCode'];
                $responseBody = $retryResult['responseBody'];
                $error = $retryResult['error'];
            }
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Record circuit breaker result
        if ($error !== null || $statusCode >= 500) {
            $this->circuitBreaker->recordFailure($provider->id, $appType, $error ?? 'HTTP ' . $statusCode);
        } else {
            $this->circuitBreaker->recordSuccess($provider->id, $appType);
        }

        // Forward response to client
        $response->status($statusCode);
        $response->header('Content-Type', 'application/json');
        if ($error !== null) {
            $response->end(json_encode(['error' => ['message' => $error, 'type' => 'proxy_error']]));
        } else {
            $response->end($responseBody);
        }

        // Parse usage from response
        $decodedResponse = json_decode($responseBody, true) ?? [];
        $usage = $this->usageLogger->parseAnthropicUsage($decodedResponse);
        if ($usage['input_tokens'] === 0 && $usage['output_tokens'] === 0) {
            $usage = $this->usageLogger->parseOpenAIUsage($decodedResponse);
        }

        // Log usage
        if ($config->enable_logging) {
            $this->usageLogger->log([
                'provider_id' => $provider->id,
                'app_type' => $appType,
                'model' => $model,
                'request_model' => $requestModel ?? $model,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cache_read_tokens' => $usage['cache_read_tokens'],
                'cache_creation_tokens' => $usage['cache_creation_tokens'],
                'latency_ms' => $latencyMs,
                'status_code' => $statusCode,
                'error_message' => $error,
                'is_streaming' => false,
                'cost_multiplier' => $costMultiplier,
                'provider_type' => $providerType,
            ]);
        }
    }

    /**
     * Attempt to rectify a 400 error using signature/budget rectifiers and retry once.
     *
     * @return array{statusCode: int, responseBody: string, error: ?string}|null
     */
    private function tryRectifyAndRetry(Client $client, string $url, array $headers, array $body, string $errorResponseBody): ?array
    {
        $errorMessage = $errorResponseBody;

        // Check if signature rectifier is enabled
        $signatureEnabled = true;
        if ($this->settingsRepo !== null) {
            $signatureEnabled = ($this->settingsRepo->get('rectifier_signature_enabled') ?? '1') === '1';
        }

        // Try signature rectifier first
        if ($signatureEnabled) {
            $signatureRectifier = new ThinkingSignatureRectifier();
            if ($signatureRectifier->shouldRectify($errorMessage)) {
                $rectifyResult = $signatureRectifier->rectify($body);
                if ($rectifyResult['applied']) {
                    return $this->retryRequest($client, $url, $headers, $body);
                }
            }
        }

        // Check if budget rectifier is enabled
        $budgetEnabled = true;
        if ($this->settingsRepo !== null) {
            $budgetEnabled = ($this->settingsRepo->get('rectifier_budget_enabled') ?? '1') === '1';
        }

        // Try budget rectifier
        if ($budgetEnabled) {
            $budgetRectifier = new ThinkingBudgetRectifier();
            if ($budgetRectifier->shouldRectify($errorMessage)) {
                $rectifyResult = $budgetRectifier->rectify($body);
                if ($rectifyResult['applied']) {
                    return $this->retryRequest($client, $url, $headers, $body);
                }
            }
        }

        return null;
    }

    /**
     * Retry a request after rectification.
     *
     * @return array{statusCode: int, responseBody: string, error: ?string}
     */
    private function retryRequest(Client $client, string $url, array $headers, array $body): array
    {
        try {
            $retryResponse = $client->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
            ]);

            return [
                'statusCode' => $retryResponse->getStatusCode(),
                'responseBody' => (string) $retryResponse->getBody(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'statusCode' => 502,
                'responseBody' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detect the request format from the URL path and body content.
     */
    private function detectRequestFormat(string $path, array $body): string
    {
        if (str_contains($path, '/v1/messages') || str_contains($path, '/claude/')) {
            return 'anthropic';
        }
        if (str_contains($path, '/chat/completions') || str_contains($path, '/v1/responses')) {
            return 'openai';
        }
        return $this->formatConverter->detectFormat($body);
    }

    /**
     * Infer the provider's expected API format from app type.
     */
    private function inferProviderFormat(string $appType): string
    {
        return match ($appType) {
            'claude' => 'anthropic',
            'codex' => 'openai',
            default => 'openai',
        };
    }

    /**
     * Build the upstream URL for the provider.
     */
    private function buildUpstreamUrl(Provider $provider, array $providerConfig, string $requestPath): string
    {
        $meta = $provider->decodeMeta();

        // Check custom endpoints first
        if (!empty($meta->customEndpoints)) {
            $endpoint = $meta->customEndpoints[0]['url'] ?? null;
            if ($endpoint !== null) {
                return rtrim($endpoint, '/') . $requestPath;
            }
        }

        // Use base URL from env
        $env = $providerConfig['env'] ?? [];
        $baseUrl = $env['ANTHROPIC_BASE_URL']
            ?? $env['OPENAI_BASE_URL']
            ?? $env['API_BASE_URL']
            ?? null;

        if ($baseUrl !== null) {
            return rtrim($baseUrl, '/') . $requestPath;
        }

        // Default API endpoints
        $providerType = $meta->providerType ?? $provider->app_type;
        return match ($providerType) {
            'claude', 'claude_auth' => 'https://api.anthropic.com' . $requestPath,
            'codex' => 'https://api.openai.com' . $requestPath,
            'openrouter' => 'https://openrouter.ai/api' . $requestPath,
            default => 'https://api.anthropic.com' . $requestPath,
        };
    }

    /**
     * Build headers for the upstream request.
     */
    private function buildUpstreamHeaders(Provider $provider, array $providerConfig, array $requestHeaders): array
    {
        $env = $providerConfig['env'] ?? [];
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // API key
        $apiKey = $env['ANTHROPIC_API_KEY'] ?? $env['OPENAI_API_KEY'] ?? $env['API_KEY'] ?? null;
        if ($apiKey !== null) {
            $headers['x-api-key'] = $apiKey;
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        // Anthropic version header
        $anthropicVersion = $env['ANTHROPIC_API_VERSION'] ?? '2023-06-01';
        $headers['anthropic-version'] = $anthropicVersion;

        // Forward select headers from the original request
        $forwardHeaders = ['anthropic-beta', 'anthropic-dangerous-direct-browser-access'];
        foreach ($forwardHeaders as $header) {
            $lowerHeader = strtolower($header);
            if (isset($requestHeaders[$lowerHeader])) {
                $headers[$header] = $requestHeaders[$lowerHeader];
            }
        }

        return $headers;
    }

    private function loadConfig(string $appType): ?ProxyConfig
    {
        $row = $this->configRepo->get($appType);
        if ($row === null) {
            return null;
        }
        return ProxyConfig::fromRow($row);
    }

    private function respondJson(Response $response, int $status, array $data): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
