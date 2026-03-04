<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use CcSwitch\Model\StreamCheckConfig;
use CcSwitch\Model\StreamCheckResult;
use Medoo\Medoo;

class StreamCheckRepository
{
    private const CONFIG_KEY = 'stream_check_config';

    public function __construct(
        private readonly Medoo $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function saveLog(
        string $providerId,
        string $providerName,
        string $appType,
        StreamCheckResult $result,
    ): int {
        $this->db->insert('stream_check_logs', [
            'provider_id' => $providerId,
            'provider_name' => $providerName,
            'app_type' => $appType,
            'status' => $result->status,
            'success' => $result->success ? 1 : 0,
            'message' => $result->message,
            'response_time_ms' => $result->response_time_ms,
            'http_status' => $result->http_status,
            'model_used' => $result->model_used,
            'retry_count' => $result->retry_count,
            'tested_at' => $result->tested_at,
        ]);

        $id = $this->db->id();
        return $id ? (int) $id : 0;
    }

    public function getConfig(): StreamCheckConfig
    {
        $json = $this->settings->get(self::CONFIG_KEY);
        if ($json === null) {
            return new StreamCheckConfig();
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new StreamCheckConfig();
        }

        return StreamCheckConfig::fromArray($data);
    }

    public function saveConfig(StreamCheckConfig $config): void
    {
        $json = json_encode($config->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->settings->set(self::CONFIG_KEY, $json);
    }
}
