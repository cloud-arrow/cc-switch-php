<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\RequestLogRepository;
use CcSwitch\Service\UsageStatsService;

class UsageController
{
    private RequestLogRepository $repo;
    private UsageStatsService $statsService;

    public function __construct(private readonly App $app)
    {
        $this->repo = new RequestLogRepository($this->app->getMedoo());
        $this->statsService = new UsageStatsService($this->app->getPdo());
    }

    public function stats(array $vars, array $body, array $query): array
    {
        $app = $query['app'] ?? 'claude';
        $startTime = isset($query['start']) ? (int) $query['start'] : time() - 86400;
        $endTime = isset($query['end']) ? (int) $query['end'] : time();

        return $this->repo->stats($app, $startTime, $endTime);
    }

    public function logs(array $vars, array $body, array $query): array
    {
        $filters = [];
        if (!empty($query['app'])) {
            $filters['app_type'] = $query['app'];
        }
        if (!empty($query['provider_id'])) {
            $filters['provider_id'] = $query['provider_id'];
        }

        $limit = isset($query['limit']) ? min((int) $query['limit'], 500) : 100;
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;

        return $this->repo->list($filters, $limit, $offset);
    }

    public function summary(array $vars, array $body, array $query): array
    {
        $start = isset($query['start']) ? (int) $query['start'] : null;
        $end = isset($query['end']) ? (int) $query['end'] : null;
        return $this->statsService->getSummary($start, $end);
    }

    public function trends(array $vars, array $body, array $query): array
    {
        $start = isset($query['start']) ? (int) $query['start'] : null;
        $end = isset($query['end']) ? (int) $query['end'] : null;
        return $this->statsService->getTrends($start, $end);
    }

    public function providers(array $vars, array $body, array $query): array
    {
        $start = isset($query['start']) ? (int) $query['start'] : null;
        $end = isset($query['end']) ? (int) $query['end'] : null;
        return $this->statsService->getProviderStats($start, $end);
    }

    public function models(array $vars, array $body, array $query): array
    {
        $start = isset($query['start']) ? (int) $query['start'] : null;
        $end = isset($query['end']) ? (int) $query['end'] : null;
        return $this->statsService->getModelStats($start, $end);
    }

    public function detail(array $vars, array $body, array $query): array
    {
        $result = $this->statsService->getRequestDetail($vars['id']);
        if ($result === null) {
            return ['status' => 404, 'body' => ['error' => 'Log not found']];
        }
        return $result;
    }
}
