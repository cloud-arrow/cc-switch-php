<?php

declare(strict_types=1);

namespace CcSwitch\DeepLink;

use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\McpService;

/**
 * Import parsed MCP deep link data into the database.
 *
 * Supports batch import of MCP servers from the standard
 * { "mcpServers": { "id": { ... } } } format.
 */
class McpImporter
{
    private McpService $mcpService;

    public function __construct(
        private readonly McpRepository $repo,
        private readonly SettingsRepository $settingsRepo,
    ) {
        $this->mcpService = new McpService($this->repo, $this->settingsRepo);
    }

    /**
     * Import MCP servers from parsed deep link data.
     *
     * @param array{apps: string, config: array<string, mixed>, enabled?: bool} $data
     * @return array{imported_count: int, imported_ids: string[], failed: array<int, array{id: string, error: string}>}
     */
    public function import(array $data): array
    {
        $appsStr = $data['apps'];
        $config = $data['config'];

        // Parse enabled apps
        $enabledApps = array_map('trim', explode(',', $appsStr));

        // Extract mcpServers from config
        $servers = $config['mcpServers'] ?? $config;
        if (!is_array($servers)) {
            return ['imported_count' => 0, 'imported_ids' => [], 'failed' => []];
        }

        $importedIds = [];
        $failed = [];

        foreach ($servers as $id => $spec) {
            if (!is_array($spec)) {
                $failed[] = ['id' => (string) $id, 'error' => 'Server spec must be an object'];
                continue;
            }

            try {
                $serverData = [
                    'id' => (string) $id,
                    'name' => (string) $id,
                    'server_config' => json_encode($spec, JSON_UNESCAPED_SLASHES),
                    'tags' => json_encode(['imported']),
                    'enabled_claude' => in_array('claude', $enabledApps) ? 1 : 0,
                    'enabled_codex' => in_array('codex', $enabledApps) ? 1 : 0,
                    'enabled_gemini' => in_array('gemini', $enabledApps) ? 1 : 0,
                    'enabled_opencode' => in_array('opencode', $enabledApps) ? 1 : 0,
                ];

                // Check if server already exists; if so, merge apps
                $existing = $this->repo->get((string) $id);
                if ($existing) {
                    // Merge: only enable additional apps, don't overwrite existing config
                    $mergeData = ['id' => (string) $id];
                    if (in_array('claude', $enabledApps)) {
                        $mergeData['enabled_claude'] = 1;
                    }
                    if (in_array('codex', $enabledApps)) {
                        $mergeData['enabled_codex'] = 1;
                    }
                    if (in_array('gemini', $enabledApps)) {
                        $mergeData['enabled_gemini'] = 1;
                    }
                    if (in_array('opencode', $enabledApps)) {
                        $mergeData['enabled_opencode'] = 1;
                    }
                    $this->mcpService->upsert($mergeData);
                } else {
                    $this->mcpService->upsert($serverData);
                }

                $importedIds[] = (string) $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => (string) $id, 'error' => $e->getMessage()];
            }
        }

        return [
            'imported_count' => count($importedIds),
            'imported_ids' => $importedIds,
            'failed' => $failed,
        ];
    }
}
