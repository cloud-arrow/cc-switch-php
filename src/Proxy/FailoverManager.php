<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Database\Repository\FailoverQueueRepository;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\Provider;

/**
 * Manages provider failover: resolves the current provider, checks circuit
 * breaker health, and falls back to the next available provider in the queue.
 */
class FailoverManager
{
    public function __construct(
        private readonly FailoverQueueRepository $failoverRepo,
        private readonly ProviderRepository $providerRepo,
        private readonly CircuitBreaker $circuitBreaker,
    ) {
    }

    /**
     * Resolve the provider to use for a request.
     *
     * Returns the current provider if healthy, otherwise tries failover queue.
     * Returns null if all providers are unavailable (503 scenario).
     */
    public function resolve(string $appType): ?Provider
    {
        // Get the current provider
        $currentRow = $this->providerRepo->getCurrent($appType);
        if ($currentRow === null) {
            return null;
        }

        $current = Provider::fromRow($currentRow);

        // Check circuit breaker
        if ($this->circuitBreaker->canPass($current->id, $appType)) {
            return $current;
        }

        // Current provider is unhealthy, try failover
        return $this->next($appType);
    }

    /**
     * Get the next available provider from the failover queue.
     *
     * Skips providers whose circuit breaker is open.
     */
    public function next(string $appType): ?Provider
    {
        $queue = $this->failoverRepo->list($appType);
        foreach ($queue as $row) {
            $provider = Provider::fromRow($row);
            if ($this->circuitBreaker->canPass($provider->id, $appType)) {
                // Switch to this provider
                $this->switchTo($appType, $provider->id);
                return $provider;
            }
        }

        // All providers in queue are unavailable
        return null;
    }

    /**
     * Switch the current provider for an app type.
     */
    public function switchTo(string $appType, string $providerId): void
    {
        $this->providerRepo->switchTo($providerId, $appType);
    }

    /**
     * Get all providers in the failover queue with their health status.
     *
     * @return array<int, array{provider: Provider, circuit_state: string}>
     */
    public function getQueueStatus(string $appType): array
    {
        $queue = $this->failoverRepo->list($appType);
        $status = [];
        $cbStatus = $this->circuitBreaker->getStatus($appType);
        $cbMap = [];
        foreach ($cbStatus as $s) {
            $cbMap[$s['provider_id']] = $s['state'];
        }

        foreach ($queue as $row) {
            $provider = Provider::fromRow($row);
            $status[] = [
                'provider' => $provider,
                'circuit_state' => $cbMap[$provider->id] ?? 'closed',
            ];
        }
        return $status;
    }
}
