<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\ClaudePluginService;

class ClaudePluginController
{
    private ClaudePluginService $service;

    public function __construct(private readonly App $app)
    {
        $repo = new SettingsRepository($this->app->getMedoo());
        $overrideDir = $repo->get('claude_override_dir');
        $this->service = new ClaudePluginService($overrideDir);
    }

    public function status(): array
    {
        return $this->service->getStatus();
    }

    public function apply(): array
    {
        $changed = $this->service->apply();
        return ['ok' => true, 'changed' => $changed];
    }

    public function clear(): array
    {
        $changed = $this->service->clear();
        return ['ok' => true, 'changed' => $changed];
    }
}
