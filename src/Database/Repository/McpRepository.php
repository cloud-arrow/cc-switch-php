<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the mcp_servers table.
 */
class McpRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->db->select('mcp_servers', '*') ?? [];
    }

    /**
     * Get MCP servers enabled for a specific app type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByApp(string $app): array
    {
        $column = 'enabled_' . $app;
        return $this->db->select('mcp_servers', '*', [$column => 1]) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $row = $this->db->get('mcp_servers', '*', ['id' => $id]);
        return $row ?: null;
    }

    /**
     * Insert or update an MCP server.
     */
    public function upsert(array $data): void
    {
        $id = $data['id'] ?? '';
        $existing = $this->get($id);
        if ($existing) {
            $this->db->update('mcp_servers', $data, ['id' => $id]);
        } else {
            $this->db->insert('mcp_servers', $data);
        }
    }

    public function delete(string $id): void
    {
        $this->db->delete('mcp_servers', ['id' => $id]);
    }
}
