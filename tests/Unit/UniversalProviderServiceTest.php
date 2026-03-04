<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\UniversalProviderRepository;
use CcSwitch\Model\UniversalProvider;
use CcSwitch\Service\UniversalProviderService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class UniversalProviderServiceTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private UniversalProviderRepository $repo;
    private UniversalProviderService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-uprov-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $dbPath = $this->tmpDir . '/test.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->repo = new UniversalProviderRepository($this->medoo);
        $this->service = new UniversalProviderService($this->repo);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testListReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->list());
    }

    public function testAddAndGet(): void
    {
        $provider = new UniversalProvider();
        $provider->id = 'test-id';
        $provider->name = 'Test Provider';
        $provider->provider_type = 'custom_gateway';
        $provider->base_url = 'https://api.example.com';
        $provider->api_key = 'sk-test';

        $this->service->add($provider);

        $result = $this->service->get('test-id');
        $this->assertNotNull($result);
        $this->assertSame('Test Provider', $result->name);
        $this->assertSame('custom_gateway', $result->provider_type);
        $this->assertSame('https://api.example.com', $result->base_url);
    }

    public function testAddGeneratesIdWhenEmpty(): void
    {
        $provider = new UniversalProvider();
        $provider->name = 'Auto ID Provider';
        $provider->provider_type = 'custom_gateway';
        $provider->base_url = 'https://api.example.com';
        $provider->api_key = 'key';

        $this->service->add($provider);

        $list = $this->service->list();
        $this->assertCount(1, $list);
        $this->assertNotEmpty($list[0]->id);
    }

    public function testUpdate(): void
    {
        $provider = new UniversalProvider();
        $provider->id = 'upd-id';
        $provider->name = 'Original';
        $provider->provider_type = 'custom_gateway';
        $provider->base_url = 'https://original.com';
        $provider->api_key = 'key';

        $this->service->add($provider);

        $provider->name = 'Updated';
        $provider->base_url = 'https://updated.com';
        $this->service->update($provider);

        $result = $this->service->get('upd-id');
        $this->assertSame('Updated', $result->name);
        $this->assertSame('https://updated.com', $result->base_url);
    }

    public function testDelete(): void
    {
        $provider = new UniversalProvider();
        $provider->id = 'del-id';
        $provider->name = 'Delete Me';
        $provider->provider_type = 'custom_gateway';
        $provider->base_url = 'https://example.com';
        $provider->api_key = 'key';

        $this->service->add($provider);
        $this->assertNotNull($this->service->get('del-id'));

        $this->service->delete('del-id');
        $this->assertNull($this->service->get('del-id'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->service->get('nonexistent'));
    }

    public function testAddFromPreset(): void
    {
        $preset = [
            'name' => 'Preset Provider',
            'providerType' => 'openrouter',
            'defaultApps' => ['claude' => true, 'codex' => false],
            'defaultModels' => ['gpt-4' => true],
            'websiteUrl' => 'https://preset.com',
        ];

        $provider = $this->service->addFromPreset($preset, 'https://api.preset.com', 'sk-preset');

        $this->assertNotEmpty($provider->id);
        $this->assertSame('Preset Provider', $provider->name);
        $this->assertSame('openrouter', $provider->provider_type);
        $this->assertSame('https://api.preset.com', $provider->base_url);
        $this->assertSame('sk-preset', $provider->api_key);
        $this->assertSame('https://preset.com', $provider->website_url);

        // Verify persisted
        $stored = $this->service->get($provider->id);
        $this->assertNotNull($stored);
        $this->assertSame('Preset Provider', $stored->name);
    }

    public function testAddFromPresetWithCustomName(): void
    {
        $preset = ['name' => 'Default Name'];
        $provider = $this->service->addFromPreset($preset, 'https://api.com', 'key', 'Custom Name');
        $this->assertSame('Custom Name', $provider->name);
    }

    public function testListMultipleProviders(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $p = new UniversalProvider();
            $p->id = "prov-{$i}";
            $p->name = "Provider {$i}";
            $p->provider_type = 'custom_gateway';
            $p->base_url = "https://api{$i}.com";
            $p->api_key = "key{$i}";
            $this->service->add($p);
        }

        $list = $this->service->list();
        $this->assertCount(3, $list);
    }

    public function testLoadPresets(): void
    {
        $presets = UniversalProviderService::loadPresets();
        // Just verify it returns an array without error
        $this->assertIsArray($presets);
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
