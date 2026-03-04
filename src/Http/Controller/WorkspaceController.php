<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\WorkspaceService;

class WorkspaceController
{
    private WorkspaceService $service;

    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private readonly App $app)
    {
        $this->service = new WorkspaceService();
    }

    public function listFiles(): array
    {
        return $this->service->listFiles();
    }

    public function readFile(array $vars): array
    {
        $name = $vars['name'] ?? '';
        try {
            $content = $this->service->readFile($name);
            if ($content === null) {
                return ['status' => 404, 'body' => ['error' => "File not found: {$name}"]];
            }
            return ['filename' => $name, 'content' => $content];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function writeFile(array $vars, array $body): array
    {
        $name = $vars['name'] ?? '';
        $content = $body['content'] ?? '';
        try {
            $this->service->writeFile($name, $content);
            return ['status' => 200, 'body' => ['ok' => true]];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function listMemory(): array
    {
        return $this->service->listDailyMemory();
    }

    public function readMemory(array $vars): array
    {
        $date = $vars['date'] ?? '';
        try {
            $content = $this->service->readDailyMemory($date);
            if ($content === null) {
                return ['status' => 404, 'body' => ['error' => "Memory not found: {$date}"]];
            }
            return ['date' => $date, 'content' => $content];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function writeMemory(array $vars, array $body): array
    {
        $date = $vars['date'] ?? '';
        $content = $body['content'] ?? '';
        try {
            $this->service->writeDailyMemory($date, $content);
            return ['status' => 200, 'body' => ['ok' => true]];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function deleteMemory(array $vars): array
    {
        $date = $vars['date'] ?? '';
        try {
            $this->service->deleteDailyMemory($date);
            return ['status' => 200, 'body' => ['ok' => true]];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function searchMemory(array $vars, array $body, array $query): array
    {
        $q = $query['q'] ?? '';
        return $this->service->searchDailyMemory($q);
    }
}
