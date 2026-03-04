<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

class MigratorTest extends TestCase
{
    private PDO $pdo;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->migrationsDir = dirname(__DIR__, 2) . '/migrations';
    }

    public function testMigrateRunsAllMigrations(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $count = $migrator->migrate();

        $this->assertSame(15, $count);
    }

    public function testMigrateIsIdempotent(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);

        $first = $migrator->migrate();
        $this->assertSame(15, $first);

        $second = $migrator->migrate();
        $this->assertSame(0, $second);
    }

    public function testStatusShowsAllApplied(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $status = $migrator->status();

        $this->assertCount(15, $status);
        foreach ($status as $s) {
            $this->assertTrue($s['applied'], "Migration {$s['file']} should be applied");
            $this->assertSame(1, $s['batch']);
        }
    }

    public function testStatusBeforeMigrate(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $status = $migrator->status();

        $this->assertCount(15, $status);
        foreach ($status as $s) {
            $this->assertFalse($s['applied']);
            $this->assertNull($s['batch']);
        }
    }

    public function testProvidersTableCreated(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        // Insert and query providers
        $stmt = $this->pdo->prepare(
            'INSERT INTO providers (id, app_type, name, settings_config) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['p1', 'claude', 'Test', '{}']);

        $row = $this->pdo->query("SELECT * FROM providers WHERE id = 'p1'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('p1', $row['id']);
        $this->assertSame('claude', $row['app_type']);
        $this->assertSame('Test', $row['name']);
        $this->assertSame(0, (int) $row['is_current']);
    }

    public function testProvidersCompositePrimaryKey(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        // Same ID but different app_type should work
        $stmt = $this->pdo->prepare(
            'INSERT INTO providers (id, app_type, name, settings_config) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['p1', 'claude', 'Claude Provider', '{}']);
        $stmt->execute(['p1', 'codex', 'Codex Provider', '{}']);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM providers WHERE id = 'p1'")->fetchColumn();
        $this->assertSame(2, $count);

        // Duplicate (id, app_type) should fail
        $this->expectException(\PDOException::class);
        $stmt->execute(['p1', 'claude', 'Duplicate', '{}']);
    }

    public function testAllTablesCreated(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        // failover_queue is an index on providers, not a separate table
        $expectedTables = [
            'providers',
            'provider_endpoints',
            'universal_providers',
            'mcp_servers',
            'prompts',
            'skills',
            'skill_repos',
            'settings',
            'proxy_config',
            'provider_health',
            'proxy_request_logs',
            'model_pricing',
            'stream_check_logs',
            'migrations', // created by the migrator itself
        ];

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expectedTables as $table) {
            $this->assertContains($table, $tables, "Table '{$table}' should exist");
        }

        // Verify failover index exists
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_providers_failover'");
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('idx_providers_failover', $indexes);
    }

    public function testMcpServersTable(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $stmt = $this->pdo->prepare(
            'INSERT INTO mcp_servers (id, name, server_config, enabled_claude, enabled_codex) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['mcp-1', 'test-server', '{"command":"npx"}', 1, 1]);

        $row = $this->pdo->query("SELECT * FROM mcp_servers WHERE id = 'mcp-1'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('test-server', $row['name']);
        $this->assertSame(1, (int) $row['enabled_claude']);
        $this->assertSame(1, (int) $row['enabled_codex']);
        $this->assertSame(0, (int) $row['enabled_gemini']);
    }

    public function testProxyConfigTable(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        // Migration pre-inserts default rows for claude, codex, gemini
        $row = $this->pdo->query("SELECT * FROM proxy_config WHERE app_type = 'claude'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('claude', $row['app_type']);
        // Claude has custom defaults: max_retries=6, threshold=8
        $this->assertSame(6, (int) $row['max_retries']);
        $this->assertSame(8, (int) $row['circuit_failure_threshold']);

        // Codex uses different defaults
        $row = $this->pdo->query("SELECT * FROM proxy_config WHERE app_type = 'codex'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(3, (int) $row['max_retries']);
        $this->assertSame(4, (int) $row['circuit_failure_threshold']);

        // All three should exist
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM proxy_config")->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function testSettingsTable(): void
    {
        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $this->pdo->exec("INSERT INTO settings (key, value) VALUES ('theme', 'dark')");
        $this->pdo->exec("INSERT INTO settings (key, value) VALUES ('lang', 'en')");

        $row = $this->pdo->query("SELECT value FROM settings WHERE key = 'theme'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('dark', $row['value']);
    }
}
