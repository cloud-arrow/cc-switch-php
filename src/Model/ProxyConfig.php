<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Proxy config model representing a row in the proxy_config table.
 */
class ProxyConfig
{
    public string $app_type = '';
    public int $proxy_enabled = 0;
    public string $listen_address = '127.0.0.1';
    public int $listen_port = 15721;
    public int $enable_logging = 1;
    public int $enabled = 0;
    public int $auto_failover_enabled = 0;
    public int $max_retries = 3;
    public int $streaming_first_byte_timeout = 60;
    public int $streaming_idle_timeout = 120;
    public int $non_streaming_timeout = 600;
    public int $circuit_failure_threshold = 4;
    public int $circuit_success_threshold = 2;
    public int $circuit_timeout_seconds = 60;
    public float $circuit_error_rate_threshold = 0.6;
    public int $circuit_min_requests = 10;
    public string $default_cost_multiplier = '1';
    public string $pricing_model_source = 'response';
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->app_type = (string) ($row['app_type'] ?? '');
        $model->proxy_enabled = (int) ($row['proxy_enabled'] ?? 0);
        $model->listen_address = (string) ($row['listen_address'] ?? '127.0.0.1');
        $model->listen_port = (int) ($row['listen_port'] ?? 15721);
        $model->enable_logging = (int) ($row['enable_logging'] ?? 1);
        $model->enabled = (int) ($row['enabled'] ?? 0);
        $model->auto_failover_enabled = (int) ($row['auto_failover_enabled'] ?? 0);
        $model->max_retries = (int) ($row['max_retries'] ?? 3);
        $model->streaming_first_byte_timeout = (int) ($row['streaming_first_byte_timeout'] ?? 60);
        $model->streaming_idle_timeout = (int) ($row['streaming_idle_timeout'] ?? 120);
        $model->non_streaming_timeout = (int) ($row['non_streaming_timeout'] ?? 600);
        $model->circuit_failure_threshold = (int) ($row['circuit_failure_threshold'] ?? 4);
        $model->circuit_success_threshold = (int) ($row['circuit_success_threshold'] ?? 2);
        $model->circuit_timeout_seconds = (int) ($row['circuit_timeout_seconds'] ?? 60);
        $model->circuit_error_rate_threshold = (float) ($row['circuit_error_rate_threshold'] ?? 0.6);
        $model->circuit_min_requests = (int) ($row['circuit_min_requests'] ?? 10);
        $model->default_cost_multiplier = (string) ($row['default_cost_multiplier'] ?? '1');
        $model->pricing_model_source = (string) ($row['pricing_model_source'] ?? 'response');
        $model->created_at = $row['created_at'] ?? null;
        $model->updated_at = $row['updated_at'] ?? null;
        return $model;
    }
}
