<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the universal_providers table.
 */
class UniversalProviderRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->db->select('universal_providers', '*') ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $row = $this->db->get('universal_providers', '*', ['id' => $id]);
        return $row ?: null;
    }

    public function insert(array $data): void
    {
        $this->db->insert('universal_providers', $data);
    }

    public function update(string $id, array $data): void
    {
        $this->db->update('universal_providers', $data, ['id' => $id]);
    }

    public function delete(string $id): void
    {
        $this->db->delete('universal_providers', ['id' => $id]);
    }
}
