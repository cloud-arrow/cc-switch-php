<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\WebDavSyncService;

class SyncController
{
    private WebDavSyncService $syncService;
    private SettingsRepository $settingsRepo;

    public function __construct(private readonly App $app)
    {
        $this->syncService = new WebDavSyncService($this->app->getBaseDir());
        $this->settingsRepo = new SettingsRepository($this->app->getMedoo());
    }

    public function push(array $vars, array $body): array
    {
        $config = $this->resolveConfig($body);
        if (empty($config['baseUrl'])) {
            return ['status' => 400, 'body' => ['error' => 'WebDAV base URL is required']];
        }

        $this->syncService->push($config);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function pull(array $vars, array $body): array
    {
        $config = $this->resolveConfig($body);
        if (empty($config['baseUrl'])) {
            return ['status' => 400, 'body' => ['error' => 'WebDAV base URL is required']];
        }

        $this->syncService->pull($config);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function test(array $vars, array $body): array
    {
        $config = $this->resolveConfig($body);
        if (empty($config['baseUrl'])) {
            return ['status' => 400, 'body' => ['error' => 'WebDAV base URL is required']];
        }

        $ok = $this->syncService->testConnection($config);
        return ['status' => 200, 'body' => ['connected' => $ok]];
    }

    /**
     * Resolve WebDAV config from request body or saved settings.
     *
     * @return array{baseUrl: string, username: string, password: string, remoteRoot?: string, profile?: string}
     */
    private function resolveConfig(array $body): array
    {
        if (!empty($body['baseUrl'])) {
            return [
                'baseUrl' => $body['baseUrl'],
                'username' => $body['username'] ?? '',
                'password' => $body['password'] ?? '',
                'remoteRoot' => $body['remoteRoot'] ?? '',
                'profile' => $body['profile'] ?? 'default',
            ];
        }

        return [
            'baseUrl' => $this->settingsRepo->get('webdav_url') ?? '',
            'username' => $this->settingsRepo->get('webdav_username') ?? '',
            'password' => $this->settingsRepo->get('webdav_password') ?? '',
            'remoteRoot' => $this->settingsRepo->get('webdav_remote_root') ?? '',
            'profile' => $this->settingsRepo->get('webdav_profile') ?? 'default',
        ];
    }
}
