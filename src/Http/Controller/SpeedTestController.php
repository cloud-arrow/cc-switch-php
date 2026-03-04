<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Service\SpeedTestService;

class SpeedTestController
{
    /** @phpstan-ignore property.onlyWritten */
    public function __construct(private readonly App $app)
    {
    }

    public function test(array $vars, array $body): array
    {
        $urls = $body['urls'] ?? [];
        if (empty($urls) || !is_array($urls)) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: urls (array)']];
        }

        $timeout = $body['timeout'] ?? 10;
        $service = new SpeedTestService();
        $results = $service->test($urls, (int) $timeout);

        return ['status' => 200, 'body' => ['results' => $results]];
    }
}
