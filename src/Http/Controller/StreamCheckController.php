<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\StreamCheckRepository;
use CcSwitch\Model\Provider;
use CcSwitch\Model\StreamCheckConfig;
use CcSwitch\Service\StreamCheckService;

class StreamCheckController
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * POST /api/stream-check/{appType}/{providerId}
     */
    public function checkOne(array $vars, array $body): array
    {
        $appType = $vars['appType'] ?? '';
        $providerId = $vars['providerId'] ?? '';

        $providerRepo = new ProviderRepository($this->app->getMedoo());
        $row = $providerRepo->get($providerId, $appType);

        if ($row === null) {
            return ['status' => 404, 'body' => ['error' => 'Provider not found']];
        }

        $provider = Provider::fromRow($row);
        $service = $this->buildService();
        $result = $service->checkProvider($appType, $provider);

        return ['status' => 200, 'body' => $result->toArray()];
    }

    /**
     * POST /api/stream-check/{appType}
     */
    public function checkAll(array $vars, array $body): array
    {
        $appType = $vars['appType'] ?? '';
        $proxyTargetsOnly = (bool) ($body['proxy_targets_only'] ?? false);

        $service = $this->buildService();
        $results = $service->checkAllProviders($appType, $proxyTargetsOnly);

        $output = [];
        foreach ($results as $providerId => $result) {
            $output[$providerId] = $result->toArray();
        }

        return ['status' => 200, 'body' => ['results' => $output]];
    }

    /**
     * GET /api/stream-check/config
     */
    public function getConfig(array $vars, array $body): array
    {
        $repo = $this->buildRepo();
        $config = $repo->getConfig();

        return ['status' => 200, 'body' => $config->toArray()];
    }

    /**
     * PUT /api/stream-check/config
     */
    public function saveConfig(array $vars, array $body): array
    {
        $config = StreamCheckConfig::fromArray($body);
        $repo = $this->buildRepo();
        $repo->saveConfig($config);

        return ['status' => 200, 'body' => $config->toArray()];
    }

    private function buildService(): StreamCheckService
    {
        $providerRepo = new ProviderRepository($this->app->getMedoo());
        $repo = $this->buildRepo();
        return new StreamCheckService($repo, $providerRepo);
    }

    private function buildRepo(): StreamCheckRepository
    {
        $settingsRepo = new SettingsRepository($this->app->getMedoo());
        return new StreamCheckRepository($this->app->getMedoo(), $settingsRepo);
    }
}
