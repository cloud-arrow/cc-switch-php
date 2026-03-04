<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * DTO for application settings (stored as key-value pairs in the settings table).
 */
class Settings
{
    public ?string $theme = null;
    public ?string $language = null;
    public ?string $autoBackup = null;
    public ?string $backupDir = null;
    public ?string $proxyPort = null;
    public ?string $webPort = null;

    /**
     * Create from a key-value array.
     *
     * @param array<string, string|null> $data
     */
    public static function fromArray(array $data): self
    {
        $settings = new self();
        $settings->theme = $data['theme'] ?? null;
        $settings->language = $data['language'] ?? null;
        $settings->autoBackup = $data['auto_backup'] ?? null;
        $settings->backupDir = $data['backup_dir'] ?? null;
        $settings->proxyPort = $data['proxy_port'] ?? null;
        $settings->webPort = $data['web_port'] ?? null;
        return $settings;
    }
}
