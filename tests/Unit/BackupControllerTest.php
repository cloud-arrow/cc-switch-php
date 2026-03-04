<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\BackupService;
use PHPUnit\Framework\TestCase;

class BackupControllerTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;
    private BackupService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/backup_test_' . getmypid() . '_' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $this->dbPath = $this->tmpDir . '/cc-switch.db';

        // Create a minimal SQLite database
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO test VALUES (1, 'hello')");
        unset($pdo);

        $this->service = new BackupService($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testListReturnsEmptyWhenNoBackups(): void
    {
        $result = $this->service->list();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testCreateReturnsPath(): void
    {
        $path = $this->service->run();
        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
    }

    public function testListReturnsBackupsAfterCreate(): void
    {
        $this->service->run();
        $result = $this->service->list();
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('filename', $result[0]);
        $this->assertArrayHasKey('size_bytes', $result[0]);
        $this->assertArrayHasKey('created_at', $result[0]);
    }

    public function testRestoreFromBackup(): void
    {
        $path = $this->service->run();
        $filename = basename($path);

        // Modify the original DB
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec("DELETE FROM test");
        $count = (int) $pdo->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(0, $count);
        unset($pdo);

        // Restore
        $this->service->restore($filename);

        // Verify data is back
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRestoreInvalidFilenameThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->restore('../evil.db');
    }

    public function testCleanupRetainsCorrectCount(): void
    {
        // Create 3 backups
        $this->service->run();
        usleep(1000);
        $this->service->run();
        usleep(1000);
        $this->service->run();

        $this->assertCount(3, $this->service->list());

        $this->service->cleanup(2);
        $this->assertCount(2, $this->service->list());
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
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
