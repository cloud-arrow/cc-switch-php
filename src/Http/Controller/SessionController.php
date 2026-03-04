<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\SessionService;

class SessionController
{
    private SessionService $service;

    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private readonly App $app)
    {
        $this->service = new SessionService();
    }

    public function list(): array
    {
        return $this->service->scan();
    }

    public function resumeCommand(array $vars, array $body, array $query): array
    {
        $sessionId = $vars['id'] ?? '';
        $appType = $query['app'] ?? 'claude';

        $command = $this->service->getResumeCommand($sessionId, $appType);
        return ['command' => $command];
    }
}
