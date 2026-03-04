<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\McpService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class McpServiceTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private McpRepository $mcpRepo;
    private SettingsRepository $settingsRepo;
    private McpService $service;
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-mcp-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome . '/.cc-switch', 0755, true);
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tmpHome);

        $dbPath = $this->tmpHome . '/.cc-switch/test.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->mcpRepo = new McpRepository($this->medoo);
        $this->settingsRepo = new SettingsRepository($this->medoo);
        $this->service = new McpService($this->mcpRepo, $this->settingsRepo);
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

    public function testListReturnsEmptyArray(): void
    {
        $result = $this->service->list();
        $this->assertSame([], $result);
    }

    public function testUpsertAndList(): void
    {
        $this->service->upsert([
            'id' => 'test-server',
            'name' => 'Test Server',
            'server_config' => json_encode(['type' => 'stdio', 'command' => 'node', 'args' => ['server.js']]),
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $list = $this->service->list();
        $this->assertCount(1, $list);
        $this->assertSame('test-server', $list[0]->id);
        $this->assertSame('Test Server', $list[0]->name);
    }

    public function testGetById(): void
    {
        $this->service->upsert([
            'id' => 'srv-1',
            'name' => 'Server 1',
            'server_config' => '{}',
            'enabled_claude' => 1,
        ]);

        $server = $this->service->get('srv-1');
        $this->assertNotNull($server);
        $this->assertSame('srv-1', $server->id);

        $missing = $this->service->get('nonexistent');
        $this->assertNull($missing);
    }

    public function testGetByApp(): void
    {
        $this->service->upsert([
            'id' => 'srv-claude',
            'name' => 'Claude Server',
            'server_config' => '{}',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
        ]);
        $this->service->upsert([
            'id' => 'srv-codex',
            'name' => 'Codex Server',
            'server_config' => '{}',
            'enabled_claude' => 0,
            'enabled_codex' => 1,
        ]);

        $claudeServers = $this->service->getByApp('claude');
        $this->assertCount(1, $claudeServers);
        $this->assertSame('srv-claude', $claudeServers[0]->id);

        $codexServers = $this->service->getByApp('codex');
        $this->assertCount(1, $codexServers);
        $this->assertSame('srv-codex', $codexServers[0]->id);
    }

    public function testDelete(): void
    {
        $this->service->upsert([
            'id' => 'to-delete',
            'name' => 'Delete Me',
            'server_config' => '{}',
            'enabled_claude' => 0,
        ]);

        $this->assertNotNull($this->service->get('to-delete'));
        $this->service->delete('to-delete');
        $this->assertNull($this->service->get('to-delete'));
    }

    public function testUpsertSyncsToClaude(): void
    {
        $this->service->upsert([
            'id' => 'sync-test',
            'name' => 'Sync Test',
            'server_config' => json_encode(['type' => 'stdio', 'command' => 'npx', 'args' => ['-y', 'server']]),
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        $this->assertFileExists($claudePath);
        $data = json_decode(file_get_contents($claudePath), true);
        $this->assertArrayHasKey('mcpServers', $data);
        $this->assertArrayHasKey('sync-test', $data['mcpServers']);
        $this->assertSame('npx', $data['mcpServers']['sync-test']['command']);
    }

    public function testUpsertSyncsToGeminiWithFormatConversion(): void
    {
        $this->service->upsert([
            'id' => 'http-server',
            'name' => 'HTTP Server',
            'server_config' => json_encode(['type' => 'http', 'url' => 'https://example.com/mcp']),
            'enabled_claude' => 0,
            'enabled_codex' => 0,
            'enabled_gemini' => 1,
            'enabled_opencode' => 0,
        ]);

        $geminiPath = $this->tmpHome . '/.config/gemini-cli/settings/mcp_servers.json';
        $this->assertFileExists($geminiPath);
        $data = json_decode(file_get_contents($geminiPath), true);
        $this->assertArrayHasKey('mcpServers', $data);
        $this->assertArrayHasKey('http-server', $data['mcpServers']);
        // Gemini uses httpUrl instead of url
        $this->assertSame('https://example.com/mcp', $data['mcpServers']['http-server']['httpUrl']);
        $this->assertArrayNotHasKey('url', $data['mcpServers']['http-server']);
        $this->assertArrayNotHasKey('type', $data['mcpServers']['http-server']);
    }

    public function testUpsertSyncsToOpenCodeWithFormatConversion(): void
    {
        $this->service->upsert([
            'id' => 'stdio-srv',
            'name' => 'Stdio Server',
            'server_config' => json_encode([
                'type' => 'stdio',
                'command' => 'node',
                'args' => ['server.js'],
                'env' => ['DEBUG' => '1'],
            ]),
            'enabled_claude' => 0,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 1,
        ]);

        $opencodePath = $this->tmpHome . '/.opencode/mcp.json';
        $this->assertFileExists($opencodePath);
        $data = json_decode(file_get_contents($opencodePath), true);
        $this->assertArrayHasKey('stdio-srv', $data);
        $this->assertSame('local', $data['stdio-srv']['type']);
        $this->assertSame(['node', 'server.js'], $data['stdio-srv']['command']);
        $this->assertSame(['DEBUG' => '1'], $data['stdio-srv']['environment']);
    }

    public function testUpsertSyncsToCodex(): void
    {
        $this->service->upsert([
            'id' => 'codex-srv',
            'name' => 'Codex Server',
            'server_config' => json_encode(['type' => 'stdio', 'command' => 'python', 'args' => ['server.py']]),
            'enabled_claude' => 0,
            'enabled_codex' => 1,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
        ]);

        $codexPath = $this->tmpHome . '/.codex/config.toml';
        $this->assertFileExists($codexPath);
        $content = file_get_contents($codexPath);
        $this->assertStringContainsString('[mcp_servers.codex-srv]', $content);
        $this->assertStringContainsString('command = "python"', $content);
    }

    public function testDeleteRemovesFromAppConfigs(): void
    {
        $this->service->upsert([
            'id' => 'del-sync',
            'name' => 'Delete Sync',
            'server_config' => json_encode(['type' => 'stdio', 'command' => 'test']),
            'enabled_claude' => 1,
        ]);

        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        $this->assertFileExists($claudePath);
        $data = json_decode(file_get_contents($claudePath), true);
        $this->assertArrayHasKey('del-sync', $data['mcpServers']);

        $this->service->delete('del-sync');
        $data = json_decode(file_get_contents($claudePath), true);
        $this->assertArrayNotHasKey('del-sync', $data['mcpServers']);
    }

    public function testSyncToApp(): void
    {
        // Insert servers directly to DB
        $this->mcpRepo->upsert([
            'id' => 'manual-srv',
            'name' => 'Manual Server',
            'server_config' => json_encode(['type' => 'stdio', 'command' => 'echo']),
            'enabled_claude' => 1,
        ]);

        $this->service->syncToApp('claude');

        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        $this->assertFileExists($claudePath);
        $data = json_decode(file_get_contents($claudePath), true);
        $this->assertArrayHasKey('manual-srv', $data['mcpServers']);
    }

    public function testSyncAll(): void
    {
        $this->mcpRepo->upsert([
            'id' => 'all-srv',
            'name' => 'All Server',
            'server_config' => json_encode(['type' => 'stdio', 'command' => 'echo']),
            'enabled_claude' => 1,
            'enabled_codex' => 1,
        ]);

        $this->service->syncAll();

        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        $codexPath = $this->tmpHome . '/.codex/config.toml';
        $this->assertFileExists($claudePath);
        $this->assertFileExists($codexPath);
    }

    public function testImportFromClaude(): void
    {
        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        $data = [
            'mcpServers' => [
                'imported-srv' => [
                    'type' => 'stdio',
                    'command' => 'node',
                    'args' => ['index.js'],
                ],
            ],
        ];
        mkdir(dirname($claudePath), 0755, true);
        file_put_contents($claudePath, json_encode($data));

        $count = $this->service->importFromApp('claude');
        $this->assertSame(1, $count);

        $server = $this->service->get('imported-srv');
        $this->assertNotNull($server);
        $this->assertSame(1, $server->enabled_claude);
    }

    public function testImportFromGeminiWithHttpUrl(): void
    {
        $geminiPath = $this->tmpHome . '/.config/gemini-cli/settings/mcp_servers.json';
        mkdir(dirname($geminiPath), 0755, true);
        file_put_contents($geminiPath, json_encode([
            'mcpServers' => [
                'gemini-srv' => [
                    'httpUrl' => 'https://example.com/mcp',
                ],
            ],
        ]));

        $count = $this->service->importFromApp('gemini');
        $this->assertSame(1, $count);

        $server = $this->service->get('gemini-srv');
        $this->assertNotNull($server);
        $config = json_decode($server->server_config, true);
        $this->assertSame('https://example.com/mcp', $config['url']);
        $this->assertSame('http', $config['type']);
    }

    public function testImportFromCodex(): void
    {
        $codexPath = $this->tmpHome . '/.codex/config.toml';
        mkdir(dirname($codexPath), 0755, true);
        file_put_contents($codexPath, <<<TOML
[mcp_servers.codex-imported]
type = "stdio"
command = "python"
args = ["server.py"]
TOML);

        $count = $this->service->importFromApp('codex');
        $this->assertSame(1, $count);

        $server = $this->service->get('codex-imported');
        $this->assertNotNull($server);
    }

    public function testImportFromOpenCode(): void
    {
        $opencodePath = $this->tmpHome . '/.opencode/mcp.json';
        mkdir(dirname($opencodePath), 0755, true);
        file_put_contents($opencodePath, json_encode([
            'oc-srv' => [
                'type' => 'local',
                'command' => ['node', 'server.js'],
                'environment' => ['KEY' => 'val'],
            ],
        ]));

        $count = $this->service->importFromApp('opencode');
        $this->assertSame(1, $count);

        $server = $this->service->get('oc-srv');
        $this->assertNotNull($server);
        $config = json_decode($server->server_config, true);
        $this->assertSame('stdio', $config['type']);
        $this->assertSame('node', $config['command']);
        $this->assertSame(['server.js'], $config['args']);
        $this->assertSame(['KEY' => 'val'], $config['env']);
    }

    public function testImportFromNonexistentFileReturnsZero(): void
    {
        $this->assertSame(0, $this->service->importFromApp('claude'));
        $this->assertSame(0, $this->service->importFromApp('codex'));
        $this->assertSame(0, $this->service->importFromApp('gemini'));
        $this->assertSame(0, $this->service->importFromApp('opencode'));
    }

    public function testImportExistingServerEnablesApp(): void
    {
        // First import from claude
        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        mkdir(dirname($claudePath), 0755, true);
        file_put_contents($claudePath, json_encode([
            'mcpServers' => [
                'shared-srv' => ['type' => 'stdio', 'command' => 'test'],
            ],
        ]));
        $this->service->importFromApp('claude');

        // Import same server from codex
        $codexPath = $this->tmpHome . '/.codex/config.toml';
        mkdir(dirname($codexPath), 0755, true);
        file_put_contents($codexPath, "[mcp_servers.shared-srv]\ncommand = \"test\"\n");
        $this->service->importFromApp('codex');

        $server = $this->service->get('shared-srv');
        $this->assertNotNull($server);
        $this->assertSame(1, $server->enabled_claude);
        $this->assertSame(1, $server->enabled_codex);
    }

    public function testUpsertWithEmptyConfig(): void
    {
        $server = $this->service->upsert([
            'id' => 'empty-cfg',
            'name' => 'Empty Config',
            'server_config' => '',
            'enabled_claude' => 1,
        ]);

        $this->assertSame('empty-cfg', $server->id);
        // Should not create config file since config is empty
        $claudePath = $this->tmpHome . '/.claude/mcp.json';
        $this->assertFileDoesNotExist($claudePath);
    }

    public function testSseServerSyncsToOpenCode(): void
    {
        $this->service->upsert([
            'id' => 'sse-srv',
            'name' => 'SSE Server',
            'server_config' => json_encode([
                'type' => 'sse',
                'url' => 'https://example.com/sse',
                'headers' => ['Authorization' => 'Bearer token'],
            ]),
            'enabled_opencode' => 1,
        ]);

        $opencodePath = $this->tmpHome . '/.opencode/mcp.json';
        $data = json_decode(file_get_contents($opencodePath), true);
        $this->assertSame('remote', $data['sse-srv']['type']);
        $this->assertSame('https://example.com/sse', $data['sse-srv']['url']);
        $this->assertSame(['Authorization' => 'Bearer token'], $data['sse-srv']['headers']);
    }

    public function testImportFromOpenCodeRemoteType(): void
    {
        $opencodePath = $this->tmpHome . '/.opencode/mcp.json';
        mkdir(dirname($opencodePath), 0755, true);
        file_put_contents($opencodePath, json_encode([
            'remote-srv' => [
                'type' => 'remote',
                'url' => 'https://example.com/sse',
                'headers' => ['X-Key' => 'val'],
            ],
        ]));

        $this->service->importFromApp('opencode');
        $server = $this->service->get('remote-srv');
        $this->assertNotNull($server);
        $config = json_decode($server->server_config, true);
        $this->assertSame('sse', $config['type']);
        $this->assertSame('https://example.com/sse', $config['url']);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
