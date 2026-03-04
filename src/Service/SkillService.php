<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\SkillRepository;
use CcSwitch\Model\Skill;

/**
 * Skill management service.
 *
 * Handles installing, uninstalling, and syncing skills from GitHub repositories
 * to various app skill directories (Claude, Codex, Gemini, OpenCode).
 *
 * Skills are stored in ~/.cc-switch/skills/ (SSOT) and synced to app directories.
 */
class SkillService
{
    private string $home;
    private string $baseDir;

    public function __construct(
        private readonly SkillRepository $repo,
        private readonly SettingsRepository $settingsRepo,
    ) {
        $this->home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        $this->baseDir = $this->home . '/.cc-switch';
    }

    /**
     * @return Skill[]
     */
    public function list(): array
    {
        $rows = $this->repo->list();
        return array_map([Skill::class, 'fromRow'], $rows);
    }

    public function get(string $id): ?Skill
    {
        $row = $this->repo->get($id);
        return $row ? Skill::fromRow($row) : null;
    }

    /**
     * Install a skill by downloading from a GitHub repository.
     *
     * @param array{repo_owner: string, repo_name: string, directory: string, repo_branch?: string, name?: string, description?: string} $data
     * @throws \RuntimeException on failure
     */
    public function install(array $data): Skill
    {
        $owner = $data['repo_owner'];
        $repoName = $data['repo_name'];
        $directory = $data['directory'];
        $branch = $data['repo_branch'] ?? 'main';

        $ssotDir = $this->getSsotDir();
        $installName = basename($directory);
        $dest = $ssotDir . '/' . $installName;

        if (!is_dir($dest)) {
            $this->downloadFromGitHub($owner, $repoName, $branch, $directory, $dest);
        }

        $id = $data['id'] ?? "{$owner}/{$repoName}:{$directory}";
        $name = $data['name'] ?? $installName;

        $skillData = [
            'id' => $id,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'directory' => $installName,
            'repo_owner' => $owner,
            'repo_name' => $repoName,
            'repo_branch' => $branch,
            'readme_url' => $data['readme_url'] ?? "https://github.com/{$owner}/{$repoName}/tree/{$branch}/{$directory}/SKILL.md",
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
            'installed_at' => time(),
        ];

        $this->repo->insert($skillData);

        $skill = Skill::fromRow($skillData);

        // Sync to Claude by default
        $this->syncToAppDir($installName, 'claude');

        return $skill;
    }

    /**
     * Delete a skill: remove from all app directories, SSOT, and database.
     */
    public function delete(string $id): void
    {
        $skill = $this->get($id);
        if (!$skill) {
            return;
        }

        // Remove from all app directories
        foreach (['claude', 'codex', 'gemini', 'opencode'] as $app) {
            $this->removeFromApp($skill->directory, $app);
        }

        // Remove from SSOT
        $ssotPath = $this->getSsotDir() . '/' . $skill->directory;
        if (is_dir($ssotPath)) {
            $this->removeDirectoryRecursive($ssotPath);
        }

        $this->repo->delete($id);
    }

    /**
     * Sync all enabled skills to their enabled app directories.
     */
    public function syncToApps(): void
    {
        $skills = $this->list();
        foreach ($skills as $skill) {
            if ($skill->enabled_claude) {
                $this->syncToAppDir($skill->directory, 'claude');
            }
            if ($skill->enabled_codex) {
                $this->syncToAppDir($skill->directory, 'codex');
            }
            if ($skill->enabled_gemini) {
                $this->syncToAppDir($skill->directory, 'gemini');
            }
            if ($skill->enabled_opencode) {
                $this->syncToAppDir($skill->directory, 'opencode');
            }
        }
    }

    /**
     * Update the enabled flag for a specific app.
     */
    public function updateEnabled(string $id, string $app, bool $enabled): void
    {
        $skill = $this->get($id);
        if (!$skill) {
            return;
        }

        $this->repo->updateEnabled($id, $app, $enabled);

        if ($enabled) {
            $this->syncToAppDir($skill->directory, $app);
        } else {
            $this->removeFromApp($skill->directory, $app);
        }
    }

    // ========================================================================
    // Path helpers
    // ========================================================================

    private function getSsotDir(): string
    {
        $dir = $this->baseDir . '/skills';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Get the app-specific skills directory.
     */
    private function getAppSkillsDir(string $app): string
    {
        $dir = match ($app) {
            'claude' => $this->home . '/.claude/commands',
            'codex' => $this->home . '/.codex/skills',
            'gemini' => $this->home . '/.gemini/skills',
            'opencode' => $this->home . '/.config/opencode/skills',
            default => $this->home . '/.cc-switch/skills',
        };
        return $dir;
    }

    // ========================================================================
    // File sync
    // ========================================================================

    /**
     * Sync a skill directory from SSOT to an app's skill directory.
     *
     * Uses the configured sync method: 'auto' (symlink with copy fallback),
     * 'symlink', or 'copy'.
     */
    private function syncToAppDir(string $directory, string $app): void
    {
        $source = $this->getSsotDir() . '/' . $directory;
        if (!is_dir($source)) {
            return;
        }

        $appDir = $this->getAppSkillsDir($app);
        if (!is_dir($appDir)) {
            mkdir($appDir, 0755, true);
        }

        $dest = $appDir . '/' . $directory;

        // Remove existing (symlink or real directory)
        if (is_link($dest)) {
            unlink($dest);
        } elseif (is_dir($dest)) {
            $this->removeDirectoryRecursive($dest);
        }

        $method = $this->getSyncMethod();

        switch ($method) {
            case 'symlink':
                symlink($source, $dest);
                break;

            case 'copy':
                $this->copyDirectoryRecursive($source, $dest);
                break;

            case 'auto':
            default:
                // Try symlink first, fallback to copy
                if (@symlink($source, $dest)) {
                    break;
                }
                $this->copyDirectoryRecursive($source, $dest);
                break;
        }
    }

    /**
     * Remove a skill from an app directory.
     */
    private function removeFromApp(string $directory, string $app): void
    {
        $appDir = $this->getAppSkillsDir($app);
        $path = $appDir . '/' . $directory;

        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            $this->removeDirectoryRecursive($path);
        }
    }

    private function getSyncMethod(): string
    {
        return $this->settingsRepo->get('skillSyncMethod') ?? 'auto';
    }

    // ========================================================================
    // GitHub download
    // ========================================================================

    /**
     * Download a specific directory from a GitHub repository.
     */
    private function downloadFromGitHub(
        string $owner,
        string $repo,
        string $branch,
        string $directory,
        string $dest
    ): void {
        $url = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$branch}.zip";
        $tmpZip = sys_get_temp_dir() . '/cc-switch-skill-' . getmypid() . '.zip';
        $tmpDir = sys_get_temp_dir() . '/cc-switch-skill-' . getmypid();

        try {
            // Download zip
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException("Failed to initialize curl for: {$url}");
            }
            $fp = fopen($tmpZip, 'w');
            if ($fp === false) {
                throw new \RuntimeException("Failed to open temp file: {$tmpZip}");
            }
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($httpCode !== 200) {
                throw new \RuntimeException("Failed to download from GitHub (HTTP {$httpCode}): {$url}");
            }

            // Extract
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new \RuntimeException("Failed to open zip file: {$tmpZip}");
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            // Find the extracted directory (usually repo-branch/)
            $entries = scandir($tmpDir);
            $extractedRoot = null;
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry !== '.' && $entry !== '..' && is_dir($tmpDir . '/' . $entry)) {
                        $extractedRoot = $tmpDir . '/' . $entry;
                        break;
                    }
                }
            }

            if ($extractedRoot === null) {
                throw new \RuntimeException("No directory found in extracted zip");
            }

            $source = $extractedRoot . '/' . $directory;
            if (!is_dir($source)) {
                throw new \RuntimeException("Directory '{$directory}' not found in repository");
            }

            $this->copyDirectoryRecursive($source, $dest);
        } finally {
            @unlink($tmpZip);
            if (is_dir($tmpDir)) {
                $this->removeDirectoryRecursive($tmpDir);
            }
        }
    }

    // ========================================================================
    // Filesystem utilities
    // ========================================================================

    private function copyDirectoryRecursive(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $entries = scandir($source);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;
            if (is_dir($srcPath)) {
                $this->copyDirectoryRecursive($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
