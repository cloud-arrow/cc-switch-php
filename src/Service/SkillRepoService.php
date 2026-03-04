<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\SkillRepoRepository;
use CcSwitch\Database\Repository\SkillRepository;

class SkillRepoService
{
    private string $home;

    public function __construct(
        private readonly SkillRepoRepository $repo,
        private readonly SkillRepository $skillRepo,
    ) {
        $this->home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    }

    public function listRepos(): array
    {
        return $this->repo->list();
    }

    public function addRepo(string $owner, string $name, string $branch = 'main'): void
    {
        $existing = $this->repo->get($owner, $name);
        if ($existing) {
            throw new \RuntimeException("Repo {$owner}/{$name} already exists");
        }
        $this->repo->insert([
            'owner' => $owner,
            'name' => $name,
            'branch' => $branch,
            'enabled' => 1,
        ]);
    }

    public function removeRepo(string $owner, string $name): void
    {
        $this->repo->delete($owner, $name);
    }

    public function discoverSkills(string $owner, string $name): array
    {
        $client = new \GuzzleHttp\Client(['timeout' => 15]);
        try {
            $response = $client->get("https://api.github.com/repos/{$owner}/{$name}/contents/", [
                'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'cc-switch-php'],
            ]);
            $items = json_decode((string) $response->getBody(), true);
            if (!is_array($items)) {
                return [];
            }

            $directories = [];
            foreach ($items as $item) {
                if (($item['type'] ?? '') === 'dir') {
                    $directories[] = [
                        'name' => $item['name'],
                        'path' => $item['path'],
                        'url' => $item['html_url'] ?? '',
                    ];
                }
            }
            return $directories;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function scanUnmanaged(): array
    {
        $unmanaged = [];
        $managedDirs = array_map(fn($s) => $s['directory'] ?? '', $this->skillRepo->list());

        $appDirs = [
            'claude' => $this->home . '/.claude/commands',
            'codex' => $this->home . '/.codex/skills',
            'gemini' => $this->home . '/.gemini/skills',
            'opencode' => $this->home . '/.config/opencode/skills',
        ];

        foreach ($appDirs as $app => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!is_dir($dir . '/' . $entry) && !is_link($dir . '/' . $entry)) {
                    continue;
                }
                if (!in_array($entry, $managedDirs, true)) {
                    $unmanaged[] = ['app' => $app, 'directory' => $entry, 'path' => $dir . '/' . $entry];
                }
            }
        }
        return $unmanaged;
    }

    public function importFromApps(): array
    {
        $unmanaged = $this->scanUnmanaged();
        $imported = 0;
        foreach ($unmanaged as $item) {
            $id = "local:{$item['app']}:{$item['directory']}";
            $existing = $this->skillRepo->get($id);
            if ($existing) {
                continue;
            }

            $this->skillRepo->insert([
                'id' => $id,
                'name' => $item['directory'],
                'description' => "Imported from {$item['app']} skills",
                'directory' => $item['directory'],
                'repo_owner' => 'local',
                'repo_name' => $item['app'],
                'repo_branch' => '',
                'readme_url' => '',
                'enabled_claude' => $item['app'] === 'claude' ? 1 : 0,
                'enabled_codex' => $item['app'] === 'codex' ? 1 : 0,
                'enabled_gemini' => $item['app'] === 'gemini' ? 1 : 0,
                'enabled_opencode' => $item['app'] === 'opencode' ? 1 : 0,
                'installed_at' => time(),
            ]);
            $imported++;
        }
        return ['imported' => $imported, 'scanned' => count($unmanaged)];
    }
}
