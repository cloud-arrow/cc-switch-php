<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Request log model representing a row in the proxy_request_logs table.
 */
class RequestLog
{
    public string $request_id = '';
    public string $provider_id = '';
    public string $app_type = '';
    public string $model = '';
    public ?string $request_model = null;
    public int $input_tokens = 0;
    public int $output_tokens = 0;
    public int $cache_read_tokens = 0;
    public int $cache_creation_tokens = 0;
    public string $input_cost_usd = '0';
    public string $output_cost_usd = '0';
    public string $cache_read_cost_usd = '0';
    public string $cache_creation_cost_usd = '0';
    public string $total_cost_usd = '0';
    public int $latency_ms = 0;
    public ?int $first_token_ms = null;
    public ?int $duration_ms = null;
    public int $status_code = 0;
    public ?string $error_message = null;
    public ?string $session_id = null;
    public ?string $provider_type = null;
    public int $is_streaming = 0;
    public string $cost_multiplier = '1.0';
    public int $created_at = 0;

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->request_id = (string) ($row['request_id'] ?? '');
        $model->provider_id = (string) ($row['provider_id'] ?? '');
        $model->app_type = (string) ($row['app_type'] ?? '');
        $model->model = (string) ($row['model'] ?? '');
        $model->request_model = $row['request_model'] ?? null;
        $model->input_tokens = (int) ($row['input_tokens'] ?? 0);
        $model->output_tokens = (int) ($row['output_tokens'] ?? 0);
        $model->cache_read_tokens = (int) ($row['cache_read_tokens'] ?? 0);
        $model->cache_creation_tokens = (int) ($row['cache_creation_tokens'] ?? 0);
        $model->input_cost_usd = (string) ($row['input_cost_usd'] ?? '0');
        $model->output_cost_usd = (string) ($row['output_cost_usd'] ?? '0');
        $model->cache_read_cost_usd = (string) ($row['cache_read_cost_usd'] ?? '0');
        $model->cache_creation_cost_usd = (string) ($row['cache_creation_cost_usd'] ?? '0');
        $model->total_cost_usd = (string) ($row['total_cost_usd'] ?? '0');
        $model->latency_ms = (int) ($row['latency_ms'] ?? 0);
        $model->first_token_ms = isset($row['first_token_ms']) ? (int) $row['first_token_ms'] : null;
        $model->duration_ms = isset($row['duration_ms']) ? (int) $row['duration_ms'] : null;
        $model->status_code = (int) ($row['status_code'] ?? 0);
        $model->error_message = $row['error_message'] ?? null;
        $model->session_id = $row['session_id'] ?? null;
        $model->provider_type = $row['provider_type'] ?? null;
        $model->is_streaming = (int) ($row['is_streaming'] ?? 0);
        $model->cost_multiplier = (string) ($row['cost_multiplier'] ?? '1.0');
        $model->created_at = (int) ($row['created_at'] ?? 0);
        return $model;
    }
}
