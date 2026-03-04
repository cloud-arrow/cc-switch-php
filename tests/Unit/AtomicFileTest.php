<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Util\AtomicFile;
use PHPUnit\Framework\TestCase;

class AtomicFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-test-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testWriteCreatesFile(): void
    {
        $path = $this->tmpDir . '/test.txt';

        AtomicFile::write($path, 'Hello, World!');

        $this->assertFileExists($path);
        $this->assertSame('Hello, World!', file_get_contents($path));
    }

    public function testWriteOverwritesExisting(): void
    {
        $path = $this->tmpDir . '/test.txt';
        file_put_contents($path, 'old content');

        AtomicFile::write($path, 'new content');

        $this->assertSame('new content', file_get_contents($path));
    }

    public function testWriteCreatesParentDirectory(): void
    {
        $path = $this->tmpDir . '/subdir/deep/test.txt';

        AtomicFile::write($path, 'nested');

        $this->assertFileExists($path);
        $this->assertSame('nested', file_get_contents($path));

        // Cleanup nested dirs
        @unlink($path);
        @rmdir($this->tmpDir . '/subdir/deep');
        @rmdir($this->tmpDir . '/subdir');
    }

    public function testWritePreservesPermissions(): void
    {
        $path = $this->tmpDir . '/test.txt';
        file_put_contents($path, 'original');
        chmod($path, 0600);

        AtomicFile::write($path, 'updated');

        $perms = fileperms($path) & 0777;
        $this->assertSame(0600, $perms);
    }

    public function testWriteJsonCreatesValidJson(): void
    {
        $path = $this->tmpDir . '/test.json';
        $data = ['key' => 'value', 'number' => 42, 'nested' => ['a' => 1]];

        AtomicFile::writeJson($path, $data);

        $this->assertFileExists($path);
        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame($data, $decoded);
    }

    public function testWriteJsonPrettyPrinted(): void
    {
        $path = $this->tmpDir . '/test.json';
        AtomicFile::writeJson($path, ['k' => 'v']);

        $content = file_get_contents($path);
        // Pretty print adds newlines and indentation
        $this->assertStringContainsString("\n", $content);
        $this->assertStringContainsString('    ', $content);
    }

    public function testWriteJsonUnescapedSlashes(): void
    {
        $path = $this->tmpDir . '/test.json';
        AtomicFile::writeJson($path, ['url' => 'https://example.com/path']);

        $content = file_get_contents($path);
        $this->assertStringContainsString('https://example.com/path', $content);
        $this->assertStringNotContainsString('\\/', $content);
    }

    public function testReadJsonReturnsData(): void
    {
        $path = $this->tmpDir . '/test.json';
        $data = ['foo' => 'bar', 'baz' => [1, 2, 3]];
        file_put_contents($path, json_encode($data));

        $result = AtomicFile::readJson($path);
        $this->assertSame($data, $result);
    }

    public function testReadJsonReturnsEmptyForMissingFile(): void
    {
        $result = AtomicFile::readJson($this->tmpDir . '/nonexistent.json');
        $this->assertSame([], $result);
    }

    public function testReadJsonReturnsEmptyForInvalidJson(): void
    {
        $path = $this->tmpDir . '/bad.json';
        file_put_contents($path, 'not valid json');

        $result = AtomicFile::readJson($path);
        $this->assertSame([], $result);
    }

    public function testWriteNoTmpFileLeftBehind(): void
    {
        $path = $this->tmpDir . '/clean.txt';
        AtomicFile::write($path, 'data');

        $files = glob($this->tmpDir . '/*');
        $this->assertCount(1, $files);
        $this->assertSame($path, $files[0]);
    }
}
