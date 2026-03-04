<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Repository\FailoverQueueRepository;
use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Proxy\CircuitBreaker;
use CcSwitch\Proxy\FailoverManager;
use PHPUnit\Framework\TestCase;

class FailoverManagerTest extends TestCase
{
    private FailoverManager $manager;
    private ProviderRepository $providerRepo;
    private FailoverQueueRepository $failoverRepo;
    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        $this->providerRepo = $this->createMock(ProviderRepository::class);
        $this->failoverRepo = $this->createMock(FailoverQueueRepository::class);

        $healthRepo = $this->createMock(HealthRepository::class);
        $configRepo = $this->createMock(ProxyConfigRepository::class);
        $configRepo->method('get')->willReturn(null);
        $this->circuitBreaker = new CircuitBreaker($healthRepo, $configRepo);

        $this->manager = new FailoverManager(
            $this->failoverRepo,
            $this->providerRepo,
            $this->circuitBreaker,
        );
    }

    public function testResolveReturnsCurrentProviderWhenHealthy(): void
    {
        $this->providerRepo->method('getCurrent')->willReturn([
            'id' => 'p1',
            'app_type' => 'claude',
            'name' => 'Provider 1',
            'settings_config' => '{}',
            'meta' => '{}',
        ]);

        $provider = $this->manager->resolve('claude');

        $this->assertNotNull($provider);
        $this->assertSame('p1', $provider->id);
    }

    public function testResolveReturnsNullWhenNoCurrentProvider(): void
    {
        $this->providerRepo->method('getCurrent')->willReturn(null);

        $this->assertNull($this->manager->resolve('claude'));
    }

    public function testResolveSkipsUnhealthyProviderAndReturnsFailover(): void
    {
        // Set up current provider
        $this->providerRepo->method('getCurrent')->willReturn([
            'id' => 'p1',
            'app_type' => 'claude',
            'name' => 'Provider 1',
            'settings_config' => '{}',
            'meta' => '{}',
        ]);

        // Trip circuit breaker for p1
        for ($i = 0; $i < 4; $i++) {
            $this->circuitBreaker->recordFailure('p1', 'claude', 'error');
        }

        // Set up failover queue
        $this->failoverRepo->method('list')->willReturn([
            [
                'id' => 'p2',
                'app_type' => 'claude',
                'name' => 'Provider 2',
                'settings_config' => '{}',
                'meta' => '{}',
            ],
        ]);

        $this->providerRepo->expects($this->once())
            ->method('switchTo')
            ->with('p2', 'claude');

        $provider = $this->manager->resolve('claude');

        $this->assertNotNull($provider);
        $this->assertSame('p2', $provider->id);
    }

    public function testResolveReturnsNullWhenAllUnavailable(): void
    {
        $this->providerRepo->method('getCurrent')->willReturn([
            'id' => 'p1',
            'app_type' => 'claude',
            'name' => 'Provider 1',
            'settings_config' => '{}',
            'meta' => '{}',
        ]);

        // Trip circuit breaker for p1
        for ($i = 0; $i < 4; $i++) {
            $this->circuitBreaker->recordFailure('p1', 'claude', 'error');
        }

        // Trip circuit breaker for p2
        for ($i = 0; $i < 4; $i++) {
            $this->circuitBreaker->recordFailure('p2', 'claude', 'error');
        }

        $this->failoverRepo->method('list')->willReturn([
            [
                'id' => 'p2',
                'app_type' => 'claude',
                'name' => 'Provider 2',
                'settings_config' => '{}',
                'meta' => '{}',
            ],
        ]);

        $provider = $this->manager->resolve('claude');
        $this->assertNull($provider);
    }

    public function testGetQueueStatus(): void
    {
        $this->failoverRepo->method('list')->willReturn([
            [
                'id' => 'p1',
                'app_type' => 'claude',
                'name' => 'Provider 1',
                'settings_config' => '{}',
                'meta' => '{}',
            ],
            [
                'id' => 'p2',
                'app_type' => 'claude',
                'name' => 'Provider 2',
                'settings_config' => '{}',
                'meta' => '{}',
            ],
        ]);

        // Record some activity to generate status
        $this->circuitBreaker->recordSuccess('p1', 'claude');

        $status = $this->manager->getQueueStatus('claude');

        $this->assertCount(2, $status);
        $this->assertSame('p1', $status[0]['provider']->id);
        $this->assertSame('closed', $status[0]['circuit_state']);
        $this->assertSame('p2', $status[1]['provider']->id);
        $this->assertSame('closed', $status[1]['circuit_state']);
    }
}
