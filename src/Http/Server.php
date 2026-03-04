<?php

declare(strict_types=1);

namespace CcSwitch\Http;

use CcSwitch\App;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Proxy\ProxyServer;
use CcSwitch\Service\BackupService;
use CcSwitch\Service\WebDavSyncService;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as SwooleServer;

/**
 * Main Swoole HTTP server for the Web UI and API.
 *
 * Serves:
 * - /api/* requests via Router (42 API endpoints)
 * - Static files from public/assets/ via Swoole static handler
 * - All other requests: serve public/index.html (SPA fallback)
 *
 * Optionally attaches the proxy server as a sub-listener.
 */
class Server
{
    private Router $router;
    private string $publicDir;

    public function __construct(
        private readonly App $app,
        private readonly int $webPort = 8080,
        private readonly bool $withProxy = false,
        private readonly int $proxyPort = 15721,
    ) {
        $this->router = new Router($this->app);
        $this->publicDir = dirname(__DIR__, 2) . '/public';
    }

    /**
     * Start the HTTP server (blocking).
     */
    public function start(): void
    {
        $server = new SwooleServer('127.0.0.1', $this->webPort);

        $server->set([
            'worker_num' => 2,
            'enable_coroutine' => false,
            'max_request' => 0,
            'dispatch_mode' => 2,
            'log_level' => SWOOLE_LOG_WARNING,
            'daemonize' => false,
            // Static file serving
            'enable_static_handler' => true,
            'document_root' => $this->publicDir,
            'static_handler_locations' => ['/assets'],
        ]);

        $server->on('start', function (SwooleServer $server) {
            echo "CC Switch server started on http://127.0.0.1:{$this->webPort}\n";
            if ($this->withProxy) {
                echo "Proxy server attached on 127.0.0.1:{$this->proxyPort}\n";
            }
        });

        $server->on('workerStart', function (SwooleServer $server, int $workerId) {
            // Only run periodic tasks on worker 0 to avoid duplication
            if ($workerId !== 0) {
                return;
            }

            $baseDir = $this->app->getBaseDir();
            $settingsRepo = new SettingsRepository($this->app->getMedoo());

            // Health check timer (every 60 seconds)
            \Swoole\Timer::tick(60_000, function () {
                // Circuit breaker periodic check placeholder
            });

            // Auto-backup timer (every 30 minutes, checks interval setting)
            \Swoole\Timer::tick(1_800_000, function () use ($baseDir, $settingsRepo) {
                try {
                    $intervalHours = (int) ($settingsRepo->get('backup_interval_hours') ?? '24');
                    $backupService = new BackupService($baseDir);
                    $backupService->periodicBackupIfNeeded($intervalHours);
                } catch (\Throwable $e) {
                    error_log('[auto-backup] ' . $e->getMessage());
                }
            });

            // Auto-sync timer (every 5 minutes, checks if enabled)
            \Swoole\Timer::tick(300_000, function () use ($baseDir, $settingsRepo) {
                try {
                    $autoSync = $settingsRepo->get('auto_sync');
                    if ($autoSync !== '1') {
                        return;
                    }

                    $webdavUrl = $settingsRepo->get('webdav_url') ?? '';
                    if ($webdavUrl === '') {
                        return;
                    }

                    $config = [
                        'baseUrl' => $webdavUrl,
                        'username' => $settingsRepo->get('webdav_username') ?? '',
                        'password' => $settingsRepo->get('webdav_password') ?? '',
                        'profile' => $settingsRepo->get('webdav_profile') ?? 'default',
                    ];

                    $syncService = new WebDavSyncService($baseDir);
                    $syncService->push($config);
                } catch (\Throwable $e) {
                    error_log('[auto-sync] ' . $e->getMessage());
                }
            });
        });

        $server->on('request', function (Request $request, Response $response) {
            $uri = $request->server['request_uri'] ?? '/';

            // CORS headers for API requests
            $response->header('Access-Control-Allow-Origin', '*');
            $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

            // Handle CORS preflight
            $method = strtoupper($request->server['request_method'] ?? 'GET');
            if ($method === 'OPTIONS') {
                $response->status(204);
                $response->end();
                return;
            }

            // API routes
            if (str_starts_with($uri, '/api/')) {
                $result = $this->router->dispatch($request);
                $response->status($result['status']);
                foreach ($result['headers'] as $key => $val) {
                    $response->header($key, $val);
                }
                $response->end($result['body']);
                return;
            }

            // SPA fallback: serve index.html for all non-API, non-static routes
            $indexFile = $this->publicDir . '/index.html';
            if (file_exists($indexFile)) {
                $response->status(200);
                $response->header('Content-Type', 'text/html; charset=utf-8');
                $response->end(file_get_contents($indexFile));
            } else {
                $response->status(404);
                $response->header('Content-Type', 'text/plain');
                $response->end('index.html not found');
            }
        });

        // Attach proxy server as sub-listener if requested
        if ($this->withProxy) {
            $proxyServer = new ProxyServer(
                $this->app->getMedoo(),
                '127.0.0.1',
                $this->proxyPort,
                $this->app->getBaseDir(),
            );
            $proxyServer->attachTo($server);
        }

        $server->start();
    }
}
