<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Database\Repository\FailoverQueueRepository;
use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ModelPricingRepository;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Database\Repository\RequestLogRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\GlobalProxyService;
use Medoo\Medoo;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Swoole HTTP proxy server.
 *
 * Listens on 127.0.0.1:15721 (configurable) and delegates all requests
 * to RequestHandler. Can run standalone or be attached as a sub-listener
 * to a main Swoole server.
 */
class ProxyServer
{
    private ?Server $server = null;
    private RequestHandler $handler;
    private string $host;
    private int $port;
    private string $pidFile;

    public function __construct(
        private readonly Medoo $db,
        string $host = '127.0.0.1',
        int $port = 15721,
        ?string $baseDir = null,
    ) {
        $this->host = $host;
        $this->port = $port;

        $baseDir = $baseDir ?? (getenv('HOME') ?: $_SERVER['HOME'] ?? '') . '/.cc-switch';
        $this->pidFile = $baseDir . '/proxy.pid';

        // Wire up all dependencies
        $healthRepo = new HealthRepository($this->db);
        $configRepo = new ProxyConfigRepository($this->db);
        $providerRepo = new ProviderRepository($this->db);
        $failoverRepo = new FailoverQueueRepository($this->db);
        $logRepo = new RequestLogRepository($this->db);

        $pricingRepo = new ModelPricingRepository($this->db);
        $settingsRepo = new SettingsRepository($this->db);
        $globalProxyService = new GlobalProxyService($settingsRepo);

        $circuitBreaker = new CircuitBreaker($healthRepo, $configRepo);
        $failoverManager = new FailoverManager($failoverRepo, $providerRepo, $circuitBreaker);
        $modelMapper = new ModelMapper();
        $formatConverter = new FormatConverter();
        $streamHandler = new StreamHandler();
        $usageLogger = new UsageLogger($logRepo, $pricingRepo);

        $this->handler = new RequestHandler(
            $failoverManager,
            $circuitBreaker,
            $modelMapper,
            $formatConverter,
            $streamHandler,
            $usageLogger,
            $configRepo,
            $settingsRepo,
        );

        // Apply global proxy settings
        $proxyOptions = $globalProxyService->getGuzzleProxyOptions();
        if (!empty($proxyOptions)) {
            $this->handler->setProxyOptions($proxyOptions);
        }
    }

    /**
     * Start the proxy server (blocking).
     */
    public function start(): void
    {
        $this->server = new Server($this->host, $this->port);

        $this->server->set([
            'worker_num' => 4,
            'enable_coroutine' => false,
            'max_request' => 0,
            'dispatch_mode' => 2,
            'log_level' => SWOOLE_LOG_WARNING,
            'daemonize' => false,
        ]);

        $this->server->on('start', function (Server $server) {
            // Write PID file for stop command
            file_put_contents($this->pidFile, (string) $server->master_pid);
            echo "Proxy server started on {$this->host}:{$this->port} (PID: {$server->master_pid})\n";
        });

        $this->server->on('shutdown', function () {
            if (file_exists($this->pidFile)) {
                @unlink($this->pidFile);
            }
            echo "Proxy server stopped.\n";
        });

        $this->server->on('request', function (Request $request, Response $response) {
            try {
                $this->handler->handle($request, $response);
            } catch (\Throwable $e) {
                $response->status(500);
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'error' => [
                        'message' => 'Internal proxy error: ' . $e->getMessage(),
                        'type' => 'proxy_error',
                    ],
                ]));
            }
        });

        $this->server->start();
    }

    /**
     * Attach the proxy as a sub-listener to an existing Swoole server.
     */
    public function attachTo(Server $mainServer): void
    {
        $listener = $mainServer->addListener($this->host, $this->port, SWOOLE_SOCK_TCP);
        if ($listener === false) {
            throw new \RuntimeException("Failed to add listener on {$this->host}:{$this->port}");
        }

        $listener->on('request', function (Request $request, Response $response) {
            try {
                $this->handler->handle($request, $response);
            } catch (\Throwable $e) {
                $response->status(500);
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'error' => [
                        'message' => 'Internal proxy error: ' . $e->getMessage(),
                        'type' => 'proxy_error',
                    ],
                ]));
            }
        });
    }

    /**
     * Stop the proxy server by reading the PID file and sending SIGTERM.
     *
     * @return bool True if the stop signal was sent successfully
     */
    public static function stop(?string $baseDir = null): bool
    {
        $baseDir = $baseDir ?? (getenv('HOME') ?: $_SERVER['HOME'] ?? '') . '/.cc-switch';
        $pidFile = $baseDir . '/proxy.pid';

        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int) file_get_contents($pidFile);
        if ($pid <= 0) {
            @unlink($pidFile);
            return false;
        }

        // Check if process is running
        if (!posix_kill($pid, 0)) {
            @unlink($pidFile);
            return false;
        }

        // Send SIGTERM
        $result = posix_kill($pid, SIGTERM);
        if ($result) {
            @unlink($pidFile);
        }

        return $result;
    }

    /**
     * Check if the proxy server is running.
     */
    public static function isRunning(?string $baseDir = null): bool
    {
        $baseDir = $baseDir ?? (getenv('HOME') ?: $_SERVER['HOME'] ?? '') . '/.cc-switch';
        $pidFile = $baseDir . '/proxy.pid';

        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int) file_get_contents($pidFile);
        if ($pid <= 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getHandler(): RequestHandler
    {
        return $this->handler;
    }
}
