<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\SkillRepository;
use CcSwitch\DeepLink\McpImporter;
use CcSwitch\DeepLink\PromptImporter;
use CcSwitch\DeepLink\ProviderImporter;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class DeepLinkImporterTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private string $origHome;
    private string $tempHome;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $this->medoo = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:',
            'pdo' => $this->pdo,
        ]);

        $this->origHome = getenv('HOME') ?: '';
        $this->tempHome = sys_get_temp_dir() . '/cc-switch-import-test-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tempHome, 0755, true);
        putenv('HOME=' . $this->tempHome);
        $_SERVER['HOME'] = $this->tempHome;
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->origHome);
        $_SERVER['HOME'] = $this->origHome;
        $this->removeDir($this->tempHome);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ========================================================================
    // McpImporter
    // ========================================================================

    public function testMcpImporterImportNewServers(): void
    {
        $repo = new McpRepository($this->medoo);
        $settingsRepo = new SettingsRepository($this->medoo);
        $importer = new McpImporter($repo, $settingsRepo);

        $result = $importer->import([
            'apps' => 'claude,codex',
            'config' => [
                'mcpServers' => [
                    'my-server' => [
                        'command' => 'node',
                        'args' => ['server.js'],
                        'type' => 'stdio',
                    ],
                ],
            ],
        ]);

        $this->assertSame(1, $result['imported_count']);
        $this->assertContains('my-server', $result['imported_ids']);
        $this->assertEmpty($result['failed']);

        // Verify in database
        $server = $repo->get('my-server');
        $this->assertNotNull($server);
        $this->assertSame(1, (int) $server['enabled_claude']);
        $this->assertSame(1, (int) $server['enabled_codex']);
        $this->assertSame(0, (int) $server['enabled_gemini']);
    }

    public function testMcpImporterMergeExistingServer(): void
    {
        $repo = new McpRepository($this->medoo);
        $settingsRepo = new SettingsRepository($this->medoo);
        $importer = new McpImporter($repo, $settingsRepo);

        // Import first with claude only
        $importer->import([
            'apps' => 'claude',
            'config' => [
                'mcpServers' => [
                    'my-server' => ['command' => 'node', 'type' => 'stdio'],
                ],
            ],
        ]);

        // Import again with codex — should merge
        $result = $importer->import([
            'apps' => 'codex',
            'config' => [
                'mcpServers' => [
                    'my-server' => ['command' => 'node', 'type' => 'stdio'],
                ],
            ],
        ]);

        $this->assertSame(1, $result['imported_count']);

        $server = $repo->get('my-server');
        $this->assertSame(1, (int) $server['enabled_claude']);
        $this->assertSame(1, (int) $server['enabled_codex']);
    }

    public function testMcpImporterInvalidServerSpec(): void
    {
        $repo = new McpRepository($this->medoo);
        $settingsRepo = new SettingsRepository($this->medoo);
        $importer = new McpImporter($repo, $settingsRepo);

        $result = $importer->import([
            'apps' => 'claude',
            'config' => [
                'mcpServers' => [
                    'bad-server' => 'not-an-array',
                ],
            ],
        ]);

        $this->assertSame(0, $result['imported_count']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame('bad-server', $result['failed'][0]['id']);
    }

    public function testMcpImporterInvalidConfig(): void
    {
        $repo = new McpRepository($this->medoo);
        $settingsRepo = new SettingsRepository($this->medoo);
        $importer = new McpImporter($repo, $settingsRepo);

        $result = $importer->import([
            'apps' => 'claude',
            'config' => 'not-an-array',
        ]);

        $this->assertSame(0, $result['imported_count']);
    }

    public function testMcpImporterDirectConfigFormat(): void
    {
        $repo = new McpRepository($this->medoo);
        $settingsRepo = new SettingsRepository($this->medoo);
        $importer = new McpImporter($repo, $settingsRepo);

        // Config without mcpServers wrapper
        $result = $importer->import([
            'apps' => 'claude',
            'config' => [
                'my-server' => ['command' => 'node', 'type' => 'stdio'],
            ],
        ]);

        $this->assertSame(1, $result['imported_count']);
    }

    // ========================================================================
    // ProviderImporter
    // ========================================================================

    public function testProviderImporterImportClaude(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'claude',
            'name' => 'My Claude',
            'endpoint' => 'https://api.anthropic.com',
            'apiKey' => 'sk-test-key',
            'model' => 'claude-opus-4-6-20260206',
        ]);

        $this->assertNotEmpty($id);
        $this->assertStringStartsWith('myclaude-', $id);

        $provider = $repo->get($id, 'claude');
        $this->assertNotNull($provider);
        $this->assertSame('My Claude', $provider['name']);

        $config = json_decode($provider['settings_config'], true);
        $this->assertSame('sk-test-key', $config['env']['ANTHROPIC_AUTH_TOKEN']);
        $this->assertSame('claude-opus-4-6-20260206', $config['env']['ANTHROPIC_MODEL']);
    }

    public function testProviderImporterImportCodex(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'codex',
            'name' => 'My Codex',
            'endpoint' => 'https://api.openai.com',
            'apiKey' => 'sk-openai',
            'model' => 'gpt-5',
        ]);

        $provider = $repo->get($id, 'codex');
        $config = json_decode($provider['settings_config'], true);
        $this->assertSame('sk-openai', $config['auth']['OPENAI_API_KEY']);
        $this->assertStringContainsString('model = "gpt-5"', $config['config']);
    }

    public function testProviderImporterImportGemini(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'gemini',
            'name' => 'My Gemini',
            'endpoint' => 'https://api.gemini.com',
            'apiKey' => 'gemini-key',
            'model' => 'gemini-2.5-pro',
        ]);

        $provider = $repo->get($id, 'gemini');
        $config = json_decode($provider['settings_config'], true);
        $this->assertSame('gemini-key', $config['env']['GEMINI_API_KEY']);
        $this->assertSame('gemini-2.5-pro', $config['env']['GEMINI_MODEL']);
    }

    public function testProviderImporterImportOpenCode(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'opencode',
            'name' => 'My OpenCode',
            'endpoint' => 'https://api.example.com',
            'apiKey' => 'oc-key',
            'model' => 'custom-model',
        ]);

        $provider = $repo->get($id, 'opencode');
        $config = json_decode($provider['settings_config'], true);
        $this->assertSame('@ai-sdk/openai-compatible', $config['npm']);
        $this->assertSame('oc-key', $config['options']['apiKey']);
        $this->assertArrayHasKey('custom-model', $config['models']);
    }

    public function testProviderImporterImportUnknownApp(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'unknown',
            'name' => 'Unknown App',
        ]);

        $provider = $repo->get($id, 'unknown');
        $config = json_decode($provider['settings_config'], true);
        $this->assertSame(['env' => []], $config);
    }

    public function testProviderImporterImportWithOptionalFields(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'claude',
            'name' => 'Test Provider',
            'homepage' => 'https://example.com',
            'notes' => 'Some notes',
            'icon' => 'star',
        ]);

        $provider = $repo->get($id, 'claude');
        $this->assertSame('https://example.com', $provider['website_url']);
        $this->assertSame('Some notes', $provider['notes']);
        $this->assertSame('star', $provider['icon']);
    }

    public function testProviderImporterImportClaudeWithoutModel(): void
    {
        $repo = new ProviderRepository($this->medoo);
        $importer = new ProviderImporter($repo);

        $id = $importer->import([
            'app' => 'claude',
            'name' => 'NoModel',
            'apiKey' => 'sk-test',
            'endpoint' => 'https://api.anthropic.com',
        ]);

        $provider = $repo->get($id, 'claude');
        $config = json_decode($provider['settings_config'], true);
        $this->assertArrayNotHasKey('ANTHROPIC_MODEL', $config['env']);
    }

    // ========================================================================
    // PromptImporter
    // ========================================================================

    public function testPromptImporterImport(): void
    {
        $repo = new PromptRepository($this->medoo);
        $importer = new PromptImporter($repo);

        $id = $importer->import([
            'app' => 'claude',
            'name' => 'My Prompt',
            'content' => 'You are a helpful assistant.',
            'description' => 'A test prompt',
            'enabled' => true,
        ]);

        $this->assertNotEmpty($id);

        $prompt = $repo->get($id, 'claude');
        $this->assertNotNull($prompt);
        $this->assertSame('My Prompt', $prompt['name']);
        $this->assertSame('You are a helpful assistant.', $prompt['content']);
        $this->assertSame('A test prompt', $prompt['description']);
        $this->assertSame(1, (int) $prompt['enabled']);
    }

    public function testPromptImporterImportDisabled(): void
    {
        $repo = new PromptRepository($this->medoo);
        $importer = new PromptImporter($repo);

        $id = $importer->import([
            'app' => 'codex',
            'name' => 'Disabled Prompt',
            'content' => 'content',
            'enabled' => false,
        ]);

        $prompt = $repo->get($id, 'codex');
        $this->assertSame(0, (int) $prompt['enabled']);
    }

    public function testPromptImporterImportWithoutOptionalFields(): void
    {
        $repo = new PromptRepository($this->medoo);
        $importer = new PromptImporter($repo);

        $id = $importer->import([
            'app' => 'claude',
            'name' => 'Simple',
            'content' => 'Hello',
        ]);

        $prompt = $repo->get($id, 'claude');
        $this->assertNotNull($prompt);
        $this->assertNull($prompt['description']);
    }
}
