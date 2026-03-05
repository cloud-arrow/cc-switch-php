<?php

declare(strict_types=1);

namespace CcSwitch\Http;

use CcSwitch\App;
use FastRoute\Dispatcher;
use Swoole\Http\Request;

/**
 * HTTP API router using nikic/fast-route.
 * Defines all 42 API routes and dispatches to controllers.
 */
class Router
{
    private Dispatcher $dispatcher;
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->dispatcher = \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
            // Provider (10) — static routes before variable routes to avoid shadowing
            $r->addRoute('POST', '/api/providers/import', ['ProviderController', 'import']);
            $r->addRoute('GET', '/api/providers/export', ['ProviderController', 'export']);
            $r->addRoute('GET', '/api/providers/presets/{app}', ['ProviderController', 'presets']);
            $r->addRoute('GET', '/api/providers/{app}', ['ProviderController', 'list']);
            $r->addRoute('POST', '/api/providers/{app}', ['ProviderController', 'add']);
            $r->addRoute('GET', '/api/providers/{app}/{id}', ['ProviderController', 'get']);
            $r->addRoute('PUT', '/api/providers/{app}/{id}', ['ProviderController', 'update']);
            $r->addRoute('DELETE', '/api/providers/{app}/{id}', ['ProviderController', 'delete']);
            $r->addRoute('POST', '/api/providers/{app}/{id}/switch', ['ProviderController', 'switch']);
            $r->addRoute('GET', '/api/providers/{app}/{id}/endpoints', ['ProviderController', 'getEndpoints']);
            $r->addRoute('POST', '/api/providers/{app}/{id}/endpoints', ['ProviderController', 'addEndpoint']);
            $r->addRoute('DELETE', '/api/providers/{app}/{id}/endpoints', ['ProviderController', 'deleteEndpoint']);
            $r->addRoute('POST', '/api/providers/{app}/reorder', ['ProviderController', 'reorder']);

            // Universal Provider (4)
            $r->addRoute('GET', '/api/universal-providers', ['UniversalProviderController', 'list']);
            $r->addRoute('POST', '/api/universal-providers', ['UniversalProviderController', 'add']);
            $r->addRoute('PUT', '/api/universal-providers/{id}', ['UniversalProviderController', 'update']);
            $r->addRoute('DELETE', '/api/universal-providers/{id}', ['UniversalProviderController', 'delete']);

            // MCP (4)
            $r->addRoute('GET', '/api/mcp', ['McpController', 'list']);
            $r->addRoute('POST', '/api/mcp', ['McpController', 'upsert']);
            $r->addRoute('DELETE', '/api/mcp/{id}', ['McpController', 'delete']);
            $r->addRoute('PUT', '/api/mcp/{id}', ['McpController', 'update']);
            $r->addRoute('POST', '/api/mcp/sync', ['McpController', 'sync']);

            // Proxy (6)
            $r->addRoute('GET', '/api/proxy/status', ['ProxyController', 'status']);
            $r->addRoute('POST', '/api/proxy/start', ['ProxyController', 'start']);
            $r->addRoute('POST', '/api/proxy/stop', ['ProxyController', 'stop']);
            $r->addRoute('GET', '/api/proxy/config/{app}', ['ProxyController', 'getConfig']);
            $r->addRoute('PUT', '/api/proxy/config/{app}', ['ProxyController', 'updateConfig']);
            $r->addRoute('GET', '/api/proxy/health/{app}', ['ProxyController', 'health']);

            // Proxy Takeover (3)
            $r->addRoute('GET', '/api/proxy/takeover/status', ['ProxyController', 'takeoverStatus']);
            $r->addRoute('POST', '/api/proxy/takeover/{app}/enable', ['ProxyController', 'takeoverEnable']);
            $r->addRoute('POST', '/api/proxy/takeover/{app}/disable', ['ProxyController', 'takeoverDisable']);

            // Failover (3)
            $r->addRoute('GET', '/api/failover/{app}', ['ProxyController', 'failoverList']);
            $r->addRoute('POST', '/api/failover/{app}', ['ProxyController', 'failoverAdd']);
            $r->addRoute('DELETE', '/api/failover/{app}/{providerId}', ['ProxyController', 'failoverRemove']);

            // Skill Repos (6) — before skills routes to avoid shadowing
            $r->addRoute('GET', '/api/skill-repos', ['SkillController', 'listRepos']);
            $r->addRoute('POST', '/api/skill-repos', ['SkillController', 'addRepo']);
            $r->addRoute('DELETE', '/api/skill-repos/{owner}/{name}', ['SkillController', 'removeRepo']);
            $r->addRoute('POST', '/api/skill-repos/{owner}/{name}/discover', ['SkillController', 'discoverSkills']);
            $r->addRoute('POST', '/api/skills/scan-unmanaged', ['SkillController', 'scanUnmanaged']);
            $r->addRoute('POST', '/api/skills/import-from-apps', ['SkillController', 'importFromApps']);

            // Skills (4)
            $r->addRoute('GET', '/api/skills', ['SkillController', 'list']);
            $r->addRoute('POST', '/api/skills/install', ['SkillController', 'install']);
            $r->addRoute('DELETE', '/api/skills/{id}', ['SkillController', 'delete']);
            $r->addRoute('PUT', '/api/skills/{id}', ['SkillController', 'update']);
            $r->addRoute('POST', '/api/skills/sync', ['SkillController', 'sync']);

            // Prompts (4)
            $r->addRoute('GET', '/api/prompts/{app}', ['PromptController', 'list']);
            $r->addRoute('POST', '/api/prompts/{app}', ['PromptController', 'add']);
            $r->addRoute('PUT', '/api/prompts/{app}/{id}', ['PromptController', 'update']);
            $r->addRoute('DELETE', '/api/prompts/{app}/{id}', ['PromptController', 'delete']);

            // Settings (2)
            $r->addRoute('GET', '/api/settings', ['SettingsController', 'get']);
            $r->addRoute('PUT', '/api/settings', ['SettingsController', 'update']);

            // Global Proxy (4)
            $r->addRoute('GET', '/api/settings/proxy', ['SettingsController', 'getProxy']);
            $r->addRoute('PUT', '/api/settings/proxy', ['SettingsController', 'setProxy']);
            $r->addRoute('POST', '/api/settings/proxy/test', ['SettingsController', 'testProxy']);
            $r->addRoute('POST', '/api/settings/proxy/scan', ['SettingsController', 'scanProxy']);

            // Model Pricing (3)
            $r->addRoute('GET', '/api/settings/pricing', ['SettingsController', 'getPricing']);
            $r->addRoute('PUT', '/api/settings/pricing/{id}', ['SettingsController', 'updatePricing']);
            $r->addRoute('POST', '/api/settings/pricing', ['SettingsController', 'addPricing']);

            // Usage (7) — static routes before variable routes
            $r->addRoute('GET', '/api/usage/summary', ['UsageController', 'summary']);
            $r->addRoute('GET', '/api/usage/trends', ['UsageController', 'trends']);
            $r->addRoute('GET', '/api/usage/providers', ['UsageController', 'providers']);
            $r->addRoute('GET', '/api/usage/models', ['UsageController', 'models']);
            $r->addRoute('GET', '/api/usage/stats', ['UsageController', 'stats']);
            $r->addRoute('GET', '/api/usage/logs', ['UsageController', 'logs']);
            $r->addRoute('GET', '/api/usage/logs/{id}', ['UsageController', 'detail']);

            // Sync (3)
            $r->addRoute('POST', '/api/sync/push', ['SyncController', 'push']);
            $r->addRoute('POST', '/api/sync/pull', ['SyncController', 'pull']);
            $r->addRoute('POST', '/api/sync/test', ['SyncController', 'test']);

            // SpeedTest (1)
            $r->addRoute('POST', '/api/speedtest', ['SpeedTestController', 'test']);

            // Sessions (2)
            $r->addRoute('GET', '/api/sessions', ['SessionController', 'list']);
            $r->addRoute('GET', '/api/sessions/{id}/resume-command', ['SessionController', 'resumeCommand']);

            // Import (1)
            $r->addRoute('POST', '/api/import', ['ImportController', 'import']);

            // Workspace (8)
            $r->addRoute('GET', '/api/workspace/files', ['WorkspaceController', 'listFiles']);
            $r->addRoute('GET', '/api/workspace/files/{name}', ['WorkspaceController', 'readFile']);
            $r->addRoute('PUT', '/api/workspace/files/{name}', ['WorkspaceController', 'writeFile']);
            $r->addRoute('GET', '/api/workspace/memory', ['WorkspaceController', 'listMemory']);
            $r->addRoute('GET', '/api/workspace/memory/search', ['WorkspaceController', 'searchMemory']);
            $r->addRoute('GET', '/api/workspace/memory/{date}', ['WorkspaceController', 'readMemory']);
            $r->addRoute('PUT', '/api/workspace/memory/{date}', ['WorkspaceController', 'writeMemory']);
            $r->addRoute('DELETE', '/api/workspace/memory/{date}', ['WorkspaceController', 'deleteMemory']);

            // OMO (3)
            $r->addRoute('GET', '/api/omo/{variant}', ['OmoController', 'get']);
            $r->addRoute('POST', '/api/omo/import/{variant}', ['OmoController', 'import']);
            $r->addRoute('POST', '/api/omo/export/{variant}', ['OmoController', 'export']);

            // OpenClaw (6)
            $r->addRoute('GET', '/api/openclaw/default-model', ['OpenClawController', 'getDefaultModel']);
            $r->addRoute('PUT', '/api/openclaw/default-model', ['OpenClawController', 'setDefaultModel']);
            $r->addRoute('GET', '/api/openclaw/model-catalog', ['OpenClawController', 'getModelCatalog']);
            $r->addRoute('PUT', '/api/openclaw/model-catalog', ['OpenClawController', 'setModelCatalog']);
            $r->addRoute('GET', '/api/openclaw/agents-defaults', ['OpenClawController', 'getAgentsDefaults']);
            $r->addRoute('PUT', '/api/openclaw/agents-defaults', ['OpenClawController', 'setAgentsDefaults']);

            // Claude Plugin (3)
            $r->addRoute('GET', '/api/claude-plugin/status', ['ClaudePluginController', 'status']);
            $r->addRoute('POST', '/api/claude-plugin/apply', ['ClaudePluginController', 'apply']);
            $r->addRoute('POST', '/api/claude-plugin/clear', ['ClaudePluginController', 'clear']);

            // Stream Check (4)
            $r->addRoute('POST', '/api/stream-check/{appType}/{providerId}', ['StreamCheckController', 'checkOne']);
            $r->addRoute('POST', '/api/stream-check/{appType}', ['StreamCheckController', 'checkAll']);
            $r->addRoute('GET', '/api/stream-check/config', ['StreamCheckController', 'getConfig']);
            $r->addRoute('PUT', '/api/stream-check/config', ['StreamCheckController', 'saveConfig']);

            // Backup (4)
            $r->addRoute('GET', '/api/backup/list', ['BackupController', 'list']);
            $r->addRoute('POST', '/api/backup/create', ['BackupController', 'create']);
            $r->addRoute('POST', '/api/backup/restore', ['BackupController', 'restore']);
            $r->addRoute('POST', '/api/backup/cleanup', ['BackupController', 'cleanup']);

            // Env (3)
            $r->addRoute('GET', '/api/env/check', ['EnvController', 'check']);
            $r->addRoute('POST', '/api/env/delete', ['EnvController', 'delete']);
            $r->addRoute('POST', '/api/env/restore', ['EnvController', 'restore']);

            // Circuit Breaker (2)
            $r->addRoute('GET', '/api/proxy/circuit-breaker/{app}', ['ProxyController', 'circuitBreakerList']);
            $r->addRoute('POST', '/api/proxy/circuit-breaker/{app}/{providerId}/reset', ['ProxyController', 'circuitBreakerReset']);

            // Rectifier Settings (2)
            $r->addRoute('GET', '/api/settings/rectifier', ['SettingsController', 'getRectifier']);
            $r->addRoute('PUT', '/api/settings/rectifier', ['SettingsController', 'setRectifier']);
        });
    }

    /**
     * Dispatch a Swoole request to the matching controller action.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function dispatch(Request $request): array
    {
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        $uri = $request->server['request_uri'] ?? '/';

        // Strip query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        $routeInfo = $this->dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return $this->jsonResponse(404, ['error' => 'Not found']);

            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowed = $routeInfo[1];
                return $this->jsonResponse(405, [
                    'error' => 'Method not allowed',
                    'allowed' => $allowed,
                ], ['Allow' => implode(', ', $allowed)]);

            case Dispatcher::FOUND:
                [$controllerName, $action] = $routeInfo[1];
                $vars = $routeInfo[2];
                return $this->callController($controllerName, $action, $request, $vars);
        }

        return $this->jsonResponse(500, ['error' => 'Internal routing error']);
    }

    /**
     * Instantiate a controller and call its action method.
     */
    private function callController(string $controllerName, string $action, Request $request, array $vars): array
    {
        $fqcn = 'CcSwitch\\Http\\Controller\\' . $controllerName;

        if (!class_exists($fqcn)) {
            return $this->jsonResponse(501, [
                'error' => "Controller {$controllerName} not implemented yet",
            ]);
        }

        $controller = new $fqcn($this->app);

        if (!method_exists($controller, $action)) {
            return $this->jsonResponse(501, [
                'error' => "{$controllerName}::{$action} not implemented yet",
            ]);
        }

        try {
            // Parse request body for POST/PUT
            $body = [];
            $rawBody = $request->rawContent();
            if ($rawBody !== false && $rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }

            // Parse query parameters
            $query = $request->get ?? [];

            $result = $controller->$action($vars, $body, $query);

            if (is_array($result) && isset($result['status'])) {
                return $this->jsonResponse(
                    $result['status'],
                    $result['body'] ?? null,
                    $result['headers'] ?? [],
                );
            }

            return $this->jsonResponse(200, $result);
        } catch (\Throwable $e) {
            return $this->jsonResponse(500, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a JSON response array.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function jsonResponse(int $status, $data, array $extraHeaders = []): array
    {
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $extraHeaders,
        );

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ];
    }
}
