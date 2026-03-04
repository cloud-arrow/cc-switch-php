<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\FailoverQueueRepository;
use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\UniversalProviderRepository;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class RepositoryCrudTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'cc-switch-test-') . '.db';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
    }

    protected function tearDown(): void
    {
        unset($this->medoo, $this->pdo);
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private function insertProvider(string $id, string $app, string $name): void
    {
        $repo = new ProviderRepository($this->medoo);
        $repo->insert([
            'id' => $id,
            'app_type' => $app,
            'name' => $name,
            'settings_config' => '{}',
            'is_current' => 0,
            'sort_index' => 0,
            'meta' => '{}',
        ]);
    }

    // --- FailoverQueueRepository ---

    public function testFailoverQueueListEmpty(): void
    {
        $repo = new FailoverQueueRepository($this->medoo);
        $this->assertSame([], $repo->list('claude'));
    }

    public function testFailoverQueueAddAndList(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');
        $this->insertProvider('p2', 'claude', 'Provider 2');

        $repo = new FailoverQueueRepository($this->medoo);
        $repo->add('claude', 'p1', 1);
        $repo->add('claude', 'p2', 2);

        $list = $repo->list('claude');
        $this->assertCount(2, $list);
        $this->assertSame('p1', $list[0]['id']);
        $this->assertSame('p2', $list[1]['id']);
    }

    public function testFailoverQueueRemove(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');
        $repo = new FailoverQueueRepository($this->medoo);
        $repo->add('claude', 'p1', 1);

        $this->assertCount(1, $repo->list('claude'));

        $repo->remove('claude', 'p1');
        $this->assertCount(0, $repo->list('claude'));
    }

    // --- HealthRepository ---

    public function testHealthGetReturnsNullWhenEmpty(): void
    {
        $repo = new HealthRepository($this->medoo);
        $this->assertNull($repo->get('nonexistent', 'claude'));
    }

    public function testHealthUpsertInsertAndGet(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');

        $repo = new HealthRepository($this->medoo);
        $repo->upsert([
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'updated_at' => '2024-01-01T00:00:00Z',
        ]);

        $row = $repo->get('p1', 'claude');
        $this->assertNotNull($row);
        $this->assertSame('p1', $row['provider_id']);
        $this->assertSame('claude', $row['app_type']);
    }

    public function testHealthUpsertUpdatesExisting(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');

        $repo = new HealthRepository($this->medoo);
        $repo->upsert([
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'updated_at' => '2024-01-01T00:00:00Z',
        ]);

        $repo->upsert([
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 0,
            'consecutive_failures' => 3,
            'last_error' => 'timeout',
            'updated_at' => '2024-01-02T00:00:00Z',
        ]);

        $row = $repo->get('p1', 'claude');
        $this->assertSame(0, (int) $row['is_healthy']);
        $this->assertSame(3, (int) $row['consecutive_failures']);
    }

    public function testHealthListByApp(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');
        $this->insertProvider('p2', 'claude', 'Provider 2');

        $repo = new HealthRepository($this->medoo);
        $repo->upsert([
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'updated_at' => '2024-01-01',
        ]);
        $repo->upsert([
            'provider_id' => 'p2',
            'app_type' => 'claude',
            'is_healthy' => 0,
            'consecutive_failures' => 1,
            'updated_at' => '2024-01-01',
        ]);

        $list = $repo->listByApp('claude');
        $this->assertCount(2, $list);
    }

    public function testHealthReset(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');

        $repo = new HealthRepository($this->medoo);
        $repo->upsert([
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 0,
            'consecutive_failures' => 5,
            'last_error' => 'fail',
            'updated_at' => '2024-01-01',
        ]);

        $repo->reset('p1', 'claude');

        $row = $repo->get('p1', 'claude');
        $this->assertSame(1, (int) $row['is_healthy']);
        $this->assertSame(0, (int) $row['consecutive_failures']);
    }

    public function testHealthResetAll(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');
        $this->insertProvider('p2', 'claude', 'Provider 2');

        $repo = new HealthRepository($this->medoo);
        $repo->upsert([
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 0,
            'consecutive_failures' => 3,
            'updated_at' => '2024-01-01',
        ]);
        $repo->upsert([
            'provider_id' => 'p2',
            'app_type' => 'claude',
            'is_healthy' => 0,
            'consecutive_failures' => 2,
            'updated_at' => '2024-01-01',
        ]);

        $repo->resetAll('claude');

        $list = $repo->listByApp('claude');
        foreach ($list as $row) {
            $this->assertSame(1, (int) $row['is_healthy']);
            $this->assertSame(0, (int) $row['consecutive_failures']);
        }
    }

    // --- McpRepository ---

    public function testMcpListEmpty(): void
    {
        $repo = new McpRepository($this->medoo);
        $this->assertSame([], $repo->list());
    }

    public function testMcpUpsertInsertAndGet(): void
    {
        $repo = new McpRepository($this->medoo);
        $repo->upsert([
            'id' => 'mcp-1',
            'name' => 'Test MCP',
            'server_config' => '{"command":"node"}',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $row = $repo->get('mcp-1');
        $this->assertNotNull($row);
        $this->assertSame('mcp-1', $row['id']);
        $this->assertSame('Test MCP', $row['name']);
    }

    public function testMcpUpsertUpdatesExisting(): void
    {
        $repo = new McpRepository($this->medoo);
        $repo->upsert([
            'id' => 'mcp-1',
            'name' => 'Original',
            'server_config' => '{}',
            'enabled_claude' => 0,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $repo->upsert([
            'id' => 'mcp-1',
            'name' => 'Updated',
            'server_config' => '{"new":true}',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $row = $repo->get('mcp-1');
        $this->assertSame('Updated', $row['name']);
        $this->assertSame(1, (int) $row['enabled_claude']);
    }

    public function testMcpGetByApp(): void
    {
        $repo = new McpRepository($this->medoo);
        $repo->upsert([
            'id' => 'mcp-1',
            'name' => 'Claude MCP',
            'server_config' => '{}',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);
        $repo->upsert([
            'id' => 'mcp-2',
            'name' => 'Codex MCP',
            'server_config' => '{}',
            'enabled_claude' => 0,
            'enabled_codex' => 1,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $claudeServers = $repo->getByApp('claude');
        $this->assertCount(1, $claudeServers);
        $this->assertSame('mcp-1', $claudeServers[0]['id']);

        $codexServers = $repo->getByApp('codex');
        $this->assertCount(1, $codexServers);
        $this->assertSame('mcp-2', $codexServers[0]['id']);
    }

    public function testMcpDelete(): void
    {
        $repo = new McpRepository($this->medoo);
        $repo->upsert([
            'id' => 'mcp-1',
            'name' => 'To Delete',
            'server_config' => '{}',
            'enabled_claude' => 0,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $repo->delete('mcp-1');
        $this->assertNull($repo->get('mcp-1'));
    }

    // --- PromptRepository ---

    public function testPromptListEmpty(): void
    {
        $repo = new PromptRepository($this->medoo);
        $this->assertSame([], $repo->list('claude'));
    }

    public function testPromptInsertAndGet(): void
    {
        $repo = new PromptRepository($this->medoo);
        $repo->insert([
            'id' => 'pr-1',
            'app_type' => 'claude',
            'name' => 'Test Prompt',
            'content' => 'Hello',
            'enabled' => 1,
            'created_at' => time(),
        ]);

        $row = $repo->get('pr-1', 'claude');
        $this->assertNotNull($row);
        $this->assertSame('pr-1', $row['id']);
        $this->assertSame('Test Prompt', $row['name']);
    }

    public function testPromptList(): void
    {
        $repo = new PromptRepository($this->medoo);
        $repo->insert([
            'id' => 'pr-1',
            'app_type' => 'claude',
            'name' => 'Prompt 1',
            'content' => 'Content 1',
            'enabled' => 1,
            'created_at' => 1000,
        ]);
        $repo->insert([
            'id' => 'pr-2',
            'app_type' => 'claude',
            'name' => 'Prompt 2',
            'content' => 'Content 2',
            'enabled' => 1,
            'created_at' => 2000,
        ]);

        $list = $repo->list('claude');
        $this->assertCount(2, $list);
    }

    public function testPromptUpdate(): void
    {
        $repo = new PromptRepository($this->medoo);
        $repo->insert([
            'id' => 'pr-1',
            'app_type' => 'claude',
            'name' => 'Old Name',
            'content' => 'Old Content',
            'enabled' => 1,
            'created_at' => time(),
        ]);

        $repo->update('pr-1', 'claude', ['name' => 'New Name', 'content' => 'New Content']);

        $row = $repo->get('pr-1', 'claude');
        $this->assertSame('New Name', $row['name']);
        $this->assertSame('New Content', $row['content']);
    }

    public function testPromptDelete(): void
    {
        $repo = new PromptRepository($this->medoo);
        $repo->insert([
            'id' => 'pr-1',
            'app_type' => 'claude',
            'name' => 'To Delete',
            'content' => 'Content',
            'enabled' => 1,
            'created_at' => time(),
        ]);

        $repo->delete('pr-1', 'claude');
        $this->assertNull($repo->get('pr-1', 'claude'));
    }

    // --- ProxyConfigRepository ---

    public function testProxyConfigGetReturnsNullForUnknownApp(): void
    {
        $repo = new ProxyConfigRepository($this->medoo);
        $this->assertNull($repo->get('nonexistent'));
    }

    public function testProxyConfigGetSeededRow(): void
    {
        // Migration seeds proxy_config with default rows for claude, codex, gemini
        $repo = new ProxyConfigRepository($this->medoo);
        $row = $repo->get('claude');
        $this->assertNotNull($row);
        $this->assertSame('claude', $row['app_type']);
    }

    public function testProxyConfigUpdate(): void
    {
        $repo = new ProxyConfigRepository($this->medoo);
        $repo->update('claude', ['listen_port' => 9999, 'max_retries' => 5]);

        $row = $repo->get('claude');
        $this->assertSame(9999, (int) $row['listen_port']);
        $this->assertSame(5, (int) $row['max_retries']);
    }

    // --- UniversalProviderRepository ---

    public function testUniversalProviderListEmpty(): void
    {
        $repo = new UniversalProviderRepository($this->medoo);
        $this->assertSame([], $repo->list());
    }

    public function testUniversalProviderInsertAndGet(): void
    {
        $repo = new UniversalProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'up-1',
            'name' => 'Test UP',
            'provider_type' => 'openai',
            'apps' => '{}',
            'base_url' => 'https://api.example.com',
            'api_key' => 'sk-test',
            'models' => '{}',
            'created_at' => time(),
        ]);

        $row = $repo->get('up-1');
        $this->assertNotNull($row);
        $this->assertSame('up-1', $row['id']);
        $this->assertSame('Test UP', $row['name']);
    }

    public function testUniversalProviderUpdate(): void
    {
        $repo = new UniversalProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'up-1',
            'name' => 'Old Name',
            'provider_type' => 'openai',
            'apps' => '{}',
            'base_url' => 'https://api.example.com',
            'api_key' => 'sk-test',
            'models' => '{}',
            'created_at' => time(),
        ]);

        $repo->update('up-1', ['name' => 'New Name']);

        $row = $repo->get('up-1');
        $this->assertSame('New Name', $row['name']);
    }

    public function testUniversalProviderDelete(): void
    {
        $repo = new UniversalProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'up-1',
            'name' => 'To Delete',
            'provider_type' => 'openai',
            'apps' => '{}',
            'base_url' => 'https://api.example.com',
            'api_key' => 'sk-test',
            'models' => '{}',
            'created_at' => time(),
        ]);

        $repo->delete('up-1');
        $this->assertNull($repo->get('up-1'));
    }

    public function testUniversalProviderList(): void
    {
        $repo = new UniversalProviderRepository($this->medoo);
        $repo->insert([
            'id' => 'up-1',
            'name' => 'UP 1',
            'provider_type' => 'openai',
            'apps' => '{}',
            'base_url' => 'https://a.com',
            'api_key' => 'k1',
            'models' => '{}',
            'created_at' => time(),
        ]);
        $repo->insert([
            'id' => 'up-2',
            'name' => 'UP 2',
            'provider_type' => 'anthropic',
            'apps' => '{}',
            'base_url' => 'https://b.com',
            'api_key' => 'k2',
            'models' => '{}',
            'created_at' => time(),
        ]);

        $list = $repo->list();
        $this->assertCount(2, $list);
    }
}
