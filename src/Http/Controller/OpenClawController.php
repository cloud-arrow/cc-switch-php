<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\OpenClawConfigService;

class OpenClawController
{
    private OpenClawConfigService $service;

    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private readonly App $app)
    {
        $this->service = new OpenClawConfigService();
    }

    public function getDefaultModel(): array
    {
        return $this->service->getDefaultModel();
    }

    public function setDefaultModel(array $vars, array $body): array
    {
        $this->service->setDefaultModel($body);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function getModelCatalog(): array
    {
        return $this->service->getModelCatalog();
    }

    public function setModelCatalog(array $vars, array $body): array
    {
        $this->service->setModelCatalog($body);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function getAgentsDefaults(): array
    {
        return $this->service->getAgentsDefaults();
    }

    public function setAgentsDefaults(array $vars, array $body): array
    {
        $this->service->setAgentsDefaults($body);
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
