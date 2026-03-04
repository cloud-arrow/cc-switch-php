<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * MCP server model representing a row in the mcp_servers table.
 */
class McpServer
{
    public string $id = '';
    public string $name = '';
    public string $server_config = '';
    public ?string $description = null;
    public ?string $homepage = null;
    public ?string $docs = null;
    public string $tags = '[]';
    public int $enabled_claude = 0;
    public int $enabled_codex = 0;
    public int $enabled_gemini = 0;
    public int $enabled_opencode = 0;

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (string) ($row['id'] ?? '');
        $model->name = (string) ($row['name'] ?? '');
        $model->server_config = (string) ($row['server_config'] ?? '');
        $model->description = $row['description'] ?? null;
        $model->homepage = $row['homepage'] ?? null;
        $model->docs = $row['docs'] ?? null;
        $model->tags = (string) ($row['tags'] ?? '[]');
        $model->enabled_claude = (int) ($row['enabled_claude'] ?? 0);
        $model->enabled_codex = (int) ($row['enabled_codex'] ?? 0);
        $model->enabled_gemini = (int) ($row['enabled_gemini'] ?? 0);
        $model->enabled_opencode = (int) ($row['enabled_opencode'] ?? 0);
        return $model;
    }
}
