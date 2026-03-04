<?php

declare(strict_types=1);

namespace CcSwitch\Model;

class StreamCheckResult
{
    /** @var string 'operational', 'degraded', or 'failed' */
    public string $status = 'failed';
    public bool $success = false;
    public string $message = '';
    public ?int $response_time_ms = null;
    public ?int $http_status = null;
    public string $model_used = '';
    public int $tested_at = 0;
    public int $retry_count = 0;

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'success' => $this->success,
            'message' => $this->message,
            'response_time_ms' => $this->response_time_ms,
            'http_status' => $this->http_status,
            'model_used' => $this->model_used,
            'tested_at' => $this->tested_at,
            'retry_count' => $this->retry_count,
        ];
    }
}
