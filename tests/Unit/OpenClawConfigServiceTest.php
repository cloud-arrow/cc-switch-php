<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\OpenClawConfigService;
use PHPUnit\Framework\TestCase;

class OpenClawConfigServiceTest extends TestCase
{
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-openclaw-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome, 0755, true);
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

    public function testGetDefaultModelReturnsEmptyWhenNoFile(): void
    {
        $service = new OpenClawConfigService();
        $this->assertSame([], $service->getDefaultModel());
    }

    public function testSetAndGetDefaultModel(): void
    {
        $service = new OpenClawConfigService();
        $model = ['provider' => 'anthropic', 'name' => 'claude-3-opus'];
        $service->setDefaultModel($model);

        $result = $service->getDefaultModel();
        $this->assertSame($model, $result);
    }

    public function testGetModelCatalogReturnsEmptyWhenNoFile(): void
    {
        $service = new OpenClawConfigService();
        $this->assertSame([], $service->getModelCatalog());
    }

    public function testSetAndGetModelCatalog(): void
    {
        $service = new OpenClawConfigService();
        $catalog = [
            ['id' => 'model-1', 'name' => 'Model One'],
            ['id' => 'model-2', 'name' => 'Model Two'],
        ];
        $service->setModelCatalog($catalog);

        $result = $service->getModelCatalog();
        $this->assertSame($catalog, $result);
    }

    public function testGetAgentsDefaultsReturnsEmptyWhenNoFile(): void
    {
        $service = new OpenClawConfigService();
        $this->assertSame([], $service->getAgentsDefaults());
    }

    public function testSetAndGetAgentsDefaults(): void
    {
        $service = new OpenClawConfigService();
        $defaults = ['maxTokens' => 4096, 'temperature' => 0.7];
        $service->setAgentsDefaults($defaults);

        $result = $service->getAgentsDefaults();
        $this->assertSame($defaults, $result);
    }

    public function testPreservesOtherConfigKeys(): void
    {
        $configPath = $this->tmpHome . '/.openclaw/config.json';
        file_put_contents($configPath, json_encode([
            'theme' => 'dark',
            'agents' => ['defaults' => ['model' => ['name' => 'old']]],
        ]));

        $service = new OpenClawConfigService();
        $service->setDefaultModel(['name' => 'new']);

        $config = json_decode(file_get_contents($configPath), true);
        $this->assertSame('dark', $config['theme']);
        $this->assertSame(['name' => 'new'], $config['agents']['defaults']['model']);
    }

    public function testHandlesJsoncComments(): void
    {
        $configPath = $this->tmpHome . '/.openclaw/config.json';
        file_put_contents($configPath, "{\n  // This is a comment\n  \"agents\": {\n    \"defaults\": {\n      \"model\": {\"name\": \"test\"}\n    }\n  }\n}");

        $service = new OpenClawConfigService();
        $result = $service->getDefaultModel();
        $this->assertSame(['name' => 'test'], $result);
    }

    public function testCreatesDirectoryIfMissing(): void
    {
        $this->recursiveDelete($this->tmpHome . '/.openclaw');

        $service = new OpenClawConfigService();
        $service->setDefaultModel(['name' => 'test']);

        $configPath = $this->tmpHome . '/.openclaw/config.json';
        $this->assertFileExists($configPath);
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
