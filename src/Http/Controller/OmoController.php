<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\OmoService;

class OmoController
{
    private OmoService $service;

    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private readonly App $app)
    {
        $this->service = new OmoService();
    }

    public function get(array $vars): array
    {
        $variant = $vars['variant'] ?? '';
        try {
            return $this->service->importFromFile($variant);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return ['status' => 404, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function import(array $vars): array
    {
        $variant = $vars['variant'] ?? '';
        try {
            $data = $this->service->importFromFile($variant);
            return ['ok' => true, 'data' => $data];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return ['status' => 404, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function export(array $vars, array $body): array
    {
        $variant = $vars['variant'] ?? '';
        try {
            $this->service->exportToFile($variant, $body);
            return ['status' => 200, 'body' => ['ok' => true]];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }
}
