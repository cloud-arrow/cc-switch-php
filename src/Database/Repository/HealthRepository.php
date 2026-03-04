<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the provider_health table.
 */
class HealthRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $providerId, string $app): ?array
    {
        $row = $this->db->get('provider_health', '*', [
            'provider_id' => $providerId,
            'app_type' => $app,
        ]);
        return $row ?: null;
    }

    /**
     * Insert or update a health record.
     */
    public function upsert(array $data): void
    {
        $providerId = $data['provider_id'] ?? '';
        $appType = $data['app_type'] ?? '';
        $existing = $this->get($providerId, $appType);
        if ($existing) {
            $this->db->update('provider_health', $data, [
                'provider_id' => $providerId,
                'app_type' => $appType,
            ]);
        } else {
            $this->db->insert('provider_health', $data);
        }
    }

    /**
     * List all health records for an app type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByApp(string $app): array
    {
        return $this->db->select('provider_health', '*', ['app_type' => $app]) ?? [];
    }

    /**
     * Reset a single provider's health record to healthy defaults.
     */
    public function reset(string $providerId, string $app): void
    {
        $this->upsert([
            'provider_id' => $providerId,
            'app_type' => $app,
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'last_error' => null,
            'updated_at' => date('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Reset all health records for an app type to healthy defaults.
     */
    public function resetAll(string $app): void
    {
        $this->db->update('provider_health', [
            'is_healthy' => 1,
            'consecutive_failures' => 0,
            'last_error' => null,
            'updated_at' => date('Y-m-d\TH:i:s\Z'),
        ], [
            'app_type' => $app,
        ]);
    }
}
