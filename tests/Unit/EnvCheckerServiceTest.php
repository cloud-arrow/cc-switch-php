<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\EnvCheckerService;
use PHPUnit\Framework\TestCase;

class EnvCheckerServiceTest extends TestCase
{
    private EnvCheckerService $service;
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-envcheck-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome . '/.cc-switch', 0755, true);
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tmpHome);

        $this->service = new EnvCheckerService();
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

    public function testCheckFindsShellConfigConflicts(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "export ANTHROPIC_API_KEY=sk-test-123\nexport OPENAI_API_KEY=sk-openai\n");

        $conflicts = $this->service->check();

        $fileConflicts = array_filter($conflicts, fn($c) => $c['source_type'] === 'file');
        $varNames = array_column($fileConflicts, 'var_name');
        $this->assertContains('ANTHROPIC_API_KEY', $varNames);
        $this->assertContains('OPENAI_API_KEY', $varNames);
    }

    public function testCheckSkipsComments(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "# export ANTHROPIC_API_KEY=sk-test\nsome_other_line\n");

        $conflicts = $this->service->check();
        $fileConflicts = array_filter($conflicts, fn($c) => $c['source_type'] === 'file');
        $varNames = array_column($fileConflicts, 'var_name');
        $this->assertNotContains('ANTHROPIC_API_KEY', $varNames);
    }

    public function testCheckDetectsGeminiVars(): void
    {
        $zshrc = $this->tmpHome . '/.zshrc';
        file_put_contents($zshrc, "GEMINI_API_KEY=gem-123\nexport GOOGLE_GEMINI_KEY=goog-123\n");

        $conflicts = $this->service->check();
        $fileConflicts = array_filter($conflicts, fn($c) => $c['source_type'] === 'file');
        $varNames = array_column($fileConflicts, 'var_name');
        $this->assertContains('GEMINI_API_KEY', $varNames);
        $this->assertContains('GOOGLE_GEMINI_KEY', $varNames);
    }

    public function testCheckIncludesSourcePath(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "export ANTHROPIC_API_KEY=sk-test\n");

        $conflicts = $this->service->check();
        $fileConflicts = array_filter($conflicts, fn($c) => $c['source_type'] === 'file');

        foreach ($fileConflicts as $c) {
            if ($c['var_name'] === 'ANTHROPIC_API_KEY') {
                $this->assertStringContainsString($bashrc, $c['source_path']);
                $this->assertStringContainsString(':1', $c['source_path']);
            }
        }
    }

    public function testDeleteConflictsRemovesLines(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "export ANTHROPIC_API_KEY=sk-test\necho hello\nexport PATH=/usr/bin\n");

        $conflicts = [
            [
                'var_name' => 'ANTHROPIC_API_KEY',
                'value' => 'sk-test',
                'source_type' => 'file',
                'source_path' => $bashrc . ':1',
            ],
        ];

        $results = $this->service->deleteConflicts($conflicts);

        $this->assertCount(1, $results);
        $this->assertSame($bashrc, $results[0]['file']);
        $this->assertSame(1, $results[0]['removed_count']);

        $content = file_get_contents($bashrc);
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY', $content);
        $this->assertStringContainsString('echo hello', $content);

        // Check backup was created
        $this->assertFileExists($results[0]['backup_path']);
    }

    public function testDeleteConflictsSkipsSystemType(): void
    {
        $conflicts = [
            [
                'var_name' => 'ANTHROPIC_API_KEY',
                'source_type' => 'system',
                'source_path' => 'Process Environment',
            ],
        ];

        $results = $this->service->deleteConflicts($conflicts);
        $this->assertSame([], $results);
    }

    public function testDeleteConflictsHandlesMultipleVarsInSameFile(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "export ANTHROPIC_API_KEY=key1\nexport OPENAI_API_KEY=key2\nother line\n");

        $conflicts = [
            ['var_name' => 'ANTHROPIC_API_KEY', 'source_type' => 'file', 'source_path' => $bashrc . ':1'],
            ['var_name' => 'OPENAI_API_KEY', 'source_type' => 'file', 'source_path' => $bashrc . ':2'],
        ];

        $results = $this->service->deleteConflicts($conflicts);

        $this->assertCount(1, $results);
        $this->assertSame(2, $results[0]['removed_count']);

        $content = file_get_contents($bashrc);
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY', $content);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $content);
        $this->assertStringContainsString('other line', $content);
    }

    public function testRestoreBackup(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "echo hello\n");

        // First delete some conflicts to create a backup
        file_put_contents($bashrc, "export ANTHROPIC_API_KEY=sk-test\necho hello\n");
        $conflicts = [
            ['var_name' => 'ANTHROPIC_API_KEY', 'source_type' => 'file', 'source_path' => $bashrc . ':1'],
        ];
        $results = $this->service->deleteConflicts($conflicts);
        $backupFile = basename($results[0]['backup_path']);

        // Verify the var was removed
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY', file_get_contents($bashrc));

        // Restore
        $this->service->restoreBackup($backupFile);

        $content = file_get_contents($bashrc);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $content);
    }

    public function testRestoreBackupThrowsOnPathTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup filename');
        $this->service->restoreBackup('../etc/passwd');
    }

    public function testRestoreBackupThrowsWhenNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup file not found');
        $this->service->restoreBackup('nonexistent.json');
    }

    public function testRestoreBackupThrowsOnInvalidFormat(): void
    {
        $backupDir = $this->tmpHome . '/.cc-switch/env-backups';
        mkdir($backupDir, 0755, true);
        file_put_contents($backupDir . '/bad.json', 'not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup file format');
        $this->service->restoreBackup('bad.json');
    }

    public function testRestoreBackupThrowsWhenTargetFileMissing(): void
    {
        $backupDir = $this->tmpHome . '/.cc-switch/env-backups';
        mkdir($backupDir, 0755, true);
        file_put_contents($backupDir . '/test.json', json_encode([
            'file' => '/nonexistent/file',
            'lines' => [['line_num' => 1, 'content' => 'export ANTHROPIC_API_KEY=sk-test']],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target file not found');
        $this->service->restoreBackup('test.json');
    }

    public function testCheckDetectsVarsWithoutExport(): void
    {
        $profile = $this->tmpHome . '/.profile';
        file_put_contents($profile, "ANTHROPIC_BASE_URL=http://localhost\n");

        $conflicts = $this->service->check();
        $fileConflicts = array_filter($conflicts, fn($c) => $c['source_type'] === 'file');
        $varNames = array_column($fileConflicts, 'var_name');
        $this->assertContains('ANTHROPIC_BASE_URL', $varNames);
    }

    public function testCheckStripsQuotesFromValue(): void
    {
        $bashrc = $this->tmpHome . '/.bashrc';
        file_put_contents($bashrc, "export ANTHROPIC_API_KEY=\"sk-quoted\"\n");

        $conflicts = $this->service->check();
        $fileConflicts = array_filter($conflicts, fn($c) => $c['source_type'] === 'file' && $c['var_name'] === 'ANTHROPIC_API_KEY');

        foreach ($fileConflicts as $c) {
            $this->assertSame('sk-quoted', $c['value']);
        }
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
