<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\EnvCheckerService;

class EnvController
{
    private EnvCheckerService $service;

    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private readonly App $app)
    {
        $this->service = new EnvCheckerService();
    }

    public function check(): array
    {
        return $this->service->check();
    }

    public function delete(array $vars, array $body): array
    {
        $conflicts = $body['conflicts'] ?? [];
        if (!is_array($conflicts) || empty($conflicts)) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: conflicts']];
        }

        $result = $this->service->deleteConflicts($conflicts);
        return ['status' => 200, 'body' => $result];
    }

    public function restore(array $vars, array $body): array
    {
        $backupFile = $body['backup_file'] ?? '';
        if ($backupFile === '') {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: backup_file']];
        }

        $this->service->restoreBackup($backupFile);
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
