<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

class SkillRepoRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    public function list(): array
    {
        return $this->db->select('skill_repos', '*') ?? [];
    }

    public function get(string $owner, string $name): ?array
    {
        $row = $this->db->get('skill_repos', '*', ['owner' => $owner, 'name' => $name]);
        return $row ?: null;
    }

    public function insert(array $data): void
    {
        $this->db->insert('skill_repos', $data);
    }

    public function delete(string $owner, string $name): void
    {
        $this->db->delete('skill_repos', ['owner' => $owner, 'name' => $name]);
    }

    public function update(string $owner, string $name, array $data): void
    {
        $this->db->update('skill_repos', $data, ['owner' => $owner, 'name' => $name]);
    }
}
