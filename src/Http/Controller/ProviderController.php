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
        $settingsConfig = $body['settings_config'] ?? '{}';
        if (is_array($settingsConfig)) {
            $settingsConfig = json_encode($settingsConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $meta = $body['meta'] ?? '{}';
        if (is_array($meta)) {
            $meta = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $data = [
            'id' => $id,
            'app_type' => $vars['app'],
            'name' => $body['name'] ?? '',
            'settings_config' => $settingsConfig,
            'website_url' => $body['website_url'] ?? null,
            'category' => $body['category'] ?? null,
            'created_at' => time(),
            'sort_index' => $body['sort_index'] ?? 0,
            'notes' => $body['notes'] ?? null,
            'icon' => $body['icon'] ?? null,
            'icon_color' => $body['icon_color'] ?? null,
            'meta' => $meta,
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

        // Ensure JSON fields are stored as strings
        foreach (['settings_config', 'meta'] as $jsonField) {
            if (isset($data[$jsonField]) && is_array($data[$jsonField])) {
                $data[$jsonField] = json_encode($data[$jsonField], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if (!empty($data)) {
            $this->repo->update($vars['id'], $vars['app'], $data);

            // Sync config file if this is the current provider
            $app = AppType::tryFrom($vars['app']);
            if ($app) {
                $service = new ProviderService($this->repo);
                $updated = $this->repo->get($vars['id'], $vars['app']);
                if ($updated && !empty($updated['is_current'])) {
                    $provider = Provider::fromRow($updated);
                    $writer = \CcSwitch\ConfigWriter\WriterFactory::create($app);
                    $writer->write($provider);
                }
            }
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
        $app = AppType::tryFrom($vars['app']);
        if ($app) {
            $service = new ProviderService($this->repo);
            $service->switchTo($vars['id'], $app);
        } else {
            $this->repo->switchTo($vars['id'], $vars['app']);
        }
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
            $sc = $p['settings_config'] ?? '{}';
            if (is_array($sc)) {
                $sc = json_encode($sc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $mt = $p['meta'] ?? '{}';
            if (is_array($mt)) {
                $mt = json_encode($mt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $data = [
                'id' => $p['id'] ?? Uuid::uuid4()->toString(),
                'app_type' => $appType,
                'name' => $p['name'] ?? '',
                'settings_config' => $sc,
                'category' => $p['category'] ?? null,
                'created_at' => time(),
                'sort_index' => $p['sort_index'] ?? 0,
                'notes' => $p['notes'] ?? null,
                'meta' => $mt,
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
        $app = AppType::tryFrom($vars['app']) ?? AppType::Claude;
        return ProviderService::loadPresets($app);
    }
}
