<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Provider health model representing a row in the provider_health table.
 */
class ProviderHealth
{
    public string $provider_id = '';
    public string $app_type = '';
    public int $is_healthy = 1;
    public int $consecutive_failures = 0;
    public ?string $last_success_at = null;
    public ?string $last_failure_at = null;
    public ?string $last_error = null;
    public string $updated_at = '';

    /**
     * Get the circuit breaker state based on health data.
     *
     * @return string "closed" (healthy), "open" (failing), or "half-open" (recovering)
     */
    public function circuitState(): string
    {
        if ($this->is_healthy) {
            return 'closed';
        }
        if ($this->consecutive_failures > 0 && $this->last_success_at !== null) {
            return 'half-open';
        }
        return 'open';
    }

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->provider_id = (string) ($row['provider_id'] ?? '');
        $model->app_type = (string) ($row['app_type'] ?? '');
        $model->is_healthy = (int) ($row['is_healthy'] ?? 1);
        $model->consecutive_failures = (int) ($row['consecutive_failures'] ?? 0);
        $model->last_success_at = $row['last_success_at'] ?? null;
        $model->last_failure_at = $row['last_failure_at'] ?? null;
        $model->last_error = $row['last_error'] ?? null;
        $model->updated_at = (string) ($row['updated_at'] ?? '');
        return $model;
    }
}
