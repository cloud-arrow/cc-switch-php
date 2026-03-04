<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the settings key-value table.
 */
class SettingsRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    public function get(string $key): ?string
    {
        $row = $this->db->get('settings', 'value', ['key' => $key]);
        return $row !== false && $row !== null ? (string) $row : null;
    }

    public function set(string $key, string $value): void
    {
        $existing = $this->db->get('settings', 'key', ['key' => $key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], ['key' => $key]);
        } else {
            $this->db->insert('settings', ['key' => $key, 'value' => $value]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function getAll(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->select('settings', ['key', 'value']) ?? [];
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    public function delete(string $key): void
    {
        $this->db->delete('settings', ['key' => $key]);
    }
}
