<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\FailoverQueueRepository;
use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Proxy\CircuitBreaker;
use CcSwitch\Proxy\ProxyServer;
use CcSwitch\Service\LiveTakeoverService;
use CcSwitch\Service\ProxyConfigService;

class ProxyController
{
    private ProxyConfigService $configService;
    private FailoverQueueRepository $failoverRepo;

    public function __construct(private readonly App $app)
    {
        $medoo = $this->app->getMedoo();
        $configRepo = new ProxyConfigRepository($medoo);
        $healthRepo = new HealthRepository($medoo);
        $circuitBreaker = new CircuitBreaker($healthRepo, $configRepo);

        $this->configService = new ProxyConfigService($configRepo, $healthRepo, $circuitBreaker);
        $this->failoverRepo = new FailoverQueueRepository($medoo);
    }

    public function status(): array
    {
        $running = ProxyServer::isRunning($this->app->getBaseDir());
        return ['running' => $running];
    }

    public function start(array $vars, array $body): array
    {
        if (ProxyServer::isRunning($this->app->getBaseDir())) {
            return ['status' => 409, 'body' => ['error' => 'Proxy is already running']];
        }

        // The proxy server must be started as a separate process
        return ['status' => 200, 'body' => ['message' => 'Use CLI command "cc-switch proxy:start" to start the proxy server']];
    }

    public function stop(): array
    {
        $stopped = ProxyServer::stop($this->app->getBaseDir());
        if (!$stopped) {
            return ['status' => 404, 'body' => ['error' => 'Proxy is not running']];
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function getConfig(array $vars): array
    {
        $config = $this->configService->getConfig($vars['app']);
        return (array) $config;
    }

    public function updateConfig(array $vars, array $body): array
    {
        $this->configService->updateConfig($vars['app'], $body);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function health(array $vars): array
    {
        $status = $this->configService->getHealthStatus($vars['app']);
        return [
            'config' => (array) $status['config'],
            'circuit_breaker' => $status['circuit_breaker'],
        ];
    }

    public function failoverList(array $vars): array
    {
        return $this->failoverRepo->list($vars['app']);
    }

    public function failoverAdd(array $vars, array $body): array
    {
        $providerId = $body['provider_id'] ?? '';
        if ($providerId === '') {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: provider_id']];
        }

        $position = $body['position'] ?? 0;
        $this->failoverRepo->add($vars['app'], $providerId, (int) $position);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function failoverRemove(array $vars): array
    {
        $this->failoverRepo->remove($vars['app'], $vars['providerId']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function circuitBreakerList(array $vars): array
    {
        $healthRepo = new HealthRepository($this->app->getMedoo());
        return $healthRepo->listByApp($vars['app']);
    }

    public function circuitBreakerReset(array $vars): array
    {
        $healthRepo = new HealthRepository($this->app->getMedoo());
        $healthRepo->reset($vars['providerId'], $vars['app']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function takeoverStatus(): array
    {
        $service = new LiveTakeoverService(new SettingsRepository($this->app->getMedoo()));
        return $service->getBackupStatus();
    }

    public function takeoverEnable(array $vars, array $body): array
    {
        $host = $body['host'] ?? '127.0.0.1';
        $port = (int) ($body['port'] ?? 15721);
        $service = new LiveTakeoverService(new SettingsRepository($this->app->getMedoo()));
        try {
            $service->takeover($vars['app'], $host, $port);
            return ['status' => 200, 'body' => ['ok' => true]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function takeoverDisable(array $vars): array
    {
        $service = new LiveTakeoverService(new SettingsRepository($this->app->getMedoo()));
        try {
            $service->restore($vars['app']);
            return ['status' => 200, 'body' => ['ok' => true]];
        } catch (\Throwable $e) {
            return ['status' => 500, 'body' => ['error' => $e->getMessage()]];
        }
    }
}
