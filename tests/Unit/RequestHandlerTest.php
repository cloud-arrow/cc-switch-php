<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ModelPricingRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Database\Repository\RequestLogRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Model\Provider;
use CcSwitch\Proxy\CircuitBreaker;
use CcSwitch\Proxy\FailoverManager;
use CcSwitch\Proxy\FormatConverter;
use CcSwitch\Proxy\ModelMapper;
use CcSwitch\Proxy\RequestHandler;
use CcSwitch\Proxy\StreamHandler;
use CcSwitch\Proxy\UsageLogger;
use PHPUnit\Framework\TestCase;

class RequestHandlerTest extends TestCase
{
    private RequestHandler $handler;

    protected function setUp(): void
    {
        $failoverManager = $this->createMock(FailoverManager::class);
        $healthRepo = $this->createMock(HealthRepository::class);
        $configRepo = $this->createMock(ProxyConfigRepository::class);
        $configRepo->method('get')->willReturn(null);
        $circuitBreaker = new CircuitBreaker($healthRepo, $configRepo);
        $modelMapper = new ModelMapper();
        $formatConverter = new FormatConverter();
        $streamHandler = new StreamHandler();
        $logRepo = $this->createMock(RequestLogRepository::class);
        $usageLogger = new UsageLogger($logRepo);
        $proxyConfigRepo = $this->createMock(ProxyConfigRepository::class);

        $this->handler = new RequestHandler(
            $failoverManager,
            $circuitBreaker,
            $modelMapper,
            $formatConverter,
            $streamHandler,
            $usageLogger,
            $proxyConfigRepo,
        );
    }

    // ========================================================================
    // detectAppType tests
    // ========================================================================

    public function testDetectAppTypeClaude(): void
    {
        $this->assertSame('claude', $this->handler->detectAppType('/v1/messages', []));
    }

    public function testDetectAppTypeClaudeProxy(): void
    {
        $this->assertSame('claude', $this->handler->detectAppType('/claude/foo', []));
    }

    public function testDetectAppTypeCodexChatCompletions(): void
    {
        $this->assertSame('codex', $this->handler->detectAppType('/v1/chat/completions', []));
    }

    public function testDetectAppTypeCodexChatCompletionsNoV1(): void
    {
        $this->assertSame('codex', $this->handler->detectAppType('/chat/completions', []));
    }

    public function testDetectAppTypeCodexResponses(): void
    {
        $this->assertSame('codex', $this->handler->detectAppType('/v1/responses', []));
    }

    public function testDetectAppTypeCodexProxy(): void
    {
        $this->assertSame('codex', $this->handler->detectAppType('/codex/foo', []));
    }

    public function testDetectAppTypeGeminiBeta(): void
    {
        $this->assertSame('gemini', $this->handler->detectAppType('/v1beta/something', []));
    }

    public function testDetectAppTypeGeminiProxy(): void
    {
        $this->assertSame('gemini', $this->handler->detectAppType('/gemini/foo', []));
    }

    public function testDetectAppTypeModelsWithAnthropicHeader(): void
    {
        $this->assertSame('claude', $this->handler->detectAppType('/v1/models', [
            'x-api-key' => 'anthropic-key',
        ]));
    }

    public function testDetectAppTypeModelsDefaultsToCodex(): void
    {
        $this->assertSame('codex', $this->handler->detectAppType('/v1/models', []));
    }

    public function testDetectAppTypeUnknownReturnsNull(): void
    {
        $this->assertNull($this->handler->detectAppType('/unknown/path', []));
    }

    public function testDetectAppTypeTrailingSlash(): void
    {
        $this->assertSame('claude', $this->handler->detectAppType('/v1/messages/', []));
    }

    // ========================================================================
    // Private method tests via reflection
    // ========================================================================

    public function testDetectRequestFormatAnthropic(): void
    {
        $method = new \ReflectionMethod($this->handler, 'detectRequestFormat');
        $method->setAccessible(true);

        $this->assertSame('anthropic', $method->invoke($this->handler, '/v1/messages', []));
        $this->assertSame('anthropic', $method->invoke($this->handler, '/claude/proxy', []));
    }

    public function testDetectRequestFormatOpenAI(): void
    {
        $method = new \ReflectionMethod($this->handler, 'detectRequestFormat');
        $method->setAccessible(true);

        $this->assertSame('openai', $method->invoke($this->handler, '/v1/chat/completions', []));
        $this->assertSame('openai', $method->invoke($this->handler, '/chat/completions', []));
        $this->assertSame('openai', $method->invoke($this->handler, '/v1/responses', []));
    }

    public function testInferProviderFormat(): void
    {
        $method = new \ReflectionMethod($this->handler, 'inferProviderFormat');
        $method->setAccessible(true);

        $this->assertSame('anthropic', $method->invoke($this->handler, 'claude'));
        $this->assertSame('openai', $method->invoke($this->handler, 'codex'));
        $this->assertSame('openai', $method->invoke($this->handler, 'gemini'));
    }

    public function testBuildUpstreamUrlWithBaseUrl(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamUrl');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'claude',
            'meta' => '{}',
        ]);

        $providerConfig = [
            'env' => ['ANTHROPIC_BASE_URL' => 'https://custom.api.com'],
        ];

        $result = $method->invoke($this->handler, $provider, $providerConfig, '/v1/messages');
        $this->assertSame('https://custom.api.com/v1/messages', $result);
    }

    public function testBuildUpstreamUrlWithCustomEndpoint(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamUrl');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'claude',
            'meta' => json_encode([
                'customEndpoints' => [['url' => 'https://custom-endpoint.com']],
            ]),
        ]);

        $result = $method->invoke($this->handler, $provider, [], '/v1/messages');
        $this->assertSame('https://custom-endpoint.com/v1/messages', $result);
    }

    public function testBuildUpstreamUrlDefaultClaude(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamUrl');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'claude',
            'meta' => '{}',
        ]);

        $result = $method->invoke($this->handler, $provider, [], '/v1/messages');
        $this->assertSame('https://api.anthropic.com/v1/messages', $result);
    }

    public function testBuildUpstreamUrlDefaultCodex(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamUrl');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'codex',
            'meta' => json_encode(['providerType' => 'codex']),
        ]);

        $result = $method->invoke($this->handler, $provider, [], '/v1/chat/completions');
        $this->assertSame('https://api.openai.com/v1/chat/completions', $result);
    }

    public function testBuildUpstreamUrlOpenRouter(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamUrl');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'claude',
            'meta' => json_encode(['providerType' => 'openrouter']),
        ]);

        $result = $method->invoke($this->handler, $provider, [], '/v1/messages');
        $this->assertSame('https://openrouter.ai/api/v1/messages', $result);
    }

    public function testBuildUpstreamHeaders(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamHeaders');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'claude',
            'meta' => '{}',
        ]);

        $providerConfig = [
            'env' => [
                'ANTHROPIC_API_KEY' => 'sk-test-key',
                'ANTHROPIC_API_VERSION' => '2024-01-01',
            ],
        ];

        $requestHeaders = [
            'anthropic-beta' => 'messages-2024-12-19',
        ];

        $result = $method->invoke($this->handler, $provider, $providerConfig, $requestHeaders);

        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('sk-test-key', $result['x-api-key']);
        $this->assertSame('Bearer sk-test-key', $result['Authorization']);
        $this->assertSame('2024-01-01', $result['anthropic-version']);
        $this->assertSame('messages-2024-12-19', $result['anthropic-beta']);
    }

    public function testBuildUpstreamHeadersDefaultVersion(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamHeaders');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'claude',
            'meta' => '{}',
        ]);

        $result = $method->invoke($this->handler, $provider, [], []);

        $this->assertSame('2023-06-01', $result['anthropic-version']);
        $this->assertArrayNotHasKey('x-api-key', $result);
    }

    public function testBuildUpstreamHeadersOpenAIKey(): void
    {
        $method = new \ReflectionMethod($this->handler, 'buildUpstreamHeaders');
        $method->setAccessible(true);

        $provider = Provider::fromRow([
            'id' => 'test',
            'app_type' => 'codex',
            'meta' => '{}',
        ]);

        $providerConfig = [
            'env' => ['OPENAI_API_KEY' => 'sk-openai-key'],
        ];

        $result = $method->invoke($this->handler, $provider, $providerConfig, []);

        $this->assertSame('sk-openai-key', $result['x-api-key']);
        $this->assertSame('Bearer sk-openai-key', $result['Authorization']);
    }

    // ========================================================================
    // handle() tests with mock Swoole objects
    // ========================================================================

    private function createSwooleRequest(string $method, string $path, ?string $body = null, array $headers = []): \Swoole\Http\Request
    {
        $request = $this->createMock(\Swoole\Http\Request::class);
        $request->server = [
            'request_method' => $method,
            'request_uri' => $path,
        ];
        $request->header = $headers;
        $request->method('rawContent')->willReturn($body ?? false);
        return $request;
    }

    private array $responseCapture = [];

    private function createSwooleResponse(): \Swoole\Http\Response
    {
        $this->responseCapture = ['statusCode' => 200, 'headers' => [], 'body' => ''];
        $capture = &$this->responseCapture;

        $response = $this->createMock(\Swoole\Http\Response::class);
        $response->method('status')->willReturnCallback(function (int $code) use (&$capture) {
            $capture['statusCode'] = $code;
            return true;
        });
        $response->method('header')->willReturnCallback(function (string $k, string $v) use (&$capture) {
            $capture['headers'][$k] = $v;
            return true;
        });
        $response->method('end')->willReturnCallback(function (string $data = '') use (&$capture) {
            $capture['body'] = $data;
            return true;
        });
        $response->method('write')->willReturnCallback(function (string $data) use (&$capture) {
            $capture['body'] .= $data;
            return true;
        });

        return $response;
    }

    public function testHandleHealthEndpoint(): void
    {
        $request = $this->createSwooleRequest('GET', '/health');
        $response = $this->createSwooleResponse();

        $this->handler->handle($request, $response);

        $this->assertSame(200, $this->responseCapture['statusCode']);
        $body = json_decode($this->responseCapture['body'], true);
        $this->assertSame('healthy', $body['status']);
        $this->assertArrayHasKey('timestamp', $body);
    }

    public function testHandleStatusEndpoint(): void
    {
        $request = $this->createSwooleRequest('GET', '/status');
        $response = $this->createSwooleResponse();

        $this->handler->handle($request, $response);

        $this->assertSame(200, $this->responseCapture['statusCode']);
        $body = json_decode($this->responseCapture['body'], true);
        $this->assertSame('running', $body['status']);
    }

    public function testHandleMethodNotAllowed(): void
    {
        $request = $this->createSwooleRequest('GET', '/v1/messages');
        $response = $this->createSwooleResponse();

        $this->handler->handle($request, $response);

        $this->assertSame(405, $this->responseCapture['statusCode']);
        $body = json_decode($this->responseCapture['body'], true);
        $this->assertSame('Method not allowed', $body['error']);
    }

    public function testHandleUnknownPath(): void
    {
        $request = $this->createSwooleRequest('POST', '/unknown/path', '{}');
        $response = $this->createSwooleResponse();

        $this->handler->handle($request, $response);

        $this->assertSame(404, $this->responseCapture['statusCode']);
        $body = json_decode($this->responseCapture['body'], true);
        $this->assertSame('Unknown API endpoint', $body['error']);
    }

    public function testHandleInvalidJsonBody(): void
    {
        $request = $this->createSwooleRequest('POST', '/v1/messages', 'not json');
        $response = $this->createSwooleResponse();

        $this->handler->handle($request, $response);

        $this->assertSame(400, $this->responseCapture['statusCode']);
        $body = json_decode($this->responseCapture['body'], true);
        $this->assertSame('Invalid JSON body', $body['error']);
    }

    public function testSetProxyOptions(): void
    {
        // Should not throw
        $this->handler->setProxyOptions(['proxy' => 'http://localhost:8080']);
        $this->assertTrue(true);
    }
}
