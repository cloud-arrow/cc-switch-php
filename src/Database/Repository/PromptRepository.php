<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the prompts table.
 */
class PromptRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $app): array
    {
        return $this->db->select('prompts', '*', [
            'app_type' => $app,
            'ORDER' => ['created_at' => 'DESC'],
        ]) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id, string $app): ?array
    {
        $row = $this->db->get('prompts', '*', [
            'id' => $id,
            'app_type' => $app,
        ]);
        return $row ?: null;
    }

    public function insert(array $data): void
    {
        $this->db->insert('prompts', $data);
    }

    public function update(string $id, string $app, array $data): void
    {
        $this->db->update('prompts', $data, [
            'id' => $id,
            'app_type' => $app,
        ]);
    }

    public function delete(string $id, string $app): void
    {
        $this->db->delete('prompts', [
            'id' => $id,
            'app_type' => $app,
        ]);
    }
}
