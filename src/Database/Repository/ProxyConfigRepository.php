<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the proxy_config table.
 */
class ProxyConfigRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $app): ?array
    {
        $row = $this->db->get('proxy_config', '*', ['app_type' => $app]);
        return $row ?: null;
    }

    public function update(string $app, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('proxy_config', $data, ['app_type' => $app]);
    }
}
