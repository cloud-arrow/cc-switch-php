<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Model\Provider;
use CcSwitch\Service\ProviderService;
use Ramsey\Uuid\Uuid;

class ProviderController
{
    private ProviderRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ProviderRepository($this->app->getMedoo());
    }

    public function list(array $vars): array
    {
        $rows = $this->repo->list($vars['app']);
        return array_map(fn(array $r) => $r, $rows);
    }

    public function get(array $vars): array
    {
        $row = $this->repo->get($vars['id'], $vars['app']);
        if ($row === null) {
            return ['status' => 404, 'body' => ['error' => 'Provider not found']];
        }
        return $row;
    }

    public function add(array $vars, array $body): array
    {
        $id = $body['id'] ?? Uuid::uuid4()->toString();
        $data = [
            'id' => $id,
            'app_type' => $vars['app'],
            'name' => $body['name'] ?? '',
            'settings_config' => $body['settings_config'] ?? '{}',
            'website_url' => $body['website_url'] ?? null,
            'category' => $body['category'] ?? null,
            'created_at' => time(),
            'sort_index' => $body['sort_index'] ?? 0,
            'notes' => $body['notes'] ?? null,
            'icon' => $body['icon'] ?? null,
            'icon_color' => $body['icon_color'] ?? null,
            'meta' => $body['meta'] ?? '{}',
            'is_current' => 0,
            'in_failover_queue' => 0,
        ];
        $this->repo->insert($data);
        return ['status' => 201, 'body' => $data];
    }

    public function update(array $vars, array $body): array
    {
        $existing = $this->repo->get($vars['id'], $vars['app']);
        if ($existing === null) {
            return ['status' => 404, 'body' => ['error' => 'Provider not found']];
        }

        $allowed = ['name', 'settings_config', 'website_url', 'category', 'sort_index', 'notes', 'icon', 'icon_color', 'meta'];
        $data = array_intersect_key($body, array_flip($allowed));
        if (!empty($data)) {
            $this->repo->update($vars['id'], $vars['app'], $data);
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function delete(array $vars): array
    {
        $this->repo->delete($vars['id'], $vars['app']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function switch(array $vars): array
    {
        $this->repo->switchTo($vars['id'], $vars['app']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function reorder(array $vars, array $body): array
    {
        $items = $body['items'] ?? [];
        foreach ($items as $i => $item) {
            $id = $item['id'] ?? null;
            if ($id !== null) {
                $this->repo->update($id, $vars['app'], ['sort_index' => $i]);
            }
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function import(array $vars, array $body): array
    {
        $providers = $body['providers'] ?? [];
        $imported = 0;
        foreach ($providers as $p) {
            $appType = $p['app_type'] ?? 'claude';
            $data = [
                'id' => $p['id'] ?? Uuid::uuid4()->toString(),
                'app_type' => $appType,
                'name' => $p['name'] ?? '',
                'settings_config' => $p['settings_config'] ?? '{}',
                'category' => $p['category'] ?? null,
                'created_at' => time(),
                'sort_index' => $p['sort_index'] ?? 0,
                'notes' => $p['notes'] ?? null,
                'meta' => $p['meta'] ?? '{}',
                'is_current' => 0,
                'in_failover_queue' => 0,
            ];
            $this->repo->insert($data);
            $imported++;
        }
        return ['status' => 200, 'body' => ['imported' => $imported]];
    }

    public function export(array $vars, array $body, array $query): array
    {
        $app = $query['app'] ?? null;
        if ($app) {
            $rows = $this->repo->list($app);
        } else {
            $rows = [];
            foreach (['claude', 'codex', 'gemini', 'opencode', 'openclaw'] as $a) {
                $rows = array_merge($rows, $this->repo->list($a));
            }
        }
        return ['status' => 200, 'body' => ['providers' => $rows]];
    }

    public function getEndpoints(array $vars, array $body, array $query): array
    {
        $service = new ProviderService($this->repo);
        $app = AppType::from($vars['app']);
        return $service->getEndpoints($vars['id'], $app);
    }

    public function addEndpoint(array $vars, array $body, array $query): array
    {
        if (empty($body['url'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing url']];
        }
        $service = new ProviderService($this->repo);
        $app = AppType::from($vars['app']);
        $service->addEndpoint($vars['id'], $app, $body['url']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function deleteEndpoint(array $vars, array $body, array $query): array
    {
        if (empty($body['url'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing url']];
        }
        $service = new ProviderService($this->repo);
        $app = AppType::from($vars['app']);
        $service->removeEndpoint($vars['id'], $app, $body['url']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function presets(array $vars): array
    {
        // Return built-in provider presets for the given app type
        $presets = match ($vars['app']) {
            'claude' => [
                ['name' => 'Anthropic (Official)', 'category' => 'official', 'settings_config' => '{"env":{"ANTHROPIC_API_KEY":""}}'],
                ['name' => 'OpenRouter', 'category' => 'openrouter', 'settings_config' => '{"env":{"ANTHROPIC_API_KEY":"","ANTHROPIC_BASE_URL":"https://openrouter.ai/api"}}'],
            ],
            'codex' => [
                ['name' => 'OpenAI (Official)', 'category' => 'official', 'settings_config' => '{"env":{"OPENAI_API_KEY":""}}'],
            ],
            default => [],
        };
        return $presets;
    }
}
