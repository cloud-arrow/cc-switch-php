<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Skill model representing a row in the skills table.
 */
class Skill
{
    public string $id = '';
    public string $name = '';
    public ?string $description = null;
    public string $directory = '';
    public ?string $repo_owner = null;
    public ?string $repo_name = null;
    public string $repo_branch = 'main';
    public ?string $readme_url = null;
    public int $enabled_claude = 0;
    public int $enabled_codex = 0;
    public int $enabled_gemini = 0;
    public int $enabled_opencode = 0;
    public int $installed_at = 0;

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (string) ($row['id'] ?? '');
        $model->name = (string) ($row['name'] ?? '');
        $model->description = $row['description'] ?? null;
        $model->directory = (string) ($row['directory'] ?? '');
        $model->repo_owner = $row['repo_owner'] ?? null;
        $model->repo_name = $row['repo_name'] ?? null;
        $model->repo_branch = (string) ($row['repo_branch'] ?? 'main');
        $model->readme_url = $row['readme_url'] ?? null;
        $model->enabled_claude = (int) ($row['enabled_claude'] ?? 0);
        $model->enabled_codex = (int) ($row['enabled_codex'] ?? 0);
        $model->enabled_gemini = (int) ($row['enabled_gemini'] ?? 0);
        $model->enabled_opencode = (int) ($row['enabled_opencode'] ?? 0);
        $model->installed_at = (int) ($row['installed_at'] ?? 0);
        return $model;
    }
}
