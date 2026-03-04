<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Service\BackupService;
use PDO;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;
    private BackupService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-backup-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $this->dbPath = $this->tmpDir . '/cc-switch.db';

        // Create a real database with migrations
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        // Insert some test data so importSql validation passes
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('test_key', 'test_value')");
        $pdo->exec("INSERT INTO providers (id, app_type, name, settings_config) VALUES ('test-prov', 'claude', 'Test Provider', '{}')");

        unset($pdo);

        $this->service = new BackupService($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testRunCreatesBackup(): void
    {
        $path = $this->service->run();
        $this->assertFileExists($path);
        $this->assertStringContainsString('backup_', basename($path));
        $this->assertStringEndsWith('.db', $path);
    }

    public function testRunThrowsWhenDbMissing(): void
    {
        unlink($this->dbPath);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database file not found');
        $this->service->run();
    }

    public function testListReturnsBackups(): void
    {
        $this->assertSame([], $this->service->list());

        $this->service->run();
        $backups = $this->service->list();
        $this->assertCount(1, $backups);
        $this->assertArrayHasKey('filename', $backups[0]);
        $this->assertArrayHasKey('size_bytes', $backups[0]);
        $this->assertArrayHasKey('created_at', $backups[0]);
    }

    public function testRestore(): void
    {
        $backupPath = $this->service->run();
        $backupFile = basename($backupPath);

        // Modify the source db
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('new_key', 'new_value')");
        unset($pdo);

        // Restore from backup
        $this->service->restore($backupFile);

        // The database should have been restored
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'test_key'")->fetch();
        $this->assertSame('test_value', $row['value']);
    }

    public function testRestoreThrowsOnPathTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup filename');
        $this->service->restore('../etc/passwd');
    }

    public function testRestoreThrowsOnInvalidExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup file must have .db extension');
        $this->service->restore('backup.txt');
    }

    public function testRestoreThrowsWhenNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup file not found');
        $this->service->restore('nonexistent.db');
    }

    public function testCleanup(): void
    {
        // Create several backups
        for ($i = 0; $i < 5; $i++) {
            $this->service->run();
            usleep(10000); // Small delay for unique timestamps
        }

        $this->assertCount(5, $this->service->list());

        $this->service->cleanup(3);
        $this->assertCount(3, $this->service->list());
    }

    public function testCleanupDoesNothingWhenUnderLimit(): void
    {
        $this->service->run();
        $this->service->cleanup(10);
        $this->assertCount(1, $this->service->list());
    }

    public function testExportSql(): void
    {
        $sql = $this->service->exportSql();
        $this->assertStringContainsString('-- CC Switch SQLite export', $sql);
        $this->assertStringContainsString('BEGIN TRANSACTION', $sql);
        $this->assertStringContainsString('COMMIT', $sql);
        $this->assertStringContainsString('test_key', $sql);
        $this->assertStringContainsString('test_value', $sql);
    }

    public function testExportSqlThrowsWhenDbMissing(): void
    {
        unlink($this->dbPath);
        $this->expectException(\RuntimeException::class);
        $this->service->exportSql();
    }

    public function testExportSqlToFile(): void
    {
        $targetPath = $this->tmpDir . '/export/dump.sql';
        $result = $this->service->exportSqlToFile($targetPath);
        $this->assertSame($targetPath, $result);
        $this->assertFileExists($targetPath);

        $content = file_get_contents($targetPath);
        $this->assertStringContainsString('-- CC Switch SQLite export', $content);
    }

    public function testImportSql(): void
    {
        $sql = $this->service->exportSql();

        // Modify current DB
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("DELETE FROM settings");
        unset($pdo);

        $this->service->importSql($sql);

        // Verify data was restored
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'test_key'")->fetch();
        $this->assertSame('test_value', $row['value']);
    }

    public function testImportSqlRejectsNonCcSwitch(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only SQL backups exported by CC Switch are supported');
        $this->service->importSql('SELECT 1;');
    }

    public function testImportSqlHandlesBom(): void
    {
        $sql = $this->service->exportSql();
        $sqlWithBom = "\xEF\xBB\xBF" . $sql;

        // Should not throw - BOM is stripped
        $this->service->importSql($sqlWithBom);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'test_key'")->fetch();
        $this->assertSame('test_value', $row['value']);
    }

    public function testImportSqlFromFile(): void
    {
        $targetPath = $this->tmpDir . '/import.sql';
        $this->service->exportSqlToFile($targetPath);

        $this->service->importSqlFromFile($targetPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'test_key'")->fetch();
        $this->assertSame('test_value', $row['value']);
    }

    public function testImportSqlFromFileThrowsWhenNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQL file not found');
        $this->service->importSqlFromFile('/nonexistent/file.sql');
    }

    public function testPeriodicBackupIfNeededCreatesFirstBackup(): void
    {
        $this->service->periodicBackupIfNeeded(24);
        $backups = $this->service->list();
        $this->assertCount(1, $backups);
    }

    public function testPeriodicBackupIfNeededSkipsWhenRecent(): void
    {
        $this->service->run();
        $this->service->periodicBackupIfNeeded(24);
        $backups = $this->service->list();
        $this->assertCount(1, $backups); // No new backup
    }

    public function testPeriodicBackupIfNeededSkipsWhenIntervalZero(): void
    {
        $this->service->periodicBackupIfNeeded(0);
        $backups = $this->service->list();
        $this->assertSame([], $backups);
    }

    public function testPeriodicBackupCreatesWhenOld(): void
    {
        // Create a backup and backdate it
        $backupPath = $this->service->run();
        touch($backupPath, time() - 90000); // 25 hours ago

        $this->service->periodicBackupIfNeeded(24);
        $backups = $this->service->list();
        $this->assertGreaterThanOrEqual(2, count($backups));
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
