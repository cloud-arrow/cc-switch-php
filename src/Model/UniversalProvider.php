<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Universal provider model (cross-app shared provider configuration).
 */
class UniversalProvider
{
    public string $id = '';
    public string $name = '';
    public string $provider_type = '';
    public string $apps = '{}';
    public string $base_url = '';
    public string $api_key = '';
    public string $models = '{}';
    public ?string $website_url = null;
    public ?string $notes = null;
    public ?int $created_at = null;

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (string) ($row['id'] ?? '');
        $model->name = (string) ($row['name'] ?? '');
        $model->provider_type = (string) ($row['provider_type'] ?? '');
        $model->apps = (string) ($row['apps'] ?? '{}');
        $model->base_url = (string) ($row['base_url'] ?? '');
        $model->api_key = (string) ($row['api_key'] ?? '');
        $model->models = (string) ($row['models'] ?? '{}');
        $model->website_url = $row['website_url'] ?? null;
        $model->notes = $row['notes'] ?? null;
        $model->created_at = isset($row['created_at']) ? (int) $row['created_at'] : null;
        return $model;
    }
}
