<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\GlobalProxyService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class GlobalProxyServiceTest extends TestCase
{
    private GlobalProxyService $service;
    private SettingsRepository $settingsRepo;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-gproxy-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $dbPath = $this->tmpDir . '/test.db';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->settingsRepo = new SettingsRepository($medoo);
        $this->service = new GlobalProxyService($this->settingsRepo);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testGetProxyUrlReturnsNullByDefault(): void
    {
        $this->assertNull($this->service->getProxyUrl());
    }

    public function testSetAndGetProxyUrl(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->assertSame('http://127.0.0.1:7890', $this->service->getProxyUrl());
    }

    public function testSetProxyUrlNullClears(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->service->setProxyUrl(null);
        $this->assertNull($this->service->getProxyUrl());
    }

    public function testSetProxyUrlEmptyStringClears(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->service->setProxyUrl('');
        $this->assertNull($this->service->getProxyUrl());
    }

    public function testSetProxyUrlValidatesScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid proxy URL scheme');
        $this->service->setProxyUrl('ftp://127.0.0.1:7890');
    }

    public function testSetProxyUrlRejectsInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // parse_url('http://') returns false, scheme becomes '' which fails scheme check
        $this->service->setProxyUrl('http://');
    }

    public function testSetProxyUrlAcceptsSocks5(): void
    {
        $this->service->setProxyUrl('socks5://127.0.0.1:1080');
        $this->assertSame('socks5://127.0.0.1:1080', $this->service->getProxyUrl());
    }

    public function testSetProxyUrlAcceptsSocks5h(): void
    {
        $this->service->setProxyUrl('socks5h://127.0.0.1:1080');
        $this->assertSame('socks5h://127.0.0.1:1080', $this->service->getProxyUrl());
    }

    public function testSetProxyUrlAcceptsHttps(): void
    {
        $this->service->setProxyUrl('https://proxy.example.com:8443');
        $this->assertSame('https://proxy.example.com:8443', $this->service->getProxyUrl());
    }

    public function testGetGuzzleProxyOptionsEmpty(): void
    {
        $options = $this->service->getGuzzleProxyOptions();
        $this->assertSame([], $options);
    }

    public function testGetGuzzleProxyOptionsWithProxy(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $options = $this->service->getGuzzleProxyOptions();
        $this->assertSame(['proxy' => 'http://127.0.0.1:7890'], $options);
    }

    public function testScanLocalProxies(): void
    {
        $results = $this->service->scanLocalProxies();
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertArrayHasKey('port', $result);
            $this->assertArrayHasKey('url', $result);
            $this->assertArrayHasKey('available', $result);
            $this->assertIsBool($result['available']);
            $this->assertStringStartsWith('http://127.0.0.1:', $result['url']);
        }
    }

    public function testTestProxyValidatesUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->testProxy('ftp://invalid');
    }

    public function testTestProxyWithInvalidProxy(): void
    {
        // This will fail to connect but should still return structured result
        $result = $this->service->testProxy('http://127.0.0.1:1');
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['results']);

        foreach ($result['results'] as $r) {
            $this->assertArrayHasKey('url', $r);
            $this->assertArrayHasKey('ok', $r);
            $this->assertFalse($r['ok']);
            $this->assertNotNull($r['error']);
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
