<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\ClaudePluginService;
use PHPUnit\Framework\TestCase;

class ClaudePluginServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/claude_plugin_test_' . getmypid() . '_' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    private function createService(): ClaudePluginService
    {
        return new ClaudePluginService($this->tmpDir);
    }

    public function testGetConfigPath(): void
    {
        $service = $this->createService();
        $this->assertSame($this->tmpDir . '/config.json', $service->getConfigPath());
    }

    public function testIsAppliedReturnsFalseWhenNoFile(): void
    {
        $service = $this->createService();
        $this->assertFalse($service->isApplied());
    }

    public function testApplyCreatesConfigWithPrimaryApiKey(): void
    {
        $service = $this->createService();
        $changed = $service->apply();
        $this->assertTrue($changed);

        // Verify file contents
        $config = json_decode(file_get_contents($service->getConfigPath()), true);
        $this->assertSame('any', $config['primaryApiKey']);

        // isApplied should return true now
        $this->assertTrue($service->isApplied());
    }

    public function testApplyReturnsFalseWhenAlreadyApplied(): void
    {
        $service = $this->createService();
        $service->apply();
        $changed = $service->apply();
        $this->assertFalse($changed);
    }

    public function testApplyPreservesExistingFields(): void
    {
        // Write existing config with other fields
        $configPath = $this->tmpDir . '/config.json';
        file_put_contents($configPath, json_encode([
            'theme' => 'dark',
            'telemetry' => false,
        ]));

        $service = $this->createService();
        $service->apply();

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertSame('any', $config['primaryApiKey']);
        $this->assertSame('dark', $config['theme']);
        $this->assertFalse($config['telemetry']);
    }

    public function testClearRemovesPrimaryApiKey(): void
    {
        $service = $this->createService();
        $service->apply();
        $this->assertTrue($service->isApplied());

        $changed = $service->clear();
        $this->assertTrue($changed);
        $this->assertFalse($service->isApplied());

        // Verify file no longer has primaryApiKey
        $config = json_decode(file_get_contents($service->getConfigPath()), true);
        $this->assertArrayNotHasKey('primaryApiKey', $config);
    }

    public function testClearReturnsFalseWhenNotApplied(): void
    {
        $service = $this->createService();
        $changed = $service->clear();
        $this->assertFalse($changed);
    }

    public function testClearPreservesOtherFields(): void
    {
        $configPath = $this->tmpDir . '/config.json';
        file_put_contents($configPath, json_encode([
            'primaryApiKey' => 'any',
            'theme' => 'light',
        ]));

        $service = $this->createService();
        $service->clear();

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertArrayNotHasKey('primaryApiKey', $config);
        $this->assertSame('light', $config['theme']);
    }

    public function testGetStatus(): void
    {
        $service = $this->createService();

        // No file yet
        $status = $service->getStatus();
        $this->assertFalse($status['exists']);
        $this->assertFalse($status['applied']);
        $this->assertSame($this->tmpDir . '/config.json', $status['path']);

        // After apply
        $service->apply();
        $status = $service->getStatus();
        $this->assertTrue($status['exists']);
        $this->assertTrue($status['applied']);
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
