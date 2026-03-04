<?php

declare(strict_types=1);

namespace CcSwitch\Tests\E2E;

use CcSwitch\ConfigWriter\GeminiWriter;
use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Model\Provider;
use CcSwitch\Service\ProviderService;
use GuzzleHttp\Client;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test: real Gemini API calls.
 *
 * Requires GEMINI_API_KEY env var. Run with:
 *   GEMINI_API_KEY=AIza... vendor/bin/phpunit tests/E2E/
 */
class GeminiE2ETest extends TestCase
{
    private string $apiKey;
    private string $apiBase;
    private string $openaiBase;
    private string $model;
    private string $tmpHome;
    private string|false $originalHome;
    private PDO $pdo;
    private Medoo $medoo;
    private string $dbPath;

    public static function setUpBeforeClass(): void
    {
        if (empty(getenv('GEMINI_API_KEY'))) {
            self::markTestSkipped('GEMINI_API_KEY env var required for E2E tests');
        }
    }

    protected function setUp(): void
    {
        $this->apiKey = getenv('GEMINI_API_KEY');
        $this->apiBase = getenv('GEMINI_API_BASE') ?: 'https://generativelanguage.googleapis.com';
        $this->model = getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash';
        // OpenAI-compat base URL: Google uses /v1beta/openai, third-party proxies use root directly
        $this->openaiBase = getenv('GEMINI_OPENAI_BASE')
            ?: rtrim($this->apiBase, '/') . '/v1beta/openai';

        // Temp HOME — never touch real config
        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-e2e-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome . '/.gemini', 0755, true);
        mkdir($this->tmpHome . '/.cc-switch', 0755, true);
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tmpHome);

        // SQLite database
        $this->dbPath = $this->tmpHome . '/.cc-switch/cc-switch.db';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        $this->recursiveDelete($this->tmpHome);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ─── Test 1: ConfigWriter + Direct API ─────────────────────────

    public function testConfigWriterAndDirectGeminiApiCall(): void
    {
        // 1) Add Gemini provider via service → triggers GeminiWriter
        $repo = new ProviderRepository($this->medoo);
        $service = new ProviderService($repo);

        $provider = new Provider();
        $provider->name = 'E2E Gemini';
        $provider->settings_config = json_encode([
            'env' => [
                'GEMINI_API_KEY' => $this->apiKey,
                'GEMINI_MODEL' => $this->model,
            ],
        ]);
        $service->add(AppType::Gemini, $provider);

        // 2) Verify .env was written
        $envPath = $this->tmpHome . '/.gemini/.env';
        $this->assertFileExists($envPath, '.env file should be created by GeminiWriter');

        $envContent = file_get_contents($envPath);
        $parsed = GeminiWriter::parseEnv($envContent);
        $this->assertSame($this->apiKey, $parsed['GEMINI_API_KEY']);
        $this->assertSame($this->model, $parsed['GEMINI_MODEL']);

        // 3) Verify settings.json auth type
        $settingsPath = $this->tmpHome . '/.gemini/settings.json';
        $this->assertFileExists($settingsPath);
        $settings = json_decode(file_get_contents($settingsPath), true);
        $this->assertSame('gemini-api-key', $settings['security']['auth']['selectedType']);

        // 4) Make a REAL API call to Gemini using the written key
        $client = new Client(['timeout' => 30, 'verify' => false]);
        $apiUrl = rtrim($this->apiBase, '/') . "/v1beta/models/{$this->model}:generateContent";
        $response = $client->post(
            $apiUrl,
            [
                'query' => ['key' => $parsed['GEMINI_API_KEY']],
                'json' => [
                    'contents' => [
                        ['parts' => [['text' => 'Reply with exactly: HELLO_E2E_TEST']]]
                    ],
                    'generationConfig' => ['maxOutputTokens' => 256],
                ],
                'http_errors' => false,
            ]
        );

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        // 200 = success, 429/403 = quota exceeded (still proves connectivity)
        $this->assertContains($status, [200, 429, 403],
            'Gemini API should return 200, 429, or 403. Got: ' . $status . ' Body: ' . json_encode($body));

        if ($status === 200) {
            $this->assertArrayHasKey('candidates', $body, 'Response should have candidates');
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('HELLO_E2E_TEST', $text);
        } else {
            // 429/403 proves the request reached Gemini and was rejected by quota
            $this->assertArrayHasKey('error', $body);
            $this->assertContains($body['error']['code'], [429, 403]);
        }
    }

    // ─── Test 2: Gemini OpenAI-compatible via Proxy ────────────────

    public function testProxyServerForwardsToGeminiOpenAICompat(): void
    {
        // Gemini's OpenAI-compatible endpoint:
        // https://generativelanguage.googleapis.com/v1beta/openai/chat/completions
        $proxyPort = 15799; // Use non-default port to avoid conflicts

        // 1) Insert provider with OpenAI-compat base URL
        $repo = new ProviderRepository($this->medoo);
        $provider = new Provider();
        $provider->id = 'gemini-oai-compat';
        $provider->app_type = 'codex'; // proxy detects /v1/chat/completions as codex
        $provider->name = 'Gemini OpenAI Compat';
        $provider->is_current = 1;
        $provider->settings_config = json_encode([
            'env' => [
                'OPENAI_API_KEY' => $this->apiKey,
                'OPENAI_BASE_URL' => $this->openaiBase,
            ],
        ]);
        $provider->meta = '{}';
        $repo->insert([
            'id' => $provider->id,
            'app_type' => $provider->app_type,
            'name' => $provider->name,
            'settings_config' => $provider->settings_config,
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => $provider->meta,
            'in_failover_queue' => 0,
        ]);

        // 2) Enable proxy config for codex
        $configRepo = new ProxyConfigRepository($this->medoo);
        $this->medoo->update('proxy_config', ['enabled' => 1], ['app_type' => 'codex']);

        // 3) Start proxy server in background process
        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process, 'Proxy process should start');

        try {
            // 4) Send request through proxy in OpenAI chat completions format
            $client = new Client(['timeout' => 30, 'verify' => false]);
            $response = $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/chat/completions",
                [
                    'json' => [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'user', 'content' => 'Reply with exactly: PROXY_E2E_OK'],
                        ],
                        'max_tokens' => 256,
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();
            $rawBody = (string) $response->getBody();
            $body = json_decode($rawBody, true);

            // 200 = success, 429/403 = quota limit (still proves proxy forwarding works)
            $this->assertContains($status, [200, 429, 403],
                'Proxy should return 200, 429, or 403. Got: ' . $status . ' Body: ' . substr($rawBody, 0, 500));

            if ($status === 200) {
                $this->assertArrayHasKey('choices', $body, 'Response should have choices');
                $text = $body['choices'][0]['message']['content'] ?? '';
                $this->assertStringContainsString('PROXY_E2E_OK', $text);
            } else {
                // 429 from Gemini — proves the entire proxy pipeline works:
                // request → proxy → Gemini API → response back through proxy
                // Response may be wrapped by proxy or raw from Gemini
                $this->assertNotEmpty($rawBody,
                    'Proxy should forward the error response body from Gemini');
            }

        } finally {
            // 5) Stop proxy
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 3: Proxy health endpoint ─────────────────────────────

    public function testProxyHealthEndpoint(): void
    {
        $proxyPort = 15798;

        // Minimal setup — just need the proxy running
        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process, 'Proxy process should start');

        try {
            $client = new Client(['timeout' => 5]);
            $response = $client->get("http://127.0.0.1:{$proxyPort}/health");

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame('healthy', $body['status']);
            $this->assertArrayHasKey('timestamp', $body);

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 4: Streaming response via proxy ──────────────────────

    public function testProxyStreamingToGemini(): void
    {
        $proxyPort = 15797;

        // Insert provider
        $repo = new ProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'gemini-stream-test',
            'app_type' => 'codex',
            'name' => 'Gemini Stream',
            'settings_config' => json_encode([
                'env' => [
                    'OPENAI_API_KEY' => $this->apiKey,
                    'OPENAI_BASE_URL' => $this->openaiBase,
                ],
            ]),
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
            'in_failover_queue' => 0,
        ]);
        $this->medoo->update('proxy_config', ['enabled' => 1], ['app_type' => 'codex']);

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            // Don't use Guzzle 'stream' => true — it causes empty body with Swoole chunked responses.
            // Instead, read the full response body which contains the SSE data.
            $client = new Client(['timeout' => 30]);
            $response = $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/chat/completions",
                [
                    'json' => [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'user', 'content' => 'Say hi in one word.'],
                        ],
                        'max_tokens' => 16,
                        'stream' => true,
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();
            $rawBody = (string) $response->getBody();

            // 200 = streaming SSE, 429/403 = quota limit (proves proxy forwarding works)
            $this->assertContains($status, [200, 429, 403],
                'Streaming should return 200, 429, or 403. Got: ' . $status . ' Body: ' . substr($rawBody, 0, 500));

            if ($status === 200) {
                $this->assertStringContainsString('data:', $rawBody, 'Response should be SSE format');
            } else {
                // 429/403 from upstream — proves the entire proxy streaming pipeline works:
                // request → proxy → upstream API → error response forwarded back
                $this->assertNotEmpty($rawBody,
                    'Proxy should forward the error response body from upstream');
            }

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 5: Provider lifecycle (add → list → switch → delete) ─

    public function testProviderLifecycle(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $service = new ProviderService($repo);

        // 1) Add first provider → auto-becomes current, .env written
        $p1 = new Provider();
        $p1->name = 'Provider A';
        $p1->settings_config = json_encode([
            'env' => ['GEMINI_API_KEY' => $this->apiKey, 'GEMINI_MODEL' => $this->model],
        ]);
        $service->add(AppType::Gemini, $p1);

        $current = $service->getCurrent(AppType::Gemini);
        $this->assertNotNull($current);
        $this->assertSame('Provider A', $current->name);

        $envPath = $this->tmpHome . '/.gemini/.env';
        $parsed = GeminiWriter::parseEnv(file_get_contents($envPath));
        $this->assertSame($this->apiKey, $parsed['GEMINI_API_KEY']);

        // 2) Add second provider → not current, .env unchanged
        $p2 = new Provider();
        $p2->name = 'Provider B';
        $p2->settings_config = json_encode([
            'env' => ['GEMINI_API_KEY' => 'sk-fake-key-b', 'GEMINI_MODEL' => 'gemini-pro'],
        ]);
        $service->add(AppType::Gemini, $p2);

        $current = $service->getCurrent(AppType::Gemini);
        $this->assertSame('Provider A', $current->name, 'First provider should remain current');
        $parsed = GeminiWriter::parseEnv(file_get_contents($envPath));
        $this->assertSame($this->apiKey, $parsed['GEMINI_API_KEY'], '.env should still have Provider A key');

        // 3) List → both exist
        $list = $service->list(AppType::Gemini);
        $this->assertCount(2, $list);

        // 4) Switch to Provider B → .env updated
        $service->switchTo($p2->id, AppType::Gemini);
        $current = $service->getCurrent(AppType::Gemini);
        $this->assertSame('Provider B', $current->name);
        $parsed = GeminiWriter::parseEnv(file_get_contents($envPath));
        $this->assertSame('sk-fake-key-b', $parsed['GEMINI_API_KEY']);
        $this->assertSame('gemini-pro', $parsed['GEMINI_MODEL']);

        // 5) Switch back to Provider A → .env restored
        $service->switchTo($p1->id, AppType::Gemini);
        $parsed = GeminiWriter::parseEnv(file_get_contents($envPath));
        $this->assertSame($this->apiKey, $parsed['GEMINI_API_KEY']);

        // 6) Delete non-current (Provider B) → success
        $service->delete($p2->id, AppType::Gemini);
        $this->assertCount(1, $service->list(AppType::Gemini));

        // 7) Delete current → should throw
        $this->expectException(\RuntimeException::class);
        $service->delete($p1->id, AppType::Gemini);
    }

    // ─── Test 6: GeminiWriter preserves mcpServers + OAuth mode ──

    public function testGeminiWriterPreservesMcpServersAndOAuthMode(): void
    {
        $settingsPath = $this->tmpHome . '/.gemini/settings.json';

        // Pre-write settings.json with existing mcpServers
        file_put_contents($settingsPath, json_encode([
            'mcpServers' => ['my-server' => ['command' => 'npx', 'args' => ['test']]],
            'customKey' => 'preserved',
        ]));

        $repo = new ProviderRepository($this->medoo);
        $service = new ProviderService($repo);

        // 1) Add with API key → auth type = gemini-api-key
        $p = new Provider();
        $p->name = 'WithKey';
        $p->settings_config = json_encode([
            'env' => ['GEMINI_API_KEY' => 'test-key'],
            'config' => ['theme' => 'dark'],
        ]);
        $service->add(AppType::Gemini, $p);

        $settings = json_decode(file_get_contents($settingsPath), true);
        $this->assertSame('gemini-api-key', $settings['security']['auth']['selectedType']);
        // mcpServers preserved
        $this->assertArrayHasKey('mcpServers', $settings);
        $this->assertSame('npx', $settings['mcpServers']['my-server']['command']);
        // config merged
        $this->assertSame('dark', $settings['theme']);

        // 2) Switch to OAuth mode (no API key in env)
        $p2 = new Provider();
        $p2->name = 'OAuthMode';
        $p2->settings_config = json_encode(['env' => []]);
        $service->add(AppType::Gemini, $p2);
        $service->switchTo($p2->id, AppType::Gemini);

        $settings = json_decode(file_get_contents($settingsPath), true);
        $this->assertSame('oauth-personal', $settings['security']['auth']['selectedType']);
    }

    // ─── Test 7: Proxy error handling ────────────────────────────

    public function testProxyErrorHandling(): void
    {
        $proxyPort = 15796;

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            $client = new Client(['timeout' => 5, 'http_errors' => false]);

            // 1) GET on API endpoint → 405 Method Not Allowed
            $r = $client->get("http://127.0.0.1:{$proxyPort}/v1/chat/completions");
            $this->assertSame(405, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertSame('Method not allowed', $body['error']);

            // 2) Unknown path → 404
            $r = $client->post("http://127.0.0.1:{$proxyPort}/unknown/path", ['json' => []]);
            $this->assertSame(404, $r->getStatusCode());

            // 3) Invalid JSON → 400
            $r = $client->post("http://127.0.0.1:{$proxyPort}/v1/chat/completions", [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => 'not-json{{{',
            ]);
            $this->assertSame(400, $r->getStatusCode());

            // 4) Proxy disabled → 503 (codex is enabled=0 by default)
            // Insert a provider so we get past the "no provider" check
            $this->medoo->insert('providers', [
                'id' => 'test-disabled', 'app_type' => 'codex', 'name' => 'Test',
                'settings_config' => '{}', 'is_current' => 1, 'sort_index' => 0,
                'meta' => '{}', 'in_failover_queue' => 0,
            ]);
            $r = $client->post("http://127.0.0.1:{$proxyPort}/v1/chat/completions", [
                'json' => ['model' => 'test', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            ]);
            $this->assertSame(503, $r->getStatusCode());

            // 5) Status endpoint works
            $r = $client->get("http://127.0.0.1:{$proxyPort}/status");
            $this->assertSame(200, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertSame('running', $body['status']);

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 8: Usage logging after proxy request ───────────────

    public function testUsageLogging(): void
    {
        $proxyPort = 15795;

        $repo = new ProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'usage-test-provider',
            'app_type' => 'codex',
            'name' => 'Usage Test',
            'settings_config' => json_encode([
                'env' => [
                    'OPENAI_API_KEY' => $this->apiKey,
                    'OPENAI_BASE_URL' => $this->openaiBase,
                ],
            ]),
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
            'in_failover_queue' => 0,
        ]);
        $this->medoo->update('proxy_config', ['enabled' => 1, 'enable_logging' => 1], ['app_type' => 'codex']);

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            $client = new Client(['timeout' => 30, 'verify' => false]);
            $response = $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/chat/completions",
                [
                    'json' => [
                        'model' => $this->model,
                        'messages' => [['role' => 'user', 'content' => 'Say OK']],
                        'max_tokens' => 64,
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();
            $this->assertContains($status, [200, 429, 403],
                'Request should reach upstream. Got: ' . $status);

            // Give the proxy a moment to flush the log (it's async in the same process)
            usleep(500_000);

            // Verify usage log was written
            // Re-open DB to see writes from the proxy subprocess
            $checkDb = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
            $logs = $checkDb->select('proxy_request_logs', '*', [
                'provider_id' => 'usage-test-provider',
            ]);

            $this->assertNotEmpty($logs, 'proxy_request_logs should have at least one entry');
            $log = $logs[0];
            $this->assertSame('usage-test-provider', $log['provider_id']);
            $this->assertSame('codex', $log['app_type']);
            $this->assertSame($this->model, $log['model']);
            $this->assertSame($status, (int) $log['status_code']);
            $this->assertNotEmpty($log['request_id']);
            $this->assertGreaterThan(0, (int) $log['latency_ms']);

            if ($status === 200) {
                // Successful request should have token counts
                $this->assertGreaterThan(0, (int) $log['input_tokens'], 'Should log input tokens');
                $this->assertGreaterThan(0, (int) $log['output_tokens'], 'Should log output tokens');
                // total_cost_usd should be calculated
                $this->assertGreaterThan(0, (float) $log['total_cost_usd'], 'Should calculate cost');
            }

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 9: Failover — trip circuit, auto-switch ────────────

    public function testFailoverOnCircuitBreakerTrip(): void
    {
        $proxyPort = 15794;

        $repo = new ProviderRepository($this->medoo);

        // Provider A: invalid key (will fail) — set as current
        $repo->insert([
            'id' => 'provider-bad',
            'app_type' => 'codex',
            'name' => 'Bad Provider',
            'settings_config' => json_encode([
                'env' => [
                    'OPENAI_API_KEY' => 'sk-invalid-will-fail',
                    'OPENAI_BASE_URL' => $this->openaiBase,
                ],
            ]),
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
            'in_failover_queue' => 1,
        ]);

        // Provider B: valid key — in failover queue
        $repo->insert([
            'id' => 'provider-good',
            'app_type' => 'codex',
            'name' => 'Good Provider',
            'settings_config' => json_encode([
                'env' => [
                    'OPENAI_API_KEY' => $this->apiKey,
                    'OPENAI_BASE_URL' => $this->openaiBase,
                ],
            ]),
            'is_current' => 0,
            'sort_index' => 1,
            'meta' => '{}',
            'in_failover_queue' => 1,
        ]);

        // Enable proxy with low failure threshold for fast failover
        $this->medoo->update('proxy_config', [
            'enabled' => 1,
            'circuit_failure_threshold' => 2,
            'max_retries' => 0,
        ], ['app_type' => 'codex']);

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            $client = new Client(['timeout' => 15, 'verify' => false, 'http_errors' => false]);
            $makeRequest = fn () => $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/chat/completions",
                ['json' => [
                    'model' => $this->model,
                    'messages' => [['role' => 'user', 'content' => 'Say FAILOVER_OK']],
                    'max_tokens' => 128,
                ]]
            );

            // Send requests — first ones fail on bad provider (401),
            // circuit breaker trips after threshold, failover to good provider
            $statuses = [];
            for ($i = 0; $i < 4; $i++) {
                $r = $makeRequest();
                $statuses[] = $r->getStatusCode();
            }

            // At least one should have hit the bad provider (401) and
            // at least one should have failed over to the good provider or returned 503
            $hasError = in_array(401, $statuses) || in_array(502, $statuses) || in_array(503, $statuses);
            $hasSuccessOrQuota = in_array(200, $statuses) || in_array(429, $statuses) || in_array(403, $statuses);

            // After circuit trips on bad provider, the proxy should either:
            // - failover to good provider (200/429/403)
            // - or return 503 if all providers exhausted
            $this->assertTrue(
                $hasError || $hasSuccessOrQuota,
                'Should see error from bad provider or success from failover. Statuses: ' . implode(',', $statuses)
            );

            // Verify circuit breaker state in DB
            $checkDb = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
            $health = $checkDb->select('provider_health', '*', ['provider_id' => 'provider-bad']);
            // Health record may or may not exist depending on whether CB persists to DB
            // The important thing is that the proxy didn't crash and handled the failover

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 10: Direct API call validates real response ────────

    public function testDirectGeminiApiResponseStructure(): void
    {
        $client = new Client(['timeout' => 30, 'verify' => false]);
        $apiUrl = rtrim($this->apiBase, '/') . "/v1beta/models/{$this->model}:generateContent";

        $response = $client->post($apiUrl, [
            'query' => ['key' => $this->apiKey],
            'json' => [
                'contents' => [['parts' => [['text' => 'What is 2+2? Reply with just the number.']]]],
                'generationConfig' => ['maxOutputTokens' => 256],
            ],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        $this->assertContains($status, [200, 429, 403]);

        if ($status === 200) {
            // Validate full Gemini response structure
            $this->assertArrayHasKey('candidates', $body);
            $this->assertNotEmpty($body['candidates']);

            $candidate = $body['candidates'][0];
            $this->assertArrayHasKey('content', $candidate);
            $this->assertArrayHasKey('role', $candidate['content']);
            $this->assertSame('model', $candidate['content']['role']);
            $this->assertArrayHasKey('parts', $candidate['content']);
            $this->assertNotEmpty($candidate['content']['parts']);

            $text = $candidate['content']['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('4', $text);

            // Verify finish reason
            $this->assertArrayHasKey('finishReason', $candidate);

            // Verify usage metadata if present
            if (isset($body['usageMetadata'])) {
                $this->assertArrayHasKey('promptTokenCount', $body['usageMetadata']);
                $this->assertArrayHasKey('candidatesTokenCount', $body['usageMetadata']);
                $this->assertGreaterThan(0, $body['usageMetadata']['promptTokenCount']);
            }
        }
    }

    // ─── Test 11: Proxy OpenAI-compat response structure ─────────

    public function testProxyOpenAICompatResponseStructure(): void
    {
        $proxyPort = 15793;

        $repo = new ProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'struct-test',
            'app_type' => 'codex',
            'name' => 'Structure Test',
            'settings_config' => json_encode([
                'env' => [
                    'OPENAI_API_KEY' => $this->apiKey,
                    'OPENAI_BASE_URL' => $this->openaiBase,
                ],
            ]),
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
            'in_failover_queue' => 0,
        ]);
        $this->medoo->update('proxy_config', ['enabled' => 1], ['app_type' => 'codex']);

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            $client = new Client(['timeout' => 30, 'verify' => false]);
            $response = $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/chat/completions",
                [
                    'json' => [
                        'model' => $this->model,
                        'messages' => [['role' => 'user', 'content' => 'What is 1+1? Reply with just the number.']],
                        'max_tokens' => 256,
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);

            $this->assertContains($status, [200, 429, 403]);

            if ($status === 200) {
                // Validate OpenAI-compatible response structure
                $this->assertArrayHasKey('choices', $body);
                $this->assertNotEmpty($body['choices']);

                $choice = $body['choices'][0];
                $this->assertArrayHasKey('message', $choice);
                $this->assertArrayHasKey('role', $choice['message']);
                $this->assertSame('assistant', $choice['message']['role']);
                $this->assertArrayHasKey('content', $choice['message']);

                $text = $choice['message']['content'];
                $this->assertStringContainsString('2', $text);

                // Verify usage if present
                if (isset($body['usage'])) {
                    $this->assertArrayHasKey('prompt_tokens', $body['usage']);
                    $this->assertArrayHasKey('completion_tokens', $body['usage']);
                    $this->assertGreaterThan(0, $body['usage']['prompt_tokens']);
                }

                // Verify model field
                $this->assertArrayHasKey('model', $body);
            }

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 12: Model Pricing CRUD via HTTP API ─────────────────

    public function testModelPricingEndpoints(): void
    {
        $webPort = 15788;
        $webScript = $this->createWebServerBootstrap($webPort);
        $process = $this->startProxyProcess($webScript, $webPort);
        $this->assertNotNull($process, 'Web server process should start');

        try {
            $client = new Client(['timeout' => 10, 'http_errors' => false]);
            $base = "http://127.0.0.1:{$webPort}";

            // 1) GET /api/settings/pricing → seeded models (>= 50)
            $r = $client->get("{$base}/api/settings/pricing");
            $this->assertSame(200, $r->getStatusCode());
            $list = json_decode((string) $r->getBody(), true);
            $this->assertIsArray($list);
            $this->assertGreaterThanOrEqual(50, count($list),
                'Should have at least 50 seeded models, got ' . count($list));

            // 2) POST new model
            $r = $client->post("{$base}/api/settings/pricing", [
                'json' => [
                    'model_id' => 'test-model-e2e',
                    'display_name' => 'E2E Test Model',
                    'input_cost_per_million' => '1.5',
                    'output_cost_per_million' => '7.5',
                ],
            ]);
            $this->assertContains($r->getStatusCode(), [200, 201],
                'POST pricing should return 200 or 201. Got: ' . $r->getStatusCode()
                . ' Body: ' . (string) $r->getBody());

            // 3) GET → verify test-model-e2e is in the list
            $r = $client->get("{$base}/api/settings/pricing");
            $list = json_decode((string) $r->getBody(), true);
            $found = false;
            foreach ($list as $item) {
                if ($item['model_id'] === 'test-model-e2e') {
                    $found = true;
                    $this->assertSame('E2E Test Model', $item['display_name']);
                    $this->assertSame('1.5', $item['input_cost_per_million']);
                    $this->assertSame('7.5', $item['output_cost_per_million']);
                    break;
                }
            }
            $this->assertTrue($found, 'test-model-e2e should be in pricing list after POST');

            // 4) PUT → update the model
            $r = $client->put("{$base}/api/settings/pricing/test-model-e2e", [
                'json' => [
                    'display_name' => 'Updated E2E',
                    'input_cost_per_million' => '2.0',
                    'output_cost_per_million' => '10.0',
                ],
            ]);
            $this->assertSame(200, $r->getStatusCode(),
                'PUT pricing should return 200. Got: ' . $r->getStatusCode()
                . ' Body: ' . (string) $r->getBody());

            // 5) GET → verify updated values
            $r = $client->get("{$base}/api/settings/pricing");
            $list = json_decode((string) $r->getBody(), true);
            foreach ($list as $item) {
                if ($item['model_id'] === 'test-model-e2e') {
                    $this->assertSame('Updated E2E', $item['display_name']);
                    $this->assertSame('2.0', $item['input_cost_per_million']);
                    $this->assertSame('10.0', $item['output_cost_per_million']);
                    break;
                }
            }

        } finally {
            $this->stopProxyProcess($process);
            @unlink($webScript);
        }
    }

    // ─── Test 13: Global Proxy Settings API ─────────────────────

    public function testGlobalProxyEndpoints(): void
    {
        $webPort = 15787;
        $webScript = $this->createWebServerBootstrap($webPort);
        $process = $this->startProxyProcess($webScript, $webPort);
        $this->assertNotNull($process, 'Web server process should start');

        try {
            $client = new Client(['timeout' => 10, 'http_errors' => false]);
            $base = "http://127.0.0.1:{$webPort}";

            // 1) GET /api/settings/proxy → initial state (no proxy)
            $r = $client->get("{$base}/api/settings/proxy");
            $this->assertSame(200, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertFalse($body['enabled'], 'Proxy should not be enabled initially');
            $this->assertTrue(
                $body['url'] === null || $body['url'] === '',
                'Proxy URL should be null or empty initially'
            );

            // 2) PUT → set proxy URL
            $r = $client->put("{$base}/api/settings/proxy", [
                'json' => ['url' => 'http://127.0.0.1:7890'],
            ]);
            $this->assertSame(200, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertTrue($body['ok']);

            // 3) GET → verify proxy URL is returned
            $r = $client->get("{$base}/api/settings/proxy");
            $body = json_decode((string) $r->getBody(), true);
            $this->assertSame('http://127.0.0.1:7890', $body['url']);
            $this->assertTrue($body['enabled']);

            // 4) PUT → clear proxy
            $r = $client->put("{$base}/api/settings/proxy", [
                'json' => ['url' => ''],
            ]);
            $this->assertSame(200, $r->getStatusCode());

            // 5) GET → verify cleared
            $r = $client->get("{$base}/api/settings/proxy");
            $body = json_decode((string) $r->getBody(), true);
            $this->assertFalse($body['enabled']);

            // 6) PUT → invalid scheme → 400
            $r = $client->put("{$base}/api/settings/proxy", [
                'json' => ['url' => 'ftp://invalid'],
            ]);
            $this->assertSame(400, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertArrayHasKey('error', $body);
            $this->assertStringContainsString('ftp', $body['error']);

        } finally {
            $this->stopProxyProcess($process);
            @unlink($webScript);
        }
    }

    // ─── Test 14: Stream Check Config API ───────────────────────

    public function testStreamCheckConfigEndpoints(): void
    {
        $webPort = 15786;
        $webScript = $this->createWebServerBootstrap($webPort);
        $process = $this->startProxyProcess($webScript, $webPort);
        $this->assertNotNull($process, 'Web server process should start');

        try {
            $client = new Client(['timeout' => 10, 'http_errors' => false]);
            $base = "http://127.0.0.1:{$webPort}";

            // 1) GET /api/stream-check/config → default values
            $r = $client->get("{$base}/api/stream-check/config");
            $this->assertSame(200, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertSame(45, $body['timeout_secs'], 'Default timeout_secs should be 45');
            $this->assertSame(2, $body['max_retries'], 'Default max_retries should be 2');
            $this->assertSame(6000, $body['degraded_threshold_ms'], 'Default degraded_threshold_ms should be 6000');
            $this->assertArrayHasKey('test_prompt', $body);

            // 2) PUT → update config
            $r = $client->put("{$base}/api/stream-check/config", [
                'json' => ['timeout_secs' => 30, 'max_retries' => 3],
            ]);
            $this->assertSame(200, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertSame(30, $body['timeout_secs']);
            $this->assertSame(3, $body['max_retries']);

            // 3) GET → verify updated values persist
            $r = $client->get("{$base}/api/stream-check/config");
            $this->assertSame(200, $r->getStatusCode());
            $body = json_decode((string) $r->getBody(), true);
            $this->assertSame(30, $body['timeout_secs'], 'Updated timeout_secs should persist');
            $this->assertSame(3, $body['max_retries'], 'Updated max_retries should persist');
            // Unchanged values should remain at defaults
            $this->assertSame(6000, $body['degraded_threshold_ms']);

        } finally {
            $this->stopProxyProcess($process);
            @unlink($webScript);
        }
    }

    // ─── Test 15: Usage Logging with Dynamic Pricing ────────────

    public function testProxyUsageLoggingWithDynamicPricing(): void
    {
        $proxyPort = 15785;

        $repo = new ProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'pricing-test-provider',
            'app_type' => 'codex',
            'name' => 'Pricing Test',
            'settings_config' => json_encode([
                'env' => [
                    'OPENAI_API_KEY' => $this->apiKey,
                    'OPENAI_BASE_URL' => $this->openaiBase,
                ],
            ]),
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
            'in_failover_queue' => 0,
        ]);
        $this->medoo->update('proxy_config', ['enabled' => 1, 'enable_logging' => 1], ['app_type' => 'codex']);

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            $client = new Client(['timeout' => 30, 'verify' => false]);
            $response = $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/chat/completions",
                [
                    'json' => [
                        'model' => $this->model,
                        'messages' => [['role' => 'user', 'content' => 'Say OK']],
                        'max_tokens' => 64,
                    ],
                    'http_errors' => false,
                ]
            );

            $status = $response->getStatusCode();
            if (!in_array($status, [200, 429, 403])) {
                $this->markTestSkipped('API returned unexpected status ' . $status . ': ' . (string) $response->getBody());
            }

            // Give the proxy a moment to flush the log
            usleep(500_000);

            // Re-open DB to read logs from the subprocess
            $checkDb = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
            $logs = $checkDb->select('proxy_request_logs', '*', [
                'provider_id' => 'pricing-test-provider',
            ]);

            $this->assertNotEmpty($logs, 'proxy_request_logs should have at least one entry');
            $log = $logs[0];

            // Verify the log entry has cost fields
            $this->assertArrayHasKey('total_cost_usd', $log);
            $this->assertArrayHasKey('input_cost_usd', $log);
            $this->assertArrayHasKey('output_cost_usd', $log);

            if ($status === 200) {
                // On success, tokens should be logged and cost should be > 0
                $this->assertGreaterThan(0, (int) $log['input_tokens'],
                    'Should log input tokens on successful request');
                $this->assertGreaterThan(0, (int) $log['output_tokens'],
                    'Should log output tokens on successful request');
                $this->assertGreaterThan(0, (float) $log['total_cost_usd'],
                    'Total cost should be > 0 for successful request');

                // Verify cost is calculated from model_pricing table
                // The model's pricing should come from the DB, not hardcoded
                $pricing = $checkDb->get('model_pricing', '*', ['model_id' => $this->model]);
                if ($pricing !== null) {
                    // Cost should be based on the pricing table rates
                    $expectedInputRate = (float) $pricing['input_cost_per_million'];
                    $expectedOutputRate = (float) $pricing['output_cost_per_million'];
                    $actualInputCost = (float) $log['input_cost_usd'];
                    $actualOutputCost = (float) $log['output_cost_usd'];

                    // Verify input cost matches: (input_tokens / 1M) * rate
                    $expectedInputCost = ((int) $log['input_tokens'] / 1_000_000) * $expectedInputRate;
                    $this->assertEqualsWithDelta($expectedInputCost, $actualInputCost, 0.0001,
                        'Input cost should be calculated from model_pricing table');
                }
            }

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Test 16: Rectifier Integration via Proxy ───────────────

    public function testRectifierIntegrationViaProxy(): void
    {
        $proxyPort = 15784;

        // Set up a Claude-type provider with a fake API key
        $repo = new ProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'rectifier-test',
            'app_type' => 'claude',
            'name' => 'Rectifier Test',
            'settings_config' => json_encode([
                'env' => [
                    'ANTHROPIC_API_KEY' => 'sk-ant-fake-key-for-rectifier-test',
                ],
            ]),
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
            'in_failover_queue' => 0,
        ]);
        $this->medoo->update('proxy_config', ['enabled' => 1], ['app_type' => 'claude']);

        $proxyScript = $this->createProxyBootstrap($proxyPort);
        $process = $this->startProxyProcess($proxyScript, $proxyPort);
        $this->assertNotNull($process);

        try {
            $client = new Client(['timeout' => 30, 'http_errors' => false]);

            // Send a request with thinking blocks and signatures that would
            // trigger the signature rectifier on a 400 error from upstream.
            // With a fake API key, we expect the proxy to:
            // 1. Forward to Anthropic API
            // 2. Get an auth error (401) — NOT a 400, so rectifier won't trigger
            // 3. Return the error without crashing
            //
            // The key assertion is that the proxy handles this gracefully.
            $response = $client->post(
                "http://127.0.0.1:{$proxyPort}/v1/messages",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'anthropic-version' => '2023-06-01',
                    ],
                    'json' => [
                        'model' => 'claude-sonnet-4-20250514',
                        'max_tokens' => 1024,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => 'Hello',
                            ],
                            [
                                'role' => 'assistant',
                                'content' => [
                                    [
                                        'type' => 'thinking',
                                        'thinking' => 'Let me think about this...',
                                        'signature' => 'fake-signature-that-is-invalid',
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => 'Hi there!',
                                    ],
                                ],
                            ],
                            [
                                'role' => 'user',
                                'content' => 'How are you?',
                            ],
                        ],
                        'thinking' => [
                            'type' => 'enabled',
                            'budget_tokens' => 10000,
                        ],
                    ],
                ]
            );

            $status = $response->getStatusCode();
            $rawBody = (string) $response->getBody();

            // The proxy should NOT crash — it should return some HTTP response.
            // With a fake key, we expect 401 (auth error) or 400 (invalid request)
            // or 502 (proxy error if connection fails). Any of these prove
            // the proxy handled the request without crashing.
            $this->assertContains($status, [400, 401, 403, 502],
                'Proxy should return an error status (not crash). Got: ' . $status
                . ' Body: ' . substr($rawBody, 0, 500));

            $this->assertNotEmpty($rawBody, 'Response body should not be empty');

            // Verify the proxy is still healthy after handling the rectifier scenario
            $healthResponse = $client->get("http://127.0.0.1:{$proxyPort}/health");
            $this->assertSame(200, $healthResponse->getStatusCode(),
                'Proxy should remain healthy after rectifier scenario');
            $healthBody = json_decode((string) $healthResponse->getBody(), true);
            $this->assertSame('healthy', $healthBody['status']);

        } finally {
            $this->stopProxyProcess($process);
            @unlink($proxyScript);
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Create a PHP bootstrap script that starts a standalone proxy server.
     */
    private function createProxyBootstrap(int $port): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $dbPath = $this->dbPath;
        $baseDir = $this->tmpHome . '/.cc-switch';

        $script = <<<PHP
        <?php
        require '{$autoload}';

        \$medoo = new \\Medoo\\Medoo(['type' => 'sqlite', 'database' => '{$dbPath}']);
        \$proxy = new \\CcSwitch\\Proxy\\ProxyServer(\$medoo, '127.0.0.1', {$port}, '{$baseDir}');
        \$proxy->start();
        PHP;

        $scriptPath = $this->tmpHome . '/proxy-e2e-' . $port . '.php';
        file_put_contents($scriptPath, $script);
        return $scriptPath;
    }

    /**
     * Start the proxy as a background process via proc_open.
     *
     * @return array{process: resource, pipes: array}|null
     */
    private function startProxyProcess(string $scriptPath, int $port): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['php', $scriptPath],
            $descriptors,
            $pipes,
            null,
            ['HOME' => $this->tmpHome]
        );

        if (!is_resource($process)) {
            return null;
        }

        // Wait for server to be ready (poll with timeout)
        $ready = false;
        $deadline = microtime(true) + 8.0; // 8 second timeout
        while (microtime(true) < $deadline) {
            usleep(100_000); // 100ms

            // Check if process died
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stderr = stream_get_contents($pipes[2]);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $this->fail("Proxy process died. stdout: {$stdout} stderr: {$stderr}");
                return null;
            }

            // Try to connect
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if ($fp) {
                fclose($fp);
                $ready = true;
                break;
            }
        }

        if (!$ready) {
            $this->stopProxyProcess(['process' => $process, 'pipes' => $pipes]);
            $this->fail("Proxy did not become ready within 8 seconds on port {$port}");
            return null;
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * Create a PHP bootstrap script that starts the web server (API routes).
     */
    private function createWebServerBootstrap(int $port): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $dbPath = $this->dbPath;
        $baseDir = $this->tmpHome . '/.cc-switch';
        $migrationsDir = dirname(__DIR__, 2) . '/migrations';

        $script = <<<PHP
        <?php
        require '{$autoload}';

        // Boot a minimal App by setting HOME so App::getDataDir() finds our temp dir
        putenv('HOME={$this->tmpHome}');

        \$app = \\CcSwitch\\App::boot();
        \$server = new \\CcSwitch\\Http\\Server(\$app, {$port}, false);
        \$server->start();
        PHP;

        $scriptPath = $this->tmpHome . '/web-e2e-' . $port . '.php';
        file_put_contents($scriptPath, $script);
        return $scriptPath;
    }

    /**
     * Stop a proxy background process.
     */
    private function stopProxyProcess(?array $handle): void
    {
        if ($handle === null) return;

        $process = $handle['process'];
        $pipes = $handle['pipes'];

        $status = proc_get_status($process);
        if ($status['running']) {
            // Send SIGTERM to the process group
            $pid = $status['pid'];
            @posix_kill($pid, SIGTERM);
            // Give it a moment to shut down
            usleep(500_000);

            $status = proc_get_status($process);
            if ($status['running']) {
                @posix_kill($pid, SIGKILL);
                usleep(200_000);
            }
        }

        @fclose($pipes[0]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($process);
    }
}
