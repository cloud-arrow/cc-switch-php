<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Proxy\StreamHandler;
use CcSwitch\Service\GlobalProxyService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GlobalProxyService with real database persistence
 * and interaction with other components (StreamHandler proxy options).
 */
class GlobalProxyIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private SettingsRepository $settingsRepo;
    private GlobalProxyService $service;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'cc-switch-proxy-') . '.db';

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
        $this->settingsRepo = new SettingsRepository($this->medoo);
        $this->service = new GlobalProxyService($this->settingsRepo);
    }

    protected function tearDown(): void
    {
        unset($this->medoo, $this->pdo);
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testProxyOptionsInjectedIntoGuzzle(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');

        $options = $this->service->getGuzzleProxyOptions();
        $this->assertSame(['proxy' => 'http://127.0.0.1:7890'], $options);

        // Verify socks5 format
        $this->service->setProxyUrl('socks5://127.0.0.1:1080');
        $options = $this->service->getGuzzleProxyOptions();
        $this->assertSame(['proxy' => 'socks5://127.0.0.1:1080'], $options);
    }

    public function testProxyPersistence(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');

        // Create a new GlobalProxyService instance using the same database file
        $medoo2 = new Medoo(['type' => 'sqlite', 'database' => $this->dbPath]);
        $settingsRepo2 = new SettingsRepository($medoo2);
        $service2 = new GlobalProxyService($settingsRepo2);

        $this->assertSame('http://127.0.0.1:7890', $service2->getProxyUrl());
    }

    public function testClearProxyRemovesFromDB(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->assertSame('http://127.0.0.1:7890', $this->service->getProxyUrl());

        // Clear using null
        $this->service->setProxyUrl(null);
        $this->assertNull($this->service->getProxyUrl());

        // Verify at the DB level that the settings key is gone
        $value = $this->settingsRepo->get('global_proxy_url');
        $this->assertNull($value);

        // Guzzle options should be empty
        $options = $this->service->getGuzzleProxyOptions();
        $this->assertSame([], $options);
    }

    public function testStreamHandlerReceivesProxyOptions(): void
    {
        $streamHandler = new StreamHandler();

        // Set proxy options on StreamHandler (same flow as ProxyServer wiring)
        $this->service->setProxyUrl('http://127.0.0.1:8118');
        $proxyOptions = $this->service->getGuzzleProxyOptions();
        $streamHandler->setProxyOptions($proxyOptions);

        // We can verify indirectly by using reflection to check the stored options
        $reflection = new \ReflectionClass($streamHandler);
        $prop = $reflection->getProperty('proxyOptions');
        $prop->setAccessible(true);
        $stored = $prop->getValue($streamHandler);

        $this->assertSame(['proxy' => 'http://127.0.0.1:8118'], $stored);
    }

    public function testProxyUrlUpdateOverwritesPrevious(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->assertSame('http://127.0.0.1:7890', $this->service->getProxyUrl());

        $this->service->setProxyUrl('socks5://127.0.0.1:1080');
        $this->assertSame('socks5://127.0.0.1:1080', $this->service->getProxyUrl());

        // Only one entry should exist in settings
        $allSettings = $this->settingsRepo->getAll();
        $proxyKeys = array_filter(array_keys($allSettings), fn($k) => $k === 'global_proxy_url');
        $this->assertCount(1, $proxyKeys);
    }

    public function testClearWithEmptyStringRemovesFromDB(): void
    {
        $this->service->setProxyUrl('http://127.0.0.1:7890');
        $this->service->setProxyUrl('');

        $this->assertNull($this->service->getProxyUrl());
        $this->assertNull($this->settingsRepo->get('global_proxy_url'));
    }
}
