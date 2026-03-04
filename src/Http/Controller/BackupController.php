<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\BackupService;

class BackupController
{
    private BackupService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new BackupService($this->app->getBaseDir());
    }

    public function list(): array
    {
        return $this->service->list();
    }

    public function create(): array
    {
        $path = $this->service->run();
        return ['status' => 200, 'body' => ['ok' => true, 'path' => $path]];
    }

    public function restore(array $vars, array $body): array
    {
        $filename = $body['filename'] ?? '';
        if ($filename === '') {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: filename']];
        }

        $this->service->restore($filename);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function cleanup(array $vars, array $body): array
    {
        $retainCount = (int) ($body['retain_count'] ?? 10);
        $this->service->cleanup($retainCount);
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
