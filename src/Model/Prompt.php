<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Prompt model representing a row in the prompts table.
 */
class Prompt
{
    public string $id = '';
    public string $app_type = '';
    public string $name = '';
    public string $content = '';
    public ?string $description = null;
    public int $enabled = 1;
    public ?int $created_at = null;
    public ?int $updated_at = null;

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (string) ($row['id'] ?? '');
        $model->app_type = (string) ($row['app_type'] ?? '');
        $model->name = (string) ($row['name'] ?? '');
        $model->content = (string) ($row['content'] ?? '');
        $model->description = $row['description'] ?? null;
        $model->enabled = (int) ($row['enabled'] ?? 1);
        $model->created_at = isset($row['created_at']) ? (int) $row['created_at'] : null;
        $model->updated_at = isset($row['updated_at']) ? (int) $row['updated_at'] : null;
        return $model;
    }
}
