<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Model\Settings;

/**
 * Application settings service for key-value configuration management.
 */
class SettingsService
{
    public function __construct(
        private readonly SettingsRepository $repo,
    ) {
    }

    /**
     * Get a single setting value by key.
     */
    public function get(string $key): ?string
    {
        return $this->repo->get($key);
    }

    /**
     * Set a single setting value.
     */
    public function set(string $key, string $value): void
    {
        $this->repo->set($key, $value);
    }

    /**
     * Delete a setting by key.
     */
    public function delete(string $key): void
    {
        $this->repo->delete($key);
    }

    /**
     * Get all settings as a key-value array.
     *
     * @return array<string, string|null>
     */
    public function getAll(): array
    {
        return $this->repo->getAll();
    }

    /**
     * Get all settings as a Settings DTO.
     */
    public function getSettings(): Settings
    {
        return Settings::fromArray($this->repo->getAll());
    }

    /**
     * Bulk update settings from an associative array.
     *
     * @param array<string, string> $data
     */
    public function updateAll(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->repo->set($key, $value);
        }
    }

    /**
     * Get the current proxy port (with default).
     */
    public function getProxyPort(): int
    {
        $port = $this->repo->get('proxy_port');
        return $port !== null ? (int) $port : 15721;
    }

    /**
     * Get the current web UI port (with default).
     */
    public function getWebPort(): int
    {
        $port = $this->repo->get('web_port');
        return $port !== null ? (int) $port : 8080;
    }
}
