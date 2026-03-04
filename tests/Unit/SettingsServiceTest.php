<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\SettingsService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase
{
    private SettingsService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-settings-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $dbPath = $this->tmpDir . '/test.db';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $repo = new SettingsRepository($medoo);
        $this->service = new SettingsService($repo);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->service->get('nonexistent'));
    }

    public function testSetAndGet(): void
    {
        $this->service->set('theme', 'dark');
        $this->assertSame('dark', $this->service->get('theme'));
    }

    public function testSetOverwrites(): void
    {
        $this->service->set('theme', 'light');
        $this->service->set('theme', 'dark');
        $this->assertSame('dark', $this->service->get('theme'));
    }

    public function testDelete(): void
    {
        $this->service->set('to_delete', 'value');
        $this->assertSame('value', $this->service->get('to_delete'));

        $this->service->delete('to_delete');
        $this->assertNull($this->service->get('to_delete'));
    }

    public function testGetAll(): void
    {
        $this->service->set('key1', 'val1');
        $this->service->set('key2', 'val2');

        $all = $this->service->getAll();
        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
        $this->assertSame('val1', $all['key1']);
        $this->assertSame('val2', $all['key2']);
    }

    public function testGetSettings(): void
    {
        $this->service->set('theme', 'dark');
        $this->service->set('language', 'en');
        $this->service->set('proxy_port', '9999');
        $this->service->set('web_port', '3000');

        $settings = $this->service->getSettings();
        $this->assertSame('dark', $settings->theme);
        $this->assertSame('en', $settings->language);
        $this->assertSame('9999', $settings->proxyPort);
        $this->assertSame('3000', $settings->webPort);
    }

    public function testUpdateAll(): void
    {
        $this->service->updateAll([
            'theme' => 'dark',
            'language' => 'ja',
        ]);

        $this->assertSame('dark', $this->service->get('theme'));
        $this->assertSame('ja', $this->service->get('language'));
    }

    public function testGetProxyPortDefault(): void
    {
        $this->assertSame(15721, $this->service->getProxyPort());
    }

    public function testGetProxyPortCustom(): void
    {
        $this->service->set('proxy_port', '8888');
        $this->assertSame(8888, $this->service->getProxyPort());
    }

    public function testGetWebPortDefault(): void
    {
        $this->assertSame(8080, $this->service->getWebPort());
    }

    public function testGetWebPortCustom(): void
    {
        $this->service->set('web_port', '3000');
        $this->assertSame(3000, $this->service->getWebPort());
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
