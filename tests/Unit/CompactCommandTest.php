<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\App;
use CcSwitch\Command\CompactCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CompactCommandTest extends TestCase
{
    private string $tmpDir;
    private string $origHome;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/compact_test_' . getmypid() . '_' . hrtime(true);
        $this->origHome = getenv('HOME') ?: '';
        putenv("HOME={$this->tmpDir}");
        $_SERVER['HOME'] = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        putenv("HOME={$this->origHome}");
        $_SERVER['HOME'] = $this->origHome;
        $this->recursiveDelete($this->tmpDir);
    }

    public function testCompactCommandExists(): void
    {
        $app = App::boot();
        $command = new CompactCommand($app);
        $this->assertSame('db:compact', $command->getName());
    }

    public function testCompactCommandRuns(): void
    {
        $app = App::boot();
        $command = new CompactCommand($app);

        $consoleApp = new Application();
        $consoleApp->add($command);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('compacted', strtolower($tester->getDisplay()));
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
