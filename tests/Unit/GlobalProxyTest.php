<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\GlobalProxyService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class GlobalProxyTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private SettingsRepository $settingsRepo;
    private GlobalProxyService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $this->medoo = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:',
            'pdo' => $this->pdo,
        ]);

        $this->settingsRepo = new SettingsRepository($this->medoo);
        $this->service = new GlobalProxyService($this->settingsRepo);
    }

    // --- URL validation ---

    public function testValidHttpProxy(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->assertSame('http://127.0.0.1:7890', $this->service->getProxyUrl());
    }

    public function testValidHttpsProxy(): void
    {
        $this->service->setProxyUrl('https://proxy.example.com:8080');
        $this->assertSame('https://proxy.example.com:8080', $this->service->getProxyUrl());
    }

    public function testValidSocks5Proxy(): void
    {
        $this->service->setProxyUrl('socks5://127.0.0.1:1080');
        $this->assertSame('socks5://127.0.0.1:1080', $this->service->getProxyUrl());
    }

    public function testValidSocks5hProxy(): void
    {
        $this->service->setProxyUrl('socks5h://127.0.0.1:1080');
        $this->assertSame('socks5h://127.0.0.1:1080', $this->service->getProxyUrl());
    }

    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid proxy URL scheme');
        $this->service->setProxyUrl('ftp://127.0.0.1:21');
    }

    public function testMissingHostThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setProxyUrl('http://');
    }

    // --- get/set/clear ---

    public function testGetProxyUrlReturnsNullByDefault(): void
    {
        $this->assertNull($this->service->getProxyUrl());
    }

    public function testSetProxyUrlStoresInSettings(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->assertSame('http://127.0.0.1:7890', $this->settingsRepo->get('global_proxy_url'));
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

    // --- Guzzle proxy options ---

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

    // --- Scan local proxies ---

    public function testScanLocalProxiesReturnsExpectedPorts(): void
    {
        $results = $this->service->scanLocalProxies();
        $this->assertCount(8, $results);

        $ports = array_column($results, 'port');
        $this->assertContains(1080, $ports);
        $this->assertContains(7890, $ports);
        $this->assertContains(8080, $ports);
        $this->assertContains(10808, $ports);

        foreach ($results as $result) {
            $this->assertArrayHasKey('port', $result);
            $this->assertArrayHasKey('url', $result);
            $this->assertArrayHasKey('available', $result);
            $this->assertIsBool($result['available']);
            $this->assertStringStartsWith('http://127.0.0.1:', $result['url']);
        }
    }
}
