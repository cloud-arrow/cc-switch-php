<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\EnvCheckerService;
use PHPUnit\Framework\TestCase;

class EnvManagerTest extends TestCase
{
    private string $tmpDir;
    private string $origHome;
    private EnvCheckerService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/env_test_' . getmypid() . '_' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->tmpDir . '/.cc-switch/env-backups', 0755, true);

        $this->origHome = getenv('HOME') ?: '';
        putenv("HOME={$this->tmpDir}");
        $_SERVER['HOME'] = $this->tmpDir;

        $this->service = new EnvCheckerService();
    }

    protected function tearDown(): void
    {
        putenv("HOME={$this->origHome}");
        $_SERVER['HOME'] = $this->origHome;
        $this->recursiveDelete($this->tmpDir);
    }

    public function testDeleteConflictsRemovesLines(): void
    {
        // Create a test shell config file
        $bashrcPath = $this->tmpDir . '/.bashrc';
        file_put_contents($bashrcPath, implode("\n", [
            '# Some comment',
            'export ANTHROPIC_API_KEY="sk-test-123"',
            'export PATH="/usr/bin:$PATH"',
            'OPENAI_API_KEY=sk-openai-456',
            'echo "hello"',
        ]));

        $conflicts = [
            [
                'var_name' => 'ANTHROPIC_API_KEY',
                'source_type' => 'file',
                'source_path' => $bashrcPath . ':2',
            ],
            [
                'var_name' => 'OPENAI_API_KEY',
                'source_type' => 'file',
                'source_path' => $bashrcPath . ':4',
            ],
        ];

        $result = $this->service->deleteConflicts($conflicts);

        $this->assertCount(1, $result);
        $this->assertSame($bashrcPath, $result[0]['file']);
        $this->assertSame(2, $result[0]['removed_count']);
        $this->assertNotEmpty($result[0]['backup_path']);

        // Verify the lines were removed
        $content = file_get_contents($bashrcPath);
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY', $content);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $content);
        $this->assertStringContainsString('PATH', $content);
    }

    public function testDeleteConflictsSkipsSystemSource(): void
    {
        $conflicts = [
            [
                'var_name' => 'ANTHROPIC_API_KEY',
                'source_type' => 'system',
                'source_path' => 'Process Environment',
            ],
        ];

        $result = $this->service->deleteConflicts($conflicts);
        $this->assertCount(0, $result);
    }

    public function testDeleteConflictsCreatesBackup(): void
    {
        $bashrcPath = $this->tmpDir . '/.bashrc';
        file_put_contents($bashrcPath, "export ANTHROPIC_API_KEY=\"sk-test\"\n");

        $conflicts = [
            [
                'var_name' => 'ANTHROPIC_API_KEY',
                'source_type' => 'file',
                'source_path' => $bashrcPath . ':1',
            ],
        ];

        $result = $this->service->deleteConflicts($conflicts);
        $this->assertCount(1, $result);
        $this->assertFileExists($result[0]['backup_path']);

        $backup = json_decode(file_get_contents($result[0]['backup_path']), true);
        $this->assertSame($bashrcPath, $backup['file']);
        $this->assertCount(1, $backup['lines']);
    }

    public function testRestoreBackupAppendsLines(): void
    {
        $bashrcPath = $this->tmpDir . '/.bashrc';
        file_put_contents($bashrcPath, "# remaining content\n");

        // Create a backup file
        $backupDir = $this->tmpDir . '/.cc-switch/env-backups';
        $backupFilename = 'bashrc_20240101_120000.json';
        $backupData = [
            'file' => $bashrcPath,
            'lines' => [
                ['line_num' => 1, 'content' => 'export ANTHROPIC_API_KEY="sk-test"'],
            ],
        ];
        file_put_contents($backupDir . '/' . $backupFilename, json_encode($backupData));

        $this->service->restoreBackup($backupFilename);

        $content = file_get_contents($bashrcPath);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $content);
        $this->assertStringContainsString('# remaining content', $content);
    }

    public function testRestoreBackupRejectsPathTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->restoreBackup('../../../etc/passwd');
    }

    public function testRestoreBackupRejectsNonexistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->restoreBackup('nonexistent.json');
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
