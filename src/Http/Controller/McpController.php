<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\McpService;

class McpController
{
    private McpService $service;

    public function __construct(private readonly App $app)
    {
        $medoo = $this->app->getMedoo();
        $this->service = new McpService(
            new McpRepository($medoo),
            new SettingsRepository($medoo),
        );
    }

    public function list(): array
    {
        $servers = $this->service->list();
        return array_map(fn($s) => get_object_vars($s), $servers);
    }

    public function upsert(array $vars, array $body): array
    {
        if (empty($body['id'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: id']];
        }

        $server = $this->service->upsert($body);
        return ['status' => 200, 'body' => get_object_vars($server)];
    }

    public function delete(array $vars): array
    {
        $this->service->delete($vars['id']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function sync(): array
    {
        $this->service->syncAll();
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
