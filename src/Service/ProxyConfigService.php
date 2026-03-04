<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Model\ProxyConfig;
use CcSwitch\Proxy\CircuitBreaker;

/**
 * Service for managing proxy configuration and health status.
 */
class ProxyConfigService
{
    public function __construct(
        private readonly ProxyConfigRepository $configRepo,
        private readonly HealthRepository $healthRepo,
        private readonly CircuitBreaker $circuitBreaker,
    ) {
    }

    /**
     * Get the proxy configuration for an app type.
     * Returns defaults if no configuration exists.
     */
    public function getConfig(string $app): ProxyConfig
    {
        $row = $this->configRepo->get($app);
        if ($row !== null) {
            return ProxyConfig::fromRow($row);
        }
        $config = new ProxyConfig();
        $config->app_type = $app;
        return $config;
    }

    /**
     * Update proxy configuration for an app type.
     *
     * @param array<string, mixed> $data Fields to update
     */
    public function updateConfig(string $app, array $data): void
    {
        $this->configRepo->update($app, $data);
    }

    /**
     * Get health status for all providers of an app type.
     *
     * @return array{
     *   config: ProxyConfig,
     *   circuit_breaker: array<int, array{provider_id: string, state: string, failures: int, successes: int}>,
     * }
     */
    public function getHealthStatus(string $app): array
    {
        return [
            'config' => $this->getConfig($app),
            'circuit_breaker' => $this->circuitBreaker->getStatus($app),
        ];
    }

    /**
     * Reset all health records and circuit breakers for an app type.
     */
    public function resetHealth(string $app): void
    {
        $this->healthRepo->resetAll($app);
        // Circuit breaker in-memory state is managed per-provider
        foreach ($this->circuitBreaker->getStatus($app) as $status) {
            $this->circuitBreaker->reset($status['provider_id'], $app);
        }
    }
}
