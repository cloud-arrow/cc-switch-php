<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

class WorkspaceServiceTest extends TestCase
{
    private string $tmpDir;
    private string $origHome;
    private WorkspaceService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/workspace_test_' . getmypid() . '_' . hrtime(true);
        $this->origHome = getenv('HOME') ?: '';
        putenv("HOME={$this->tmpDir}");
        $_SERVER['HOME'] = $this->tmpDir;
        $this->service = new WorkspaceService();
    }

    protected function tearDown(): void
    {
        putenv("HOME={$this->origHome}");
        $_SERVER['HOME'] = $this->origHome;
        $this->recursiveDelete($this->tmpDir);
    }

    // --- Whitelist validation ---

    public function testReadFileRejectsInvalidFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->readFile('EVIL.md');
    }

    public function testWriteFileRejectsInvalidFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeFile('../etc/passwd', 'hack');
    }

    public function testReadFileReturnsNullForNonexistent(): void
    {
        $this->assertNull($this->service->readFile('AGENTS.md'));
    }

    // --- File read/write ---

    public function testWriteAndReadFile(): void
    {
        $this->service->writeFile('SOUL.md', 'Be helpful');
        $content = $this->service->readFile('SOUL.md');
        $this->assertSame('Be helpful', $content);
    }

    // --- listFiles ---

    public function testListFilesShowsExistence(): void
    {
        $this->service->writeFile('AGENTS.md', 'test content');
        $files = $this->service->listFiles();
        $this->assertCount(9, $files); // 9 allowed files

        $agents = null;
        $soul = null;
        foreach ($files as $f) {
            if ($f['filename'] === 'AGENTS.md') {
                $agents = $f;
            }
            if ($f['filename'] === 'SOUL.md') {
                $soul = $f;
            }
        }

        $this->assertTrue($agents['exists']);
        $this->assertGreaterThan(0, $agents['size']);
        $this->assertFalse($soul['exists']);
        $this->assertSame(0, $soul['size']);
    }

    // --- Date validation ---

    public function testInvalidDateFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->readDailyMemory('not-a-date');
    }

    public function testValidDateFormat(): void
    {
        $this->assertNull($this->service->readDailyMemory('2025-01-15'));
    }

    // --- Daily memory CRUD ---

    public function testDailyMemoryCrud(): void
    {
        $date = '2025-06-15';

        // Write
        $this->service->writeDailyMemory($date, 'Today was productive');
        $content = $this->service->readDailyMemory($date);
        $this->assertSame('Today was productive', $content);

        // List
        $list = $this->service->listDailyMemory();
        $this->assertCount(1, $list);
        $this->assertSame($date, $list[0]['date']);
        $this->assertSame('2025-06-15.md', $list[0]['filename']);
        $this->assertGreaterThan(0, $list[0]['size']);

        // Delete
        $this->service->deleteDailyMemory($date);
        $this->assertNull($this->service->readDailyMemory($date));
    }

    // --- Search ---

    public function testSearchDailyMemory(): void
    {
        $this->service->writeDailyMemory('2025-06-10', 'Fixed the authentication bug');
        $this->service->writeDailyMemory('2025-06-11', 'Worked on new features');
        $this->service->writeDailyMemory('2025-06-12', 'Another authentication fix');

        $results = $this->service->searchDailyMemory('authentication');
        $this->assertCount(2, $results);

        // Newest first
        $this->assertSame('2025-06-12', $results[0]['date']);
        $this->assertSame('2025-06-10', $results[1]['date']);

        // Match count
        $this->assertSame(1, $results[0]['matchCount']);

        // Snippet contains context
        $this->assertStringContainsString('authentication', $results[0]['snippet']);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $this->service->writeDailyMemory('2025-01-01', 'nothing here');
        $results = $this->service->searchDailyMemory('nonexistent');
        $this->assertEmpty($results);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $this->service->writeDailyMemory('2025-01-01', 'Found a BUG today');
        $results = $this->service->searchDailyMemory('bug');
        $this->assertCount(1, $results);
    }

    public function testSearchEmptyQueryReturnsEmpty(): void
    {
        $this->service->writeDailyMemory('2025-01-01', 'content');
        $results = $this->service->searchDailyMemory('');
        $this->assertEmpty($results);
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
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
