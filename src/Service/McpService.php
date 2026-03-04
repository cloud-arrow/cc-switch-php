<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Model\McpServer;
use CcSwitch\Util\AtomicFile;

/**
 * MCP server management service.
 *
 * Handles CRUD operations and syncing MCP server configurations
 * to various app config files (Claude, Codex, Gemini, OpenCode).
 */
class McpService
{
    private string $home;

    public function __construct(
        private readonly McpRepository $repo,
        /** @phpstan-ignore property.onlyWritten */
        private readonly SettingsRepository $settingsRepo,
    ) {
        $this->home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    }

    /**
     * @return McpServer[]
     */
    public function list(): array
    {
        $rows = $this->repo->list();
        return array_map([McpServer::class, 'fromRow'], $rows);
    }

    /**
     * Get MCP servers enabled for a specific app.
     *
     * @return McpServer[]
     */
    public function getByApp(string $app): array
    {
        $rows = $this->repo->getByApp($app);
        return array_map([McpServer::class, 'fromRow'], $rows);
    }

    public function get(string $id): ?McpServer
    {
        $row = $this->repo->get($id);
        return $row ? McpServer::fromRow($row) : null;
    }

    /**
     * Insert or update an MCP server.
     *
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): McpServer
    {
        $this->repo->upsert($data);
        $row = $this->repo->get($data['id']);
        $server = McpServer::fromRow($row);

        // Sync to enabled apps
        $this->syncServerToApps($server);

        return $server;
    }

    public function delete(string $id): void
    {
        $server = $this->get($id);
        if ($server) {
            // Remove from all app configs
            $this->removeServerFromAllApps($server);
        }
        $this->repo->delete($id);
    }

    /**
     * Sync all enabled MCP servers to a specific app.
     */
    public function syncToApp(string $app): void
    {
        $servers = $this->getByApp($app);
        foreach ($servers as $server) {
            $this->syncSingleServerToApp($server, $app);
        }
    }

    /**
     * Sync all enabled MCP servers to all apps.
     */
    public function syncAll(): void
    {
        $servers = $this->list();
        foreach ($servers as $server) {
            $this->syncServerToApps($server);
        }
    }

    /**
     * Import MCP servers from an app's config file into the database.
     */
    public function importFromApp(string $app): int
    {
        $imported = 0;

        switch ($app) {
            case 'claude':
                $imported = $this->importFromClaude();
                break;
            case 'codex':
                $imported = $this->importFromCodex();
                break;
            case 'gemini':
                $imported = $this->importFromGemini();
                break;
            case 'opencode':
                $imported = $this->importFromOpenCode();
                break;
        }

        return $imported;
    }

    // ========================================================================
    // App config paths
    // ========================================================================

    private function getClaudeMcpPath(): string
    {
        return $this->home . '/.claude/mcp.json';
    }

    private function getCodexConfigPath(): string
    {
        return $this->home . '/.codex/config.toml';
    }

    private function getGeminiSettingsPath(): string
    {
        return $this->home . '/.config/gemini-cli/settings/mcp_servers.json';
    }

    private function getOpenCodeConfigPath(): string
    {
        return $this->home . '/.opencode/mcp.json';
    }

    // ========================================================================
    // Sync: server -> app config
    // ========================================================================

    private function syncServerToApps(McpServer $server): void
    {
        if ($server->enabled_claude) {
            $this->syncSingleServerToApp($server, 'claude');
        }
        if ($server->enabled_codex) {
            $this->syncSingleServerToApp($server, 'codex');
        }
        if ($server->enabled_gemini) {
            $this->syncSingleServerToApp($server, 'gemini');
        }
        if ($server->enabled_opencode) {
            $this->syncSingleServerToApp($server, 'opencode');
        }
    }

    private function syncSingleServerToApp(McpServer $server, string $app): void
    {
        $config = $this->decodeServerConfig($server);
        if ($config === null) {
            return;
        }

        switch ($app) {
            case 'claude':
                $this->syncToClaude($server->id, $config);
                break;
            case 'codex':
                $this->syncToCodex($server->id, $config);
                break;
            case 'gemini':
                $this->syncToGemini($server->id, $config);
                break;
            case 'opencode':
                $this->syncToOpenCode($server->id, $config);
                break;
        }
    }

    private function removeServerFromAllApps(McpServer $server): void
    {
        if ($server->enabled_claude) {
            $this->removeFromClaude($server->id);
        }
        if ($server->enabled_codex) {
            $this->removeFromCodex($server->id);
        }
        if ($server->enabled_gemini) {
            $this->removeFromGemini($server->id);
        }
        if ($server->enabled_opencode) {
            $this->removeFromOpenCode($server->id);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeServerConfig(McpServer $server): ?array
    {
        if (empty($server->server_config)) {
            return null;
        }
        $data = json_decode($server->server_config, true);
        return is_array($data) ? $data : null;
    }

    // ========================================================================
    // Claude: ~/.claude/mcp.json — { "mcpServers": { "id": { ... } } }
    // ========================================================================

    /**
     * @param array<string, mixed> $config
     */
    private function syncToClaude(string $id, array $config): void
    {
        $path = $this->getClaudeMcpPath();
        $data = AtomicFile::readJson($path);

        if (!isset($data['mcpServers']) || !is_array($data['mcpServers'])) {
            $data['mcpServers'] = [];
        }

        $data['mcpServers'][$id] = $this->stripMetaFields($config);
        AtomicFile::writeJson($path, $data);
    }

    private function removeFromClaude(string $id): void
    {
        $path = $this->getClaudeMcpPath();
        if (!file_exists($path)) {
            return;
        }
        $data = AtomicFile::readJson($path);

        if (isset($data['mcpServers'][$id])) {
            unset($data['mcpServers'][$id]);
            AtomicFile::writeJson($path, $data);
        }
    }

    // ========================================================================
    // Codex: ~/.codex/config.toml — [mcp_servers.id]
    // ========================================================================

    /**
     * @param array<string, mixed> $config
     */
    private function syncToCodex(string $id, array $config): void
    {
        $path = $this->getCodexConfigPath();
        $spec = $this->stripMetaFields($config);

        // Read existing TOML, update mcp_servers section, write back
        $content = file_exists($path) ? (string) file_get_contents($path) : '';
        $content = $this->updateCodexTomlServer($content, $id, $spec);
        AtomicFile::write($path, $content);
    }

    private function removeFromCodex(string $id): void
    {
        $path = $this->getCodexConfigPath();
        if (!file_exists($path)) {
            return;
        }
        $content = (string) file_get_contents($path);
        $content = $this->removeCodexTomlServer($content, $id);
        AtomicFile::write($path, $content);
    }

    /**
     * Update or add an MCP server entry in Codex TOML config.
     *
     * @param array<string, mixed> $spec
     */
    private function updateCodexTomlServer(string $toml, string $id, array $spec): string
    {
        // Remove existing section for this server (both formats)
        $toml = $this->removeCodexTomlServer($toml, $id);

        // Build the new TOML section
        $section = "\n[mcp_servers.{$id}]\n";
        foreach ($spec as $key => $value) {
            $section .= $this->tomlKeyValue($key, $value);
        }

        return rtrim($toml) . "\n" . $section;
    }

    private function removeCodexTomlServer(string $toml, string $id): string
    {
        // Remove [mcp_servers.<id>] section including all key-value pairs until next section or EOF
        $escapedId = preg_quote($id, '/');
        $pattern = '/\n?\[mcp_servers\.' . $escapedId . '\]\n(?:[^\[]*?)(?=\n\[|\z)/s';
        $result = preg_replace($pattern, '', $toml);
        return $result ?? $toml;
    }

    /**
     * Format a key-value pair as TOML.
     */
    private function tomlKeyValue(string $key, mixed $value): string
    {
        if (is_string($value)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            return "{$key} = \"{$escaped}\"\n";
        }
        if (is_int($value) || is_float($value)) {
            return "{$key} = {$value}\n";
        }
        if (is_bool($value)) {
            return "{$key} = " . ($value ? 'true' : 'false') . "\n";
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                // Array of values
                $items = array_map(function ($v) {
                    if (is_string($v)) {
                        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
                        return "\"{$escaped}\"";
                    }
                    return (string) $v;
                }, $value);
                return "{$key} = [" . implode(', ', $items) . "]\n";
            }
            // Associative array -> inline table or sub-table
            $parts = [];
            foreach ($value as $k => $v) {
                if (is_string($v)) {
                    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
                    $parts[] = "{$k} = \"{$escaped}\"";
                } elseif (is_int($v) || is_float($v)) {
                    $parts[] = "{$k} = {$v}";
                } elseif (is_bool($v)) {
                    $parts[] = "{$k} = " . ($v ? 'true' : 'false');
                }
            }
            if (!empty($parts)) {
                return "{$key} = { " . implode(', ', $parts) . " }\n";
            }
        }

        return '';
    }

    // ========================================================================
    // Gemini: ~/.config/gemini-cli/settings/mcp_servers.json — { "mcpServers": { ... } }
    // ========================================================================

    /**
     * @param array<string, mixed> $config
     */
    private function syncToGemini(string $id, array $config): void
    {
        $path = $this->getGeminiSettingsPath();
        $data = AtomicFile::readJson($path);

        if (!isset($data['mcpServers']) || !is_array($data['mcpServers'])) {
            $data['mcpServers'] = [];
        }

        $spec = $this->stripMetaFields($config);

        // Gemini format conversion: http type uses "httpUrl" instead of "url"
        if (isset($spec['type']) && $spec['type'] === 'http' && isset($spec['url'])) {
            $spec['httpUrl'] = $spec['url'];
            unset($spec['url']);
        }

        // Gemini does not use the "type" field
        unset($spec['type']);

        $data['mcpServers'][$id] = $spec;
        AtomicFile::writeJson($path, $data);
    }

    private function removeFromGemini(string $id): void
    {
        $path = $this->getGeminiSettingsPath();
        if (!file_exists($path)) {
            return;
        }
        $data = AtomicFile::readJson($path);

        if (isset($data['mcpServers'][$id])) {
            unset($data['mcpServers'][$id]);
            AtomicFile::writeJson($path, $data);
        }
    }

    // ========================================================================
    // OpenCode: ~/.opencode/mcp.json — { "id": { ... } }
    // Format: stdio->local, command+args->command array, env->environment
    // ========================================================================

    /**
     * @param array<string, mixed> $config
     */
    private function syncToOpenCode(string $id, array $config): void
    {
        $path = $this->getOpenCodeConfigPath();
        $data = AtomicFile::readJson($path);

        $spec = $this->convertToOpenCodeFormat($this->stripMetaFields($config));
        $data[$id] = $spec;
        AtomicFile::writeJson($path, $data);
    }

    private function removeFromOpenCode(string $id): void
    {
        $path = $this->getOpenCodeConfigPath();
        if (!file_exists($path)) {
            return;
        }
        $data = AtomicFile::readJson($path);

        if (isset($data[$id])) {
            unset($data[$id]);
            AtomicFile::writeJson($path, $data);
        }
    }

    /**
     * Convert unified MCP format to OpenCode format.
     *
     * - stdio -> local, command+args -> command array, env -> environment
     * - sse/http -> remote
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function convertToOpenCodeFormat(array $spec): array
    {
        $type = $spec['type'] ?? 'stdio';
        $result = [];

        if ($type === 'stdio') {
            $result['type'] = 'local';
            $command = [$spec['command'] ?? ''];
            if (isset($spec['args']) && is_array($spec['args'])) {
                $command = array_merge($command, $spec['args']);
            }
            $result['command'] = $command;

            if (!empty($spec['env']) && is_array($spec['env'])) {
                $result['environment'] = $spec['env'];
            }
            $result['enabled'] = true;
        } elseif ($type === 'sse' || $type === 'http') {
            $result['type'] = 'remote';
            if (isset($spec['url'])) {
                $result['url'] = $spec['url'];
            }
            if (!empty($spec['headers']) && is_array($spec['headers'])) {
                $result['headers'] = $spec['headers'];
            }
            $result['enabled'] = true;
        }

        return $result;
    }

    /**
     * Convert OpenCode format back to unified MCP format.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function convertFromOpenCodeFormat(array $spec): array
    {
        $type = $spec['type'] ?? 'local';
        $result = [];

        if ($type === 'local') {
            $result['type'] = 'stdio';
            if (isset($spec['command']) && is_array($spec['command']) && !empty($spec['command'])) {
                $result['command'] = $spec['command'][0];
                if (count($spec['command']) > 1) {
                    $result['args'] = array_slice($spec['command'], 1);
                }
            }
            if (!empty($spec['environment']) && is_array($spec['environment'])) {
                $result['env'] = $spec['environment'];
            }
        } elseif ($type === 'remote') {
            $result['type'] = 'sse';
            if (isset($spec['url'])) {
                $result['url'] = $spec['url'];
            }
            if (!empty($spec['headers']) && is_array($spec['headers'])) {
                $result['headers'] = $spec['headers'];
            }
        }

        return $result;
    }

    // ========================================================================
    // Import: app config -> database
    // ========================================================================

    private function importFromClaude(): int
    {
        $path = $this->getClaudeMcpPath();
        if (!file_exists($path)) {
            return 0;
        }

        $data = AtomicFile::readJson($path);
        $servers = $data['mcpServers'] ?? [];
        if (!is_array($servers) || empty($servers)) {
            return 0;
        }

        $count = 0;
        foreach ($servers as $id => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $this->importServer((string) $id, $spec, 'claude');
            $count++;
        }

        return $count;
    }

    private function importFromCodex(): int
    {
        $path = $this->getCodexConfigPath();
        if (!file_exists($path)) {
            return 0;
        }

        $content = (string) file_get_contents($path);
        if (trim($content) === '') {
            return 0;
        }

        // Parse TOML using yosymfony/toml
        try {
            $parsed = \Yosymfony\Toml\Toml::parse($content);
        } catch (\Exception $e) {
            return 0;
        }

        $count = 0;

        // Check for [mcp_servers.*] (correct Codex format)
        if (isset($parsed['mcp_servers']) && is_array($parsed['mcp_servers'])) {
            foreach ($parsed['mcp_servers'] as $id => $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $this->importServer((string) $id, $spec, 'codex');
                $count++;
            }
        }

        return $count;
    }

    private function importFromGemini(): int
    {
        $path = $this->getGeminiSettingsPath();
        if (!file_exists($path)) {
            return 0;
        }

        $data = AtomicFile::readJson($path);
        $servers = $data['mcpServers'] ?? [];
        if (!is_array($servers) || empty($servers)) {
            return 0;
        }

        $count = 0;
        foreach ($servers as $id => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            // Reverse Gemini format: httpUrl -> url + type=http
            if (isset($spec['httpUrl'])) {
                $spec['url'] = $spec['httpUrl'];
                $spec['type'] = 'http';
                unset($spec['httpUrl']);
            }

            // Add missing type field
            if (!isset($spec['type'])) {
                if (isset($spec['command'])) {
                    $spec['type'] = 'stdio';
                } elseif (isset($spec['url'])) {
                    $spec['type'] = 'sse';
                }
            }

            $this->importServer((string) $id, $spec, 'gemini');
            $count++;
        }

        return $count;
    }

    private function importFromOpenCode(): int
    {
        $path = $this->getOpenCodeConfigPath();
        if (!file_exists($path)) {
            return 0;
        }

        $data = AtomicFile::readJson($path);
        if (empty($data)) {
            return 0;
        }

        $count = 0;
        foreach ($data as $id => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $unified = $this->convertFromOpenCodeFormat($spec);
            $this->importServer((string) $id, $unified, 'opencode');
            $count++;
        }

        return $count;
    }

    /**
     * Import a single server into the database, enabling it for the specified app.
     *
     * @param array<string, mixed> $spec
     */
    private function importServer(string $id, array $spec, string $app): void
    {
        $existing = $this->repo->get($id);

        if ($existing) {
            // Already exists: just enable for this app
            $column = 'enabled_' . $app;
            $this->repo->upsert([
                'id' => $id,
                $column => 1,
            ]);
        } else {
            // New server
            $enabledColumn = 'enabled_' . $app;
            $this->repo->upsert([
                'id' => $id,
                'name' => $id,
                'server_config' => json_encode($spec, JSON_UNESCAPED_SLASHES),
                $enabledColumn => 1,
            ]);
        }
    }

    /**
     * Strip metadata fields that should not be written to app config files.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function stripMetaFields(array $config): array
    {
        unset(
            $config['enabled'],
            $config['source'],
            $config['id'],
            $config['name'],
            $config['description'],
            $config['tags'],
            $config['homepage'],
            $config['docs'],
        );
        return $config;
    }
}
