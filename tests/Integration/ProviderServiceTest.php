<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Model\Provider;
use CcSwitch\Service\ProviderService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests ProviderService with a real SQLite database.
 *
 * NOTE: ConfigWriter calls are triggered during add/switch/delete. Since we
 * don't want to modify real config files, these tests only cover the DB layer.
 * ConfigWriter calls will fail silently or be skipped where home dirs don't exist.
 *
 * For a full integration test, override HOME env to a temp directory.
 */
class ProviderServiceTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private ProviderRepository $repo;
    private ProviderService $service;
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'cc-switch-svc-') . '.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->repo = new ProviderRepository($this->medoo);
        $this->service = new ProviderService($this->repo);

        // Redirect HOME to a temp directory to avoid touching real config files
        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-home-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome, 0755, true);
        // Create necessary config dirs
        mkdir($this->tmpHome . '/.claude', 0755, true);
        mkdir($this->tmpHome . '/.codex', 0755, true);
        mkdir($this->tmpHome . '/.gemini', 0755, true);
        mkdir($this->tmpHome . '/.config/opencode', 0755, true);
        mkdir($this->tmpHome . '/.openclaw', 0755, true);

        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tmpHome);
    }

    protected function tearDown(): void
    {
        // Restore HOME
        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        // Cleanup temp home
        $this->recursiveDelete($this->tmpHome);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeProvider(string $name, string $config = '{}'): Provider
    {
        $p = new Provider();
        $p->name = $name;
        $p->settings_config = $config;
        return $p;
    }

    // --- List ---

    public function testListEmpty(): void
    {
        $providers = $this->service->list(AppType::Claude);
        $this->assertSame([], $providers);
    }

    // --- Add (switch mode) ---

    public function testAddFirstProviderBecomesCurrent(): void
    {
        $p = $this->makeProvider('Provider A', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-test"}}');
        $this->service->add(AppType::Claude, $p);

        $providers = $this->service->list(AppType::Claude);
        $this->assertCount(1, $providers);

        $current = $this->service->getCurrent(AppType::Claude);
        $this->assertNotNull($current);
        $this->assertSame('Provider A', $current->name);
    }

    public function testAddSecondProviderNotCurrent(): void
    {
        $p1 = $this->makeProvider('Provider A', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-a"}}');
        $this->service->add(AppType::Claude, $p1);

        $p2 = $this->makeProvider('Provider B', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-b"}}');
        $this->service->add(AppType::Claude, $p2);

        $current = $this->service->getCurrent(AppType::Claude);
        $this->assertSame('Provider A', $current->name);

        $providers = $this->service->list(AppType::Claude);
        $this->assertCount(2, $providers);
    }

    public function testAddGeneratesUuidIfEmpty(): void
    {
        $p = $this->makeProvider('No ID');
        $this->service->add(AppType::Claude, $p);

        $this->assertNotEmpty($p->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $p->id);
    }

    // --- Add (additive mode) ---

    public function testAddAdditiveModeSavesToDb(): void
    {
        $p = $this->makeProvider('OpenCode Provider', '{"options":{"apiKey":"sk-oc"}}');
        $this->service->add(AppType::OpenCode, $p);

        $providers = $this->service->list(AppType::OpenCode);
        $this->assertCount(1, $providers);
    }

    public function testGetCurrentReturnsNullForAdditiveMode(): void
    {
        $this->assertNull($this->service->getCurrent(AppType::OpenCode));
    }

    // --- Get ---

    public function testGetById(): void
    {
        $p = $this->makeProvider('Find Me');
        $this->service->add(AppType::Claude, $p);

        $found = $this->service->get($p->id, AppType::Claude);
        $this->assertNotNull($found);
        $this->assertSame('Find Me', $found->name);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->service->get('nonexistent', AppType::Claude));
    }

    // --- Switch ---

    public function testSwitchTo(): void
    {
        $p1 = $this->makeProvider('Provider A', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-a"}}');
        $this->service->add(AppType::Claude, $p1);

        $p2 = $this->makeProvider('Provider B', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-b"}}');
        $this->service->add(AppType::Claude, $p2);

        $this->service->switchTo($p2->id, AppType::Claude);

        $current = $this->service->getCurrent(AppType::Claude);
        $this->assertSame($p2->id, $current->id);
        $this->assertSame('Provider B', $current->name);

        // Verify config file was updated
        $settingsFile = $this->tmpHome . '/.claude/settings.json';
        $this->assertFileExists($settingsFile);
        $settings = json_decode(file_get_contents($settingsFile), true);
        $this->assertSame('sk-b', $settings['env']['ANTHROPIC_AUTH_TOKEN']);
    }

    public function testSwitchToNonexistentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->service->switchTo('nonexistent', AppType::Claude);
    }

    public function testSwitchOnAdditiveThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('additive-mode');
        $this->service->switchTo('any-id', AppType::OpenCode);
    }

    // --- Delete ---

    public function testDeleteProvider(): void
    {
        $p1 = $this->makeProvider('Provider A', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-a"}}');
        $this->service->add(AppType::Claude, $p1);

        $p2 = $this->makeProvider('Provider B');
        $this->service->add(AppType::Claude, $p2);

        // Can delete non-current
        $this->service->delete($p2->id, AppType::Claude);

        $providers = $this->service->list(AppType::Claude);
        $this->assertCount(1, $providers);
    }

    public function testDeleteCurrentProviderThrows(): void
    {
        $p = $this->makeProvider('Current', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-a"}}');
        $this->service->add(AppType::Claude, $p);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('currently active');
        $this->service->delete($p->id, AppType::Claude);
    }

    public function testDeleteAdditiveMode(): void
    {
        $p = $this->makeProvider('OC Provider', '{"options":{"apiKey":"sk"}}');
        $this->service->add(AppType::OpenCode, $p);

        $this->service->delete($p->id, AppType::OpenCode);

        $providers = $this->service->list(AppType::OpenCode);
        $this->assertSame([], $providers);
    }

    // --- Update ---

    public function testUpdateProvider(): void
    {
        $p = $this->makeProvider('Original', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-old"}}');
        $this->service->add(AppType::Claude, $p);

        $p->name = 'Updated';
        $p->settings_config = '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-new"}}';
        $this->service->update(AppType::Claude, $p);

        $found = $this->service->get($p->id, AppType::Claude);
        $this->assertSame('Updated', $found->name);
    }

    // --- Export / Import ---

    public function testExport(): void
    {
        $p1 = $this->makeProvider('A');
        $p2 = $this->makeProvider('B');
        $this->service->add(AppType::Claude, $p1);
        $this->service->add(AppType::Claude, $p2);

        $exported = $this->service->export(AppType::Claude);

        $this->assertCount(2, $exported);
        $names = array_column($exported, 'name');
        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
    }

    public function testImportNewProviders(): void
    {
        $rows = [
            ['id' => 'imp-1', 'name' => 'Imported A', 'settings_config' => '{}', 'meta' => '{}'],
            ['id' => 'imp-2', 'name' => 'Imported B', 'settings_config' => '{}', 'meta' => '{}'],
        ];

        $count = $this->service->import(AppType::Claude, $rows);

        $this->assertSame(2, $count);
        $providers = $this->service->list(AppType::Claude);
        $this->assertCount(2, $providers);
    }

    public function testImportUpdatesExisting(): void
    {
        $p = $this->makeProvider('Original');
        $p->id = 'existing-1';
        $this->service->add(AppType::Claude, $p);

        $rows = [
            ['id' => 'existing-1', 'name' => 'Overwritten', 'settings_config' => '{"new":true}', 'meta' => '{}'],
        ];

        $count = $this->service->import(AppType::Claude, $rows);
        $this->assertSame(1, $count);

        $found = $this->service->get('existing-1', AppType::Claude);
        $this->assertSame('Overwritten', $found->name);
    }

    // --- Presets ---

    public function testLoadPresetsReturnsArray(): void
    {
        $presets = ProviderService::loadPresets(AppType::Claude);
        $this->assertIsArray($presets);
        $this->assertNotEmpty($presets, 'Claude presets should exist');

        // Each preset should have a name
        foreach ($presets as $preset) {
            $this->assertArrayHasKey('name', $preset);
        }
    }

    public function testLoadPresetsForAllApps(): void
    {
        foreach (AppType::cases() as $app) {
            $presets = ProviderService::loadPresets($app);
            $this->assertIsArray($presets, "Presets for {$app->value} should be an array");
        }
    }

    // --- ConfigWriter integration (uses temp HOME) ---

    public function testSwitchWritesClaudeSettings(): void
    {
        $p1 = $this->makeProvider('Provider A', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-a","ANTHROPIC_BASE_URL":"https://a.example.com"}}');
        $this->service->add(AppType::Claude, $p1);

        $p2 = $this->makeProvider('Provider B', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-b","ANTHROPIC_BASE_URL":"https://b.example.com"}}');
        $this->service->add(AppType::Claude, $p2);

        $this->service->switchTo($p2->id, AppType::Claude);

        $settingsPath = $this->tmpHome . '/.claude/settings.json';
        $this->assertFileExists($settingsPath);

        $settings = json_decode(file_get_contents($settingsPath), true);
        $this->assertSame('sk-b', $settings['env']['ANTHROPIC_AUTH_TOKEN']);
        $this->assertSame('https://b.example.com', $settings['env']['ANTHROPIC_BASE_URL']);
    }

    public function testClaudeSettingsPreservesExistingKeys(): void
    {
        // Write some pre-existing settings
        $settingsPath = $this->tmpHome . '/.claude/settings.json';
        file_put_contents($settingsPath, json_encode([
            'permissions' => ['allow' => ['Read']],
            'env' => ['CUSTOM_KEY' => 'custom_value'],
        ]));

        $p = $this->makeProvider('Test', '{"env":{"ANTHROPIC_AUTH_TOKEN":"sk-new"}}');
        $this->service->add(AppType::Claude, $p);

        $settings = json_decode(file_get_contents($settingsPath), true);
        // New keys merged
        $this->assertSame('sk-new', $settings['env']['ANTHROPIC_AUTH_TOKEN']);
        // Existing keys preserved
        $this->assertSame('custom_value', $settings['env']['CUSTOM_KEY']);
        $this->assertSame(['allow' => ['Read']], $settings['permissions']);
    }

    public function testGeminiWriterCreatesEnvFile(): void
    {
        $p = $this->makeProvider('Gemini Test', '{"env":{"GEMINI_API_KEY":"AIza-test","GEMINI_MODEL":"gemini-pro"}}');
        $this->service->add(AppType::Gemini, $p);

        $envPath = $this->tmpHome . '/.gemini/.env';
        $this->assertFileExists($envPath);

        $content = file_get_contents($envPath);
        $this->assertStringContainsString('GEMINI_API_KEY=AIza-test', $content);
        $this->assertStringContainsString('GEMINI_MODEL=gemini-pro', $content);
    }

    public function testOpenCodeAdditiveWrite(): void
    {
        $p1 = $this->makeProvider('OC Provider 1', '{"options":{"apiKey":"sk-1"}}');
        $this->service->add(AppType::OpenCode, $p1);

        $p2 = $this->makeProvider('OC Provider 2', '{"options":{"apiKey":"sk-2"}}');
        $this->service->add(AppType::OpenCode, $p2);

        $configPath = $this->tmpHome . '/.config/opencode/opencode.json';
        $this->assertFileExists($configPath);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertArrayHasKey('provider', $config);
        $this->assertArrayHasKey($p1->id, $config['provider']);
        $this->assertArrayHasKey($p2->id, $config['provider']);
    }

    public function testOpenClawAdditiveWrite(): void
    {
        $p = $this->makeProvider('Claw Provider', '{"baseUrl":"https://api.example.com","apiKey":"sk-claw"}');
        $this->service->add(AppType::OpenClaw, $p);

        $configPath = $this->tmpHome . '/.openclaw/openclaw.json';
        $this->assertFileExists($configPath);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertArrayHasKey('models', $config);
        $this->assertArrayHasKey('providers', $config['models']);
        $this->assertArrayHasKey($p->id, $config['models']['providers']);
    }
}
