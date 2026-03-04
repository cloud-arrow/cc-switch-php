<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Service\ProviderService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class ProviderEndpointsTest extends TestCase
{
    private PDO $pdo;
    private ProviderRepository $repo;
    private ProviderService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $medoo = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:',
            'pdo' => $this->pdo,
        ]);

        $this->repo = new ProviderRepository($medoo);
        $this->service = new ProviderService($this->repo);

        // Insert a test provider
        $this->repo->insert([
            'id' => 'test-prov',
            'app_type' => 'claude',
            'name' => 'Test Provider',
            'settings_config' => '{}',
            'meta' => '{}',
            'is_current' => 0,
            'in_failover_queue' => 0,
        ]);
    }

    public function testGetEndpointsEmptyInitially(): void
    {
        $endpoints = $this->service->getEndpoints('test-prov', AppType::Claude);

        $this->assertSame([], $endpoints);
    }

    public function testGetEndpointsForNonexistentProvider(): void
    {
        $endpoints = $this->service->getEndpoints('nonexistent', AppType::Claude);

        $this->assertSame([], $endpoints);
    }

    public function testAddEndpoint(): void
    {
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');

        $endpoints = $this->service->getEndpoints('test-prov', AppType::Claude);
        $this->assertCount(1, $endpoints);
        $this->assertSame('https://api.example.com/v1', $endpoints[0]['url']);
        $this->assertArrayHasKey('addedAt', $endpoints[0]);
    }

    public function testAddEndpointDeduplicates(): void
    {
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');

        $endpoints = $this->service->getEndpoints('test-prov', AppType::Claude);
        $this->assertCount(1, $endpoints);
    }

    public function testAddMultipleEndpoints(): void
    {
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.other.com/v2');

        $endpoints = $this->service->getEndpoints('test-prov', AppType::Claude);
        $this->assertCount(2, $endpoints);
    }

    public function testRemoveEndpoint(): void
    {
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.other.com/v2');

        $this->service->removeEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');

        $endpoints = $this->service->getEndpoints('test-prov', AppType::Claude);
        $this->assertCount(1, $endpoints);
        $this->assertSame('https://api.other.com/v2', $endpoints[0]['url']);
    }

    public function testRemoveNonexistentEndpoint(): void
    {
        $this->service->addEndpoint('test-prov', AppType::Claude, 'https://api.example.com/v1');
        $this->service->removeEndpoint('test-prov', AppType::Claude, 'https://nonexistent.com');

        $endpoints = $this->service->getEndpoints('test-prov', AppType::Claude);
        $this->assertCount(1, $endpoints);
    }

    public function testAddEndpointToNonexistentProviderThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider not found');

        $this->service->addEndpoint('nonexistent', AppType::Claude, 'https://api.example.com');
    }

    public function testRemoveEndpointFromNonexistentProviderThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider not found');

        $this->service->removeEndpoint('nonexistent', AppType::Claude, 'https://api.example.com');
    }
}
