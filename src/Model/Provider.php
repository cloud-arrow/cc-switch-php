<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Provider model representing a row in the providers table.
 */
class Provider
{
    public string $id = '';
    public string $app_type = '';
    public string $name = '';
    public string $settings_config = '';
    public ?string $website_url = null;
    public ?string $category = null;
    public ?int $created_at = null;
    public ?int $sort_index = null;
    public ?string $notes = null;
    public ?string $icon = null;
    public ?string $icon_color = null;
    public string $meta = '{}';
    public int $is_current = 0;
    public int $in_failover_queue = 0;

    /**
     * Create a Provider from a database row array.
     */
    public static function fromRow(array $row): self
    {
        $provider = new self();
        $provider->id = (string) ($row['id'] ?? '');
        $provider->app_type = (string) ($row['app_type'] ?? '');
        $provider->name = (string) ($row['name'] ?? '');
        $provider->settings_config = (string) ($row['settings_config'] ?? '');
        $provider->website_url = $row['website_url'] ?? null;
        $provider->category = $row['category'] ?? null;
        $provider->created_at = isset($row['created_at']) ? (int) $row['created_at'] : null;
        $provider->sort_index = isset($row['sort_index']) ? (int) $row['sort_index'] : null;
        $provider->notes = $row['notes'] ?? null;
        $provider->icon = $row['icon'] ?? null;
        $provider->icon_color = $row['icon_color'] ?? null;
        $provider->meta = (string) ($row['meta'] ?? '{}');
        $provider->is_current = (int) ($row['is_current'] ?? 0);
        $provider->in_failover_queue = (int) ($row['in_failover_queue'] ?? 0);
        return $provider;
    }

    /**
     * Decode the meta JSON field into a ProviderMeta object.
     */
    public function decodeMeta(): ProviderMeta
    {
        return ProviderMeta::fromJson($this->meta);
    }
}
