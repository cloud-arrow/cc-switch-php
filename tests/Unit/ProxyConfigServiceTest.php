<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Proxy\CircuitBreaker;
use CcSwitch\Service\ProxyConfigService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class ProxyConfigServiceTest extends TestCase
{
    private ProxyConfigService $service;
    private CircuitBreaker $circuitBreaker;
    private HealthRepository $healthRepo;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-proxyconf-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $dbPath = $this->tmpDir . '/test.db';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $configRepo = new ProxyConfigRepository($medoo);
        $this->healthRepo = new HealthRepository($medoo);
        $this->circuitBreaker = new CircuitBreaker($this->healthRepo, $configRepo);
        $this->service = new ProxyConfigService($configRepo, $this->healthRepo, $this->circuitBreaker);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testGetConfigReturnsSeededValues(): void
    {
        // Migration 009 seeds claude with max_retries=6
        $config = $this->service->getConfig('claude');
        $this->assertSame('claude', $config->app_type);
        $this->assertSame(15721, $config->listen_port);
        $this->assertSame('127.0.0.1', $config->listen_address);
        $this->assertSame(6, $config->max_retries);
    }

    public function testUpdateConfig(): void
    {
        // Migration already seeds claude row
        $this->service->updateConfig('claude', ['max_retries' => 5]);
        $config = $this->service->getConfig('claude');
        $this->assertSame(5, $config->max_retries);
    }

    public function testGetHealthStatus(): void
    {
        $status = $this->service->getHealthStatus('claude');
        $this->assertArrayHasKey('config', $status);
        $this->assertArrayHasKey('circuit_breaker', $status);
        $this->assertSame('claude', $status['config']->app_type);
        $this->assertIsArray($status['circuit_breaker']);
    }

    public function testGetHealthStatusWithCircuitBreakerData(): void
    {
        // Record some failures to populate circuit breaker state
        $this->circuitBreaker->recordFailure('prov-1', 'claude', 'test error');
        $this->circuitBreaker->recordFailure('prov-1', 'claude', 'test error');

        $status = $this->service->getHealthStatus('claude');
        $this->assertNotEmpty($status['circuit_breaker']);
        $this->assertSame('prov-1', $status['circuit_breaker'][0]['provider_id']);
    }

    public function testResetHealth(): void
    {
        // Record failures
        $this->circuitBreaker->recordFailure('prov-1', 'claude', 'error 1');
        $this->circuitBreaker->recordFailure('prov-1', 'claude', 'error 2');
        $this->circuitBreaker->recordFailure('prov-1', 'claude', 'error 3');
        $this->circuitBreaker->recordFailure('prov-1', 'claude', 'error 4');

        $status = $this->service->getHealthStatus('claude');
        $this->assertNotEmpty($status['circuit_breaker']);

        $this->service->resetHealth('claude');

        $status = $this->service->getHealthStatus('claude');
        foreach ($status['circuit_breaker'] as $cb) {
            $this->assertSame('closed', $cb['state']);
            $this->assertSame(0, $cb['failures']);
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
