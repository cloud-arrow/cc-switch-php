<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the skills table.
 */
class SkillRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->db->select('skills', '*') ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $row = $this->db->get('skills', '*', ['id' => $id]);
        return $row ?: null;
    }

    public function insert(array $data): void
    {
        $this->db->insert('skills', $data);
    }

    public function delete(string $id): void
    {
        $this->db->delete('skills', ['id' => $id]);
    }

    /**
     * Update the enabled flag for a specific app type.
     */
    public function updateEnabled(string $id, string $app, bool $enabled): void
    {
        $column = 'enabled_' . $app;
        $this->db->update('skills', [$column => $enabled ? 1 : 0], ['id' => $id]);
    }
}
