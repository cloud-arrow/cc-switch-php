<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\App;
use CcSwitch\Database\Database;
use CcSwitch\Database\Migrator;
use CcSwitch\Http\Router;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private Router $router;
    private App $app;
    private string $dbPath;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/cc-switch-router-test-' . uniqid();
        mkdir($this->baseDir, 0755, true);

        $this->dbPath = $this->baseDir . '/cc-switch.db';
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $medoo = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
        $database = new Database($pdo, $medoo);

        // Use reflection to create App with private constructor
        $ref = new \ReflectionClass(App::class);
        $this->app = $ref->newInstanceWithoutConstructor();
        $dbProp = $ref->getProperty('database');
        $dbProp->setValue($this->app, $database);
        $baseProp = $ref->getProperty('baseDir');
        $baseProp->setValue($this->app, $this->baseDir);

        $this->router = new Router($this->app);

        // Seed a provider for tests that need one
        $medoo->insert('providers', [
            'id' => 'test-p1',
            'app_type' => 'claude',
            'name' => 'Test Provider',
            'settings_config' => '{}',
            'is_current' => 1,
            'sort_index' => 0,
            'meta' => '{}',
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $files = glob($this->baseDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->baseDir);
    }

    private function makeRequest(string $method, string $uri, array $body = [], array $query = []): TestSwooleRequest
    {
        $request = new TestSwooleRequest();
        $request->server = ['request_method' => $method, 'request_uri' => $uri];
        $request->header = [];
        $request->get = !empty($query) ? $query : null;
        if (!empty($body)) {
            $request->setBody(json_encode($body));
        }
        return $request;
    }

    private function dispatch(string $method, string $uri, array $body = [], array $query = []): array
    {
        $request = $this->makeRequest($method, $uri, $body, $query);
        return $this->router->dispatch($request);
    }

    // --- Routing: 404 and 405 ---

    public function testNotFoundRoute(): void
    {
        $result = $this->dispatch('GET', '/api/nonexistent');
        $this->assertSame(404, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertSame('Not found', $body['error']);
    }

    public function testMethodNotAllowed(): void
    {
        $result = $this->dispatch('DELETE', '/api/settings');
        $this->assertSame(405, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertSame('Method not allowed', $body['error']);
        $this->assertNotEmpty($body['allowed']);
    }

    // --- Settings ---

    public function testGetSettings(): void
    {
        $result = $this->dispatch('GET', '/api/settings');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    public function testUpdateSettings(): void
    {
        $result = $this->dispatch('PUT', '/api/settings', ['theme' => 'dark']);
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertTrue($body['ok']);

        // Verify it was saved
        $result = $this->dispatch('GET', '/api/settings');
        $body = json_decode($result['body'], true);
        $this->assertSame('dark', $body['theme']);
    }

    public function testGetRectifier(): void
    {
        $result = $this->dispatch('GET', '/api/settings/rectifier');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertArrayHasKey('signature_enabled', $body);
        $this->assertArrayHasKey('budget_enabled', $body);
    }

    public function testSetRectifier(): void
    {
        $result = $this->dispatch('PUT', '/api/settings/rectifier', [
            'signature_enabled' => false,
            'budget_enabled' => true,
        ]);
        $this->assertSame(200, $result['status']);
    }

    public function testGetProxy(): void
    {
        $result = $this->dispatch('GET', '/api/settings/proxy');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertArrayHasKey('enabled', $body);
    }

    public function testGetPricing(): void
    {
        $result = $this->dispatch('GET', '/api/settings/pricing');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    // --- Providers ---

    public function testListProviders(): void
    {
        $result = $this->dispatch('GET', '/api/providers/claude');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
        $this->assertNotEmpty($body);
    }

    public function testAddProvider(): void
    {
        $result = $this->dispatch('POST', '/api/providers/claude', [
            'id' => 'new-p1',
            'name' => 'New Provider',
            'settings_config' => '{"key":"value"}',
        ]);
        $this->assertSame(201, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertSame('new-p1', $body['id']);
    }

    public function testGetProvider(): void
    {
        $result = $this->dispatch('GET', '/api/providers/claude/test-p1');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertSame('test-p1', $body['id']);
    }

    public function testUpdateProvider(): void
    {
        $result = $this->dispatch('PUT', '/api/providers/claude/test-p1', [
            'name' => 'Updated Provider',
        ]);
        $this->assertSame(200, $result['status']);
    }

    public function testSwitchProvider(): void
    {
        // Add another provider first
        $this->dispatch('POST', '/api/providers/claude', [
            'id' => 'switch-p2',
            'name' => 'Switch Target',
        ]);

        $result = $this->dispatch('POST', '/api/providers/claude/switch-p2/switch');
        $this->assertSame(200, $result['status']);
    }

    public function testDeleteProvider(): void
    {
        $this->dispatch('POST', '/api/providers/claude', [
            'id' => 'del-p1',
            'name' => 'To Delete',
        ]);

        $result = $this->dispatch('DELETE', '/api/providers/claude/del-p1');
        $this->assertSame(200, $result['status']);
    }

    public function testGetEndpoints(): void
    {
        $result = $this->dispatch('GET', '/api/providers/claude/test-p1/endpoints');
        $this->assertSame(200, $result['status']);
    }

    public function testAddEndpoint(): void
    {
        $result = $this->dispatch('POST', '/api/providers/claude/test-p1/endpoints', [
            'url' => 'https://api.example.com',
        ]);
        $this->assertSame(200, $result['status']);
    }

    // --- Usage ---

    public function testUsageSummary(): void
    {
        $result = $this->dispatch('GET', '/api/usage/summary');
        $this->assertSame(200, $result['status']);
    }

    public function testUsageTrends(): void
    {
        $result = $this->dispatch('GET', '/api/usage/trends');
        $this->assertSame(200, $result['status']);
    }

    public function testUsageProviders(): void
    {
        $result = $this->dispatch('GET', '/api/usage/providers');
        $this->assertSame(200, $result['status']);
    }

    public function testUsageModels(): void
    {
        $result = $this->dispatch('GET', '/api/usage/models');
        $this->assertSame(200, $result['status']);
    }

    public function testUsageStats(): void
    {
        $result = $this->dispatch('GET', '/api/usage/stats');
        $this->assertSame(200, $result['status']);
    }

    public function testUsageLogs(): void
    {
        $result = $this->dispatch('GET', '/api/usage/logs');
        $this->assertSame(200, $result['status']);
    }

    // --- Proxy ---

    public function testProxyStatus(): void
    {
        $result = $this->dispatch('GET', '/api/proxy/status');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertArrayHasKey('running', $body);
    }

    public function testProxyTakeoverStatus(): void
    {
        $result = $this->dispatch('GET', '/api/proxy/takeover/status');
        $this->assertSame(200, $result['status']);
    }

    // --- Backup ---

    public function testBackupList(): void
    {
        $result = $this->dispatch('GET', '/api/backup/list');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    public function testBackupCreate(): void
    {
        $result = $this->dispatch('POST', '/api/backup/create');
        $this->assertSame(200, $result['status']);
    }

    public function testBackupCleanup(): void
    {
        $result = $this->dispatch('POST', '/api/backup/cleanup', ['retain_count' => 5]);
        $this->assertSame(200, $result['status']);
    }

    // --- Env ---

    public function testEnvCheck(): void
    {
        $result = $this->dispatch('GET', '/api/env/check');
        $this->assertSame(200, $result['status']);
    }

    // --- Skills ---

    public function testSkillsList(): void
    {
        $result = $this->dispatch('GET', '/api/skills');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    public function testSkillReposList(): void
    {
        $result = $this->dispatch('GET', '/api/skill-repos');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    // --- Prompts ---

    public function testPromptsList(): void
    {
        $result = $this->dispatch('GET', '/api/prompts/claude');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    public function testPromptsAdd(): void
    {
        $result = $this->dispatch('POST', '/api/prompts/claude', [
            'name' => 'Test Prompt',
            'content' => 'Hello world',
        ]);
        $this->assertSame(201, $result['status']);
    }

    // --- MCP ---

    public function testMcpList(): void
    {
        $result = $this->dispatch('GET', '/api/mcp');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    // --- Sessions ---

    public function testSessionsList(): void
    {
        $result = $this->dispatch('GET', '/api/sessions');
        $this->assertSame(200, $result['status']);
    }

    // --- Claude Plugin ---

    public function testClaudePluginStatus(): void
    {
        $result = $this->dispatch('GET', '/api/claude-plugin/status');
        $this->assertSame(200, $result['status']);
    }

    // --- Universal Providers ---

    public function testUniversalProvidersList(): void
    {
        $result = $this->dispatch('GET', '/api/universal-providers');
        $this->assertSame(200, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertIsArray($body);
    }

    // --- Query string stripping ---

    public function testQueryStringStripping(): void
    {
        $result = $this->dispatch('GET', '/api/settings?foo=bar');
        $this->assertSame(200, $result['status']);
    }

    // --- JSON response format ---

    public function testResponseHasContentTypeHeader(): void
    {
        $result = $this->dispatch('GET', '/api/settings');
        $this->assertSame('application/json', $result['headers']['Content-Type']);
    }
}

class TestSwooleRequest extends \Swoole\Http\Request
{
    private string $bodyContent = '';

    public function setBody(string $body): void
    {
        $this->bodyContent = $body;
    }

    public function rawContent(): string|false
    {
        return $this->bodyContent ?: false;
    }
}
