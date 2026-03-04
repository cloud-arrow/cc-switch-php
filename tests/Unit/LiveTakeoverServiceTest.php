<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\LiveTakeoverService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class LiveTakeoverServiceTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private SettingsRepository $settingsRepo;
    private LiveTakeoverService $service;
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'cc-switch-takeover-') . '.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->settingsRepo = new SettingsRepository($this->medoo);
        $this->service = new LiveTakeoverService($this->settingsRepo);

        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-takeover-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome, 0755, true);
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
        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        $this->recursiveDelete($this->tmpHome);
    }

    public function testBackupAndRestoreClaude(): void
    {
        $configPath = $this->tmpHome . '/.claude/settings.json';
        $original = json_encode(['env' => ['ANTHROPIC_AUTH_TOKEN' => 'sk-test']]);
        file_put_contents($configPath, $original);

        $this->service->backup('claude');

        $backup = $this->settingsRepo->get('live_backup_claude');
        $this->assertSame($original, $backup);
        $this->assertNotNull($this->settingsRepo->get('live_backup_claude_at'));

        // Modify config
        file_put_contents($configPath, '{"modified": true}');

        // Restore
        $this->service->restore('claude');
        $this->assertSame($original, file_get_contents($configPath));
    }

    public function testTakeoverClaudeSetsProxyUrl(): void
    {
        $configPath = $this->tmpHome . '/.claude/settings.json';
        file_put_contents($configPath, json_encode(['env' => ['ANTHROPIC_AUTH_TOKEN' => 'sk-test']]));

        $this->service->takeover('claude', '127.0.0.1', 15721);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertSame('http://127.0.0.1:15721', $config['env']['ANTHROPIC_BASE_URL']);
        $this->assertSame('sk-test', $config['env']['ANTHROPIC_AUTH_TOKEN']);
        $this->assertTrue($this->service->isActive('claude'));
    }

    public function testTakeoverCodexSetsProxyUrl(): void
    {
        $configPath = $this->tmpHome . '/.codex/config.json';
        file_put_contents($configPath, json_encode(['env' => ['OPENAI_API_KEY' => 'sk-test']]));

        $this->service->takeover('codex', '127.0.0.1', 15721);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertSame('http://127.0.0.1:15721/v1', $config['env']['OPENAI_BASE_URL']);
    }

    public function testTakeoverGeminiSetsEnvVar(): void
    {
        $configPath = $this->tmpHome . '/.gemini/.env';
        file_put_contents($configPath, "GEMINI_API_KEY=test-key\n");

        $this->service->takeover('gemini', '127.0.0.1', 15721);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('API_BASE_URL=http://127.0.0.1:15721', $content);
        $this->assertStringContainsString('GEMINI_API_KEY=test-key', $content);
    }

    public function testIsActiveReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->service->isActive('claude'));
    }

    public function testIsActiveReturnsTrueAfterTakeover(): void
    {
        $configPath = $this->tmpHome . '/.claude/settings.json';
        file_put_contents($configPath, '{}');

        $this->service->takeover('claude');
        $this->assertTrue($this->service->isActive('claude'));
    }

    public function testIsActiveReturnsFalseAfterRestore(): void
    {
        $configPath = $this->tmpHome . '/.claude/settings.json';
        file_put_contents($configPath, '{}');

        $this->service->takeover('claude');
        $this->assertTrue($this->service->isActive('claude'));

        $this->service->restore('claude');
        $this->assertFalse($this->service->isActive('claude'));
    }

    public function testGetBackupStatusFormat(): void
    {
        $status = $this->service->getBackupStatus();

        $this->assertCount(5, $status);
        foreach (['claude', 'codex', 'gemini', 'opencode', 'openclaw'] as $app) {
            $this->assertArrayHasKey($app, $status);
            $this->assertArrayHasKey('active', $status[$app]);
            $this->assertArrayHasKey('has_backup', $status[$app]);
            $this->assertArrayHasKey('backup_at', $status[$app]);
            $this->assertFalse($status[$app]['active']);
            $this->assertFalse($status[$app]['has_backup']);
            $this->assertNull($status[$app]['backup_at']);
        }
    }

    public function testRestoreThrowsWhenNoBackup(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No backup found for claude');
        $this->service->restore('claude');
    }

    public function testGetConfigPathThrowsForUnknownApp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown app type: invalid');
        $this->service->getConfigPath('invalid');
    }

    public function testTakeoverOpenCodeSetsProvider(): void
    {
        $configPath = $this->tmpHome . '/.config/opencode/config.json';
        file_put_contents($configPath, json_encode(['provider' => []]));

        $this->service->takeover('opencode', '127.0.0.1', 15721);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertArrayHasKey('cc-switch-proxy', $config['provider']);
        $this->assertSame('http://127.0.0.1:15721/v1', $config['provider']['cc-switch-proxy']['baseUrl']);
        $this->assertSame('openai', $config['provider']['cc-switch-proxy']['type']);
        $this->assertTrue($this->service->isActive('opencode'));
    }

    public function testTakeoverOpenClawSetsProvider(): void
    {
        $configPath = $this->tmpHome . '/.openclaw/config.json';
        file_put_contents($configPath, '{}');

        $this->service->takeover('openclaw', '127.0.0.1', 15721);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertArrayHasKey('cc-switch-proxy', $config['models']['providers']);
        $this->assertSame('http://127.0.0.1:15721', $config['models']['providers']['cc-switch-proxy']['baseUrl']);
        $this->assertTrue($this->service->isActive('openclaw'));
    }

    public function testTakeoverCreatesConfigIfMissing(): void
    {
        // Remove the pre-created config
        $configPath = $this->tmpHome . '/.claude/settings.json';
        if (file_exists($configPath)) {
            unlink($configPath);
        }

        // takeover should still work (no file to backup)
        $this->service->takeover('claude', '127.0.0.1', 15721);

        $this->assertFileExists($configPath);
        $config = json_decode(file_get_contents($configPath), true);
        $this->assertSame('http://127.0.0.1:15721', $config['env']['ANTHROPIC_BASE_URL']);
        $this->assertTrue($this->service->isActive('claude'));
    }

    public function testTakeoverThrowsForUnknownApp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown app type: invalid');
        $this->service->takeover('invalid');
    }

    public function testTakeoverGeminiReplacesExistingApiBaseUrl(): void
    {
        $configPath = $this->tmpHome . '/.gemini/.env';
        file_put_contents($configPath, "GEMINI_API_KEY=key\nAPI_BASE_URL=http://old:8080\n");

        $this->service->takeover('gemini', '127.0.0.1', 15721);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('API_BASE_URL=http://127.0.0.1:15721', $content);
        // Should not have duplicate API_BASE_URL
        $this->assertSame(1, substr_count($content, 'API_BASE_URL='));
    }

    public function testGetConfigPathAllApps(): void
    {
        $this->assertStringContainsString('.claude/settings.json', $this->service->getConfigPath('claude'));
        $this->assertStringContainsString('.codex/config.json', $this->service->getConfigPath('codex'));
        $this->assertStringContainsString('.gemini/.env', $this->service->getConfigPath('gemini'));
        $this->assertStringContainsString('.config/opencode/config.json', $this->service->getConfigPath('opencode'));
        $this->assertStringContainsString('.openclaw/config.json', $this->service->getConfigPath('openclaw'));
    }

    public function testBackupThrowsWhenConfigMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config file not found');
        // Remove the claude dir
        $configPath = $this->tmpHome . '/.claude/settings.json';
        // No file exists at this path (we only created dir, not file)
        $this->service->backup('claude');
    }

    public function testGetBackupStatusAfterTakeover(): void
    {
        $configPath = $this->tmpHome . '/.claude/settings.json';
        file_put_contents($configPath, '{}');

        $this->service->takeover('claude');

        $status = $this->service->getBackupStatus();
        $this->assertTrue($status['claude']['active']);
        $this->assertTrue($status['claude']['has_backup']);
        $this->assertNotNull($status['claude']['backup_at']);
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
