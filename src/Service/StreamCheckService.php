<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\StreamCheckRepository;
use CcSwitch\Model\Provider;
use CcSwitch\Model\StreamCheckConfig;
use CcSwitch\Model\StreamCheckResult;
use GuzzleHttp\Client;

class StreamCheckService
{
    public function __construct(
        private readonly StreamCheckRepository $repo,
        private readonly ProviderRepository $providerRepo,
    ) {
    }

    public function checkProvider(
        string $appType,
        Provider $provider,
        ?StreamCheckConfig $config = null,
    ): StreamCheckResult {
        $config ??= $this->repo->getConfig();

        $result = $this->checkWithRetry($appType, $provider, $config);

        $this->repo->saveLog($provider->id, $provider->name, $appType, $result);

        return $result;
    }

    /**
     * @return array<string, StreamCheckResult>
     */
    public function checkAllProviders(string $appType, bool $proxyTargetsOnly = false): array
    {
        if ($proxyTargetsOnly) {
            $rows = $this->providerRepo->getByFailoverQueue($appType);
        } else {
            $rows = $this->providerRepo->list($appType);
        }

        $config = $this->repo->getConfig();
        $results = [];

        foreach ($rows as $row) {
            $provider = Provider::fromRow($row);
            $results[$provider->id] = $this->checkProvider($appType, $provider, $config);
        }

        return $results;
    }

    private function checkWithRetry(
        string $appType,
        Provider $provider,
        StreamCheckConfig $config,
    ): StreamCheckResult {
        $lastResult = null;

        for ($attempt = 0; $attempt <= $config->max_retries; $attempt++) {
            $result = $this->checkOnce($appType, $provider, $config);

            if ($result->success) {
                $result->retry_count = $attempt;
                return $result;
            }

            if ($this->shouldRetry($result->message) && $attempt < $config->max_retries) {
                $lastResult = $result;
                continue;
            }

            $result->retry_count = $attempt;
            return $result;
        }

        if ($lastResult !== null) {
            $lastResult->retry_count = $config->max_retries;
            return $lastResult;
        }

        $result = new StreamCheckResult();
        $result->status = 'failed';
        $result->success = false;
        $result->message = 'Check failed';
        $result->tested_at = time();
        $result->retry_count = $config->max_retries;
        return $result;
    }

    private function checkOnce(
        string $appType,
        Provider $provider,
        StreamCheckConfig $config,
    ): StreamCheckResult {
        $startTime = microtime(true);

        try {
            [$httpStatus, $model] = match ($appType) {
                'claude' => $this->checkClaudeStream($provider, $config),
                'codex' => $this->checkCodexStream($provider, $config),
                'gemini' => $this->checkGeminiStream($provider, $config),
                'opencode' => $this->checkOpenCodeStream($provider, $config),
                'openclaw' => $this->checkOpenClawStream($provider, $config),
                default => throw new \RuntimeException("Unsupported app type: {$appType}"),
            };
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $result = new StreamCheckResult();
            $result->status = 'failed';
            $result->success = false;
            $result->message = $e->getMessage();
            $result->response_time_ms = $responseTimeMs;
            $result->tested_at = time();
            return $result;
        }

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
        $status = $responseTimeMs <= $config->degraded_threshold_ms ? 'operational' : 'degraded';

        $result = new StreamCheckResult();
        $result->status = $status;
        $result->success = true;
        $result->message = 'Check succeeded';
        $result->response_time_ms = $responseTimeMs;
        $result->http_status = $httpStatus;
        $result->model_used = $model;
        $result->tested_at = time();
        return $result;
    }

    /**
     * @return array{0: int, 1: string} [httpStatus, model]
     */
    private function checkClaudeStream(Provider $provider, StreamCheckConfig $config): array
    {
        $providerConfig = json_decode($provider->settings_config, true) ?: [];
        $env = $providerConfig['env'] ?? [];

        $baseUrl = $env['ANTHROPIC_BASE_URL'] ?? $env['API_BASE_URL'] ?? 'https://api.anthropic.com';
        $baseUrl = rtrim($baseUrl, '/');
        $apiKey = $env['ANTHROPIC_API_KEY'] ?? $env['API_KEY'] ?? '';

        $url = str_ends_with($baseUrl, '/v1')
            ? "{$baseUrl}/messages?beta=true"
            : "{$baseUrl}/v1/messages?beta=true";

        $model = $env['ANTHROPIC_MODEL'] ?? $config->claude_model;

        $body = [
            'model' => $model,
            'max_tokens' => 1,
            'messages' => [['role' => 'user', 'content' => $config->test_prompt]],
            'stream' => true,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'claude-code-20250219,interleaved-thinking-2025-05-14',
            'x-api-key' => $apiKey,
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        return $this->doStreamCheck($url, $headers, $body, $config->timeout_secs, $model);
    }

    /**
     * @return array{0: int, 1: string} [httpStatus, model]
     */
    private function checkCodexStream(Provider $provider, StreamCheckConfig $config): array
    {
        $providerConfig = json_decode($provider->settings_config, true) ?: [];
        $env = $providerConfig['env'] ?? [];

        $baseUrl = $env['OPENAI_BASE_URL'] ?? $env['API_BASE_URL'] ?? 'https://api.openai.com/v1';
        $baseUrl = rtrim($baseUrl, '/');
        $apiKey = $env['OPENAI_API_KEY'] ?? $env['API_KEY'] ?? '';

        $model = $config->codex_model;

        // Parse model@effort format
        [$actualModel, $reasoningEffort] = $this->parseModelWithEffort($model);

        $urls = str_ends_with($baseUrl, '/v1')
            ? ["{$baseUrl}/responses"]
            : ["{$baseUrl}/responses", "{$baseUrl}/v1/responses"];

        $body = [
            'model' => $actualModel,
            'input' => [['role' => 'user', 'content' => $config->test_prompt]],
            'stream' => true,
        ];

        if ($reasoningEffort !== null) {
            $body['reasoning'] = ['effort' => $reasoningEffort];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $lastError = null;
        foreach ($urls as $i => $url) {
            try {
                return $this->doStreamCheck($url, $headers, $body, $config->timeout_secs, $actualModel);
            } catch (\Throwable $e) {
                if ($i === 0 && count($urls) > 1 && str_contains($e->getMessage(), 'HTTP 404')) {
                    $lastError = $e;
                    continue;
                }
                throw $e;
            }
        }

        throw $lastError ?? new \RuntimeException('No valid Codex responses endpoint found');
    }

    /**
     * @return array{0: int, 1: string} [httpStatus, model]
     */
    private function checkGeminiStream(Provider $provider, StreamCheckConfig $config): array
    {
        $providerConfig = json_decode($provider->settings_config, true) ?: [];
        $env = $providerConfig['env'] ?? [];

        $baseUrl = $env['API_BASE_URL'] ?? 'https://generativelanguage.googleapis.com';
        $baseUrl = rtrim($baseUrl, '/');
        $apiKey = $env['GEMINI_API_KEY'] ?? $env['API_KEY'] ?? '';

        $model = $env['GEMINI_MODEL'] ?? $config->gemini_model;

        $url = (str_contains($baseUrl, '/v1beta') || str_contains($baseUrl, '/v1/'))
            ? "{$baseUrl}/models/{$model}:streamGenerateContent?alt=sse"
            : "{$baseUrl}/v1beta/models/{$model}:streamGenerateContent?alt=sse";

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $config->test_prompt]]],
            ],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
            'x-goog-api-key' => $apiKey,
        ];

        return $this->doStreamCheck($url, $headers, $body, $config->timeout_secs, $model);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function checkOpenCodeStream(Provider $provider, StreamCheckConfig $config): array
    {
        // OpenCode uses OpenAI-compatible format, delegate to codex check
        return $this->checkCodexStream($provider, $config);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function checkOpenClawStream(Provider $provider, StreamCheckConfig $config): array
    {
        // OpenClaw can use either Claude or OpenAI format depending on provider
        $meta = $provider->decodeMeta();
        $apiFormat = $meta->apiFormat ?? 'anthropic';

        if ($apiFormat === 'anthropic') {
            return $this->checkClaudeStream($provider, $config);
        }

        return $this->checkCodexStream($provider, $config);
    }

    /**
     * @return array{0: int, 1: string} [httpStatus, model]
     */
    private function doStreamCheck(
        string $url,
        array $headers,
        array $body,
        int $timeoutSecs,
        string $model,
    ): array {
        $client = new Client([
            'timeout' => $timeoutSecs,
            'connect_timeout' => 10,
            'verify' => false,
            'http_errors' => false,
        ]);

        $response = $client->request('POST', $url, [
            'headers' => $headers,
            'json' => $body,
            'stream' => true,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $errorBody = (string) $response->getBody();
            throw new \RuntimeException("HTTP {$statusCode}: {$errorBody}");
        }

        // Read first chunk only
        $stream = $response->getBody();
        $chunk = $stream->read(8192);

        if ($chunk === '') {
            throw new \RuntimeException('No response data received');
        }

        return [$statusCode, $model];
    }

    private function shouldRetry(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'timeout')
            || str_contains($lower, 'abort')
            || str_contains($lower, 'timed out');
    }

    /**
     * @return array{0: string, 1: ?string} [actualModel, reasoningEffort]
     */
    private function parseModelWithEffort(string $model): array
    {
        $pos = strpos($model, '@');
        if ($pos === false) {
            $pos = strpos($model, '#');
        }

        if ($pos !== false) {
            $actualModel = substr($model, 0, $pos);
            $effort = substr($model, $pos + 1);
            if ($effort !== '') {
                return [$actualModel, $effort];
            }
        }

        return [$model, null];
    }
}
