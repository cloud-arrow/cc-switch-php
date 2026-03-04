<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Model\ProxyConfig;

/**
 * Circuit breaker state machine for provider health management.
 *
 * States: closed (healthy) -> open (failing) -> half_open (recovering) -> closed
 *
 * Transitions:
 *   closed -> open:      consecutive_failures >= threshold OR error_rate > threshold
 *   open -> half_open:   time since opened > timeout_seconds
 *   half_open -> closed: consecutive_successes >= success_threshold
 *   half_open -> open:   any failure
 */
class CircuitBreaker
{
    /** @var array<string, array{state: string, failures: int, successes: int, total: int, failed: int, opened_at: ?float}> */
    private array $states = [];

    public function __construct(
        private readonly HealthRepository $healthRepo,
        private readonly ProxyConfigRepository $configRepo,
    ) {
    }

    /**
     * Check if a request can pass through to the provider.
     */
    public function canPass(string $providerId, string $appType): bool
    {
        $state = $this->getState($providerId, $appType);

        switch ($state['state']) {
            case 'closed':
                return true;

            case 'open':
                $config = $this->getConfig($appType);
                if ($state['opened_at'] !== null) {
                    $elapsed = microtime(true) - $state['opened_at'];
                    if ($elapsed >= $config->circuit_timeout_seconds) {
                        $this->transitionToHalfOpen($providerId, $appType);
                        return true;
                    }
                }
                return false;

            case 'half_open':
                return true;

            default:
                return true;
        }
    }

    /**
     * Record a successful request.
     */
    public function recordSuccess(string $providerId, string $appType): void
    {
        $state = &$this->getStateRef($providerId, $appType);
        $config = $this->getConfig($appType);

        $state['failures'] = 0;
        $state['total']++;

        if ($state['state'] === 'half_open') {
            $state['successes']++;
            if ($state['successes'] >= $config->circuit_success_threshold) {
                $this->transitionToClosed($providerId, $appType);
            }
        }

        // Update health record
        $this->healthRepo->upsert([
            'provider_id' => $providerId,
            'app_type' => $appType,
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'last_success_at' => date('Y-m-d\TH:i:s\Z'),
            'last_error' => null,
            'updated_at' => date('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Record a failed request.
     */
    public function recordFailure(string $providerId, string $appType, string $error): void
    {
        $state = &$this->getStateRef($providerId, $appType);
        $config = $this->getConfig($appType);

        $state['failures']++;
        $state['total']++;
        $state['failed']++;
        $state['successes'] = 0;

        switch ($state['state']) {
            case 'half_open':
                // Any failure in half_open -> open immediately
                $this->transitionToOpen($providerId, $appType);
                break;

            case 'closed':
                // Check consecutive failure threshold
                if ($state['failures'] >= $config->circuit_failure_threshold) {
                    $this->transitionToOpen($providerId, $appType);
                } else {
                    // Check error rate
                    if ($state['total'] >= $config->circuit_min_requests) {
                        $errorRate = $state['failed'] / $state['total'];
                        if ($errorRate >= $config->circuit_error_rate_threshold) {
                            $this->transitionToOpen($providerId, $appType);
                        }
                    }
                }
                break;
        }

        // Update health record
        $this->healthRepo->upsert([
            'provider_id' => $providerId,
            'app_type' => $appType,
            'is_healthy' => $state['state'] === 'closed' ? 1 : 0,
            'consecutive_failures' => $state['failures'],
            'last_failure_at' => date('Y-m-d\TH:i:s\Z'),
            'last_error' => mb_substr($error, 0, 500),
            'updated_at' => date('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Periodic check: transition open -> half_open if timeout expired.
     */
    public function periodicCheck(): void
    {
        foreach ($this->states as $key => $state) {
            if ($state['state'] !== 'open') {
                continue;
            }
            [$providerId, $appType] = explode(':', $key, 2);
            $config = $this->getConfig($appType);

            if ($state['opened_at'] !== null) {
                $elapsed = microtime(true) - $state['opened_at'];
                if ($elapsed >= $config->circuit_timeout_seconds) {
                    $this->transitionToHalfOpen($providerId, $appType);
                }
            }
        }
    }

    /**
     * Get health status of all providers for an app type.
     *
     * @return array<int, array{provider_id: string, state: string, failures: int, successes: int}>
     */
    public function getStatus(string $appType): array
    {
        $result = [];
        foreach ($this->states as $key => $state) {
            [$pid, $at] = explode(':', $key, 2);
            if ($at === $appType) {
                $result[] = [
                    'provider_id' => $pid,
                    'state' => $state['state'],
                    'failures' => $state['failures'],
                    'successes' => $state['successes'],
                    'total_requests' => $state['total'],
                    'failed_requests' => $state['failed'],
                ];
            }
        }
        return $result;
    }

    /**
     * Reset the circuit breaker for a provider.
     */
    public function reset(string $providerId, string $appType): void
    {
        $this->transitionToClosed($providerId, $appType);
        $this->healthRepo->upsert([
            'provider_id' => $providerId,
            'app_type' => $appType,
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'last_error' => null,
            'updated_at' => date('Y-m-d\TH:i:s\Z'),
        ]);
    }

    private function stateKey(string $providerId, string $appType): string
    {
        return $providerId . ':' . $appType;
    }

    private function getState(string $providerId, string $appType): array
    {
        $key = $this->stateKey($providerId, $appType);
        if (!isset($this->states[$key])) {
            $this->states[$key] = [
                'state' => 'closed',
                'failures' => 0,
                'successes' => 0,
                'total' => 0,
                'failed' => 0,
                'opened_at' => null,
            ];
        }
        return $this->states[$key];
    }

    private function &getStateRef(string $providerId, string $appType): array
    {
        $key = $this->stateKey($providerId, $appType);
        if (!isset($this->states[$key])) {
            $this->states[$key] = [
                'state' => 'closed',
                'failures' => 0,
                'successes' => 0,
                'total' => 0,
                'failed' => 0,
                'opened_at' => null,
            ];
        }
        return $this->states[$key];
    }

    private function transitionToOpen(string $providerId, string $appType): void
    {
        $key = $this->stateKey($providerId, $appType);
        if (!isset($this->states[$key])) {
            $this->states[$key] = ['state' => 'closed', 'failures' => 0, 'successes' => 0, 'total' => 0, 'failed' => 0, 'opened_at' => null];
        }
        $this->states[$key]['state'] = 'open';
        $this->states[$key]['opened_at'] = microtime(true);
        $this->states[$key]['failures'] = 0;
        $this->states[$key]['successes'] = 0;
    }

    private function transitionToHalfOpen(string $providerId, string $appType): void
    {
        $key = $this->stateKey($providerId, $appType);
        if (!isset($this->states[$key]) || $this->states[$key]['state'] !== 'open') {
            return;
        }
        $this->states[$key]['state'] = 'half_open';
        $this->states[$key]['successes'] = 0;
    }

    private function transitionToClosed(string $providerId, string $appType): void
    {
        $key = $this->stateKey($providerId, $appType);
        $this->states[$key] = [
            'state' => 'closed',
            'failures' => 0,
            'successes' => 0,
            'total' => 0,
            'failed' => 0,
            'opened_at' => null,
        ];
    }

    private function getConfig(string $appType): ProxyConfig
    {
        $row = $this->configRepo->get($appType);
        if ($row !== null) {
            return ProxyConfig::fromRow($row);
        }
        // Return defaults
        return new ProxyConfig();
    }
}
