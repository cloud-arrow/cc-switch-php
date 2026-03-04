<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\ModelPricingRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\GlobalProxyService;

class SettingsController
{
    private SettingsRepository $repo;
    private GlobalProxyService $proxyService;
    private ModelPricingRepository $pricingRepo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new SettingsRepository($this->app->getMedoo());
        $this->pricingRepo = new ModelPricingRepository($this->app->getMedoo());
        $this->proxyService = new GlobalProxyService($this->repo);
    }

    public function get(): array
    {
        return $this->repo->getAll();
    }

    public function update(array $vars, array $body): array
    {
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($value === null) {
                $this->repo->delete($key);
            } else {
                $this->repo->set($key, (string) $value);
            }
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    // --- Proxy endpoints ---

    public function getProxy(): array
    {
        $url = $this->proxyService->getProxyUrl();
        return [
            'url' => $url,
            'enabled' => $url !== null && $url !== '',
        ];
    }

    public function setProxy(array $vars, array $body): array
    {
        $url = $body['url'] ?? null;

        try {
            $this->proxyService->setProxyUrl($url);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }

        return ['status' => 200, 'body' => ['ok' => true, 'url' => $url]];
    }

    public function testProxy(array $vars, array $body): array
    {
        $url = $body['url'] ?? $this->proxyService->getProxyUrl();

        if ($url === null || $url === '') {
            return ['status' => 400, 'body' => ['error' => 'No proxy URL provided']];
        }

        try {
            $result = $this->proxyService->testProxy($url);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }

        return $result;
    }

    public function scanProxy(): array
    {
        return $this->proxyService->scanLocalProxies();
    }

    // --- Pricing endpoints ---

    public function getPricing(): array
    {
        return $this->pricingRepo->findAll();
    }

    public function updatePricing(array $vars, array $body): array
    {
        $modelId = $vars['id'] ?? '';
        if ($modelId === '') {
            return ['status' => 400, 'body' => ['error' => 'Missing model ID']];
        }

        $body['model_id'] = $modelId;
        $this->pricingRepo->upsert($body);

        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function addPricing(array $vars, array $body): array
    {
        if (empty($body['model_id']) || empty($body['display_name'])) {
            return ['status' => 400, 'body' => ['error' => 'model_id and display_name are required']];
        }

        $this->pricingRepo->upsert([
            'model_id' => $body['model_id'],
            'display_name' => $body['display_name'],
            'input_cost_per_million' => $body['input_cost_per_million'] ?? '0',
            'output_cost_per_million' => $body['output_cost_per_million'] ?? '0',
            'cache_read_cost_per_million' => $body['cache_read_cost_per_million'] ?? '0',
            'cache_creation_cost_per_million' => $body['cache_creation_cost_per_million'] ?? '0',
        ]);

        return ['status' => 201, 'body' => ['ok' => true]];
    }

    // --- Rectifier endpoints ---

    public function getRectifier(): array
    {
        return [
            'signature_enabled' => ($this->repo->get('rectifier_signature_enabled') ?? '1') === '1',
            'budget_enabled' => ($this->repo->get('rectifier_budget_enabled') ?? '1') === '1',
        ];
    }

    public function setRectifier(array $vars, array $body): array
    {
        if (isset($body['signature_enabled'])) {
            $this->repo->set('rectifier_signature_enabled', $body['signature_enabled'] ? '1' : '0');
        }
        if (isset($body['budget_enabled'])) {
            $this->repo->set('rectifier_budget_enabled', $body['budget_enabled'] ? '1' : '0');
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
