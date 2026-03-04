<?php

declare(strict_types=1);

namespace CcSwitch\Service;

/**
 * Session scanner service.
 *
 * Scans session directories for all supported apps and returns metadata.
 * Supports generating resume commands for continuing sessions.
 */
class SessionService
{
    private string $home;

    public function __construct()
    {
        $this->home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    }

    /**
     * Scan session directories for all apps.
     *
     * @return array<int, array{session_id: string, title: string|null, app: string, last_active: int|null, project_dir: string|null, source_path: string|null, resume_command: string|null}>
     */
    public function scan(): array
    {
        $sessions = [];

        $sessions = array_merge($sessions, $this->scanClaude());
        $sessions = array_merge($sessions, $this->scanCodex());
        $sessions = array_merge($sessions, $this->scanGemini());
        $sessions = array_merge($sessions, $this->scanOpenCode());

        // Sort by last_active descending (most recent first)
        usort($sessions, function ($a, $b) {
            $aTs = $a['last_active'] ?? 0;
            $bTs = $b['last_active'] ?? 0;
            return $bTs <=> $aTs;
        });

        return $sessions;
    }

    /**
     * Generate a terminal command to resume a session.
     */
    public function getResumeCommand(string $sessionId, string $app): string
    {
        return match ($app) {
            'claude' => "claude --resume {$sessionId}",
            'codex' => "codex --resume {$sessionId}",
            'gemini' => "gemini",
            'opencode' => "opencode",
            default => '',
        };
    }

    /**
     * Scan Claude sessions from ~/.claude/projects/
     *
     * Claude stores sessions as JSONL files under:
     * ~/.claude/projects/{project-hash}/sessions/{session-id}.jsonl
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanClaude(): array
    {
        $projectsDir = $this->home . '/.claude/projects';
        if (!is_dir($projectsDir)) {
            return [];
        }

        $sessions = [];
        $projects = scandir($projectsDir);
        if ($projects === false) {
            return [];
        }

        foreach ($projects as $project) {
            if ($project === '.' || $project === '..' || str_starts_with($project, '.')) {
                continue;
            }

            // Look for JSONL session files at various locations
            $sessionsDir = $projectsDir . '/' . $project;
            if (!is_dir($sessionsDir)) {
                continue;
            }

            $this->scanClaudeDir($sessionsDir, $project, $sessions);
        }

        return $sessions;
    }

    /**
     * @param array<int, array<string, mixed>> &$sessions
     */
    private function scanClaudeDir(string $dir, string $projectHash, array &$sessions): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->scanClaudeDir($path, $projectHash, $sessions);
                continue;
            }

            if (!str_ends_with($entry, '.jsonl')) {
                continue;
            }

            $sessionId = basename($entry, '.jsonl');
            $stat = stat($path);
            $lastActive = $stat !== false ? $stat['mtime'] : null;

            // Try to extract title and project dir from first line
            $title = null;
            $projectDir = null;
            $fp = @fopen($path, 'r');
            if ($fp) {
                $firstLine = fgets($fp);
                if ($firstLine !== false) {
                    $data = json_decode($firstLine, true);
                    if (is_array($data)) {
                        $title = $data['title'] ?? $data['userMessage'] ?? null;
                        if (is_string($title) && strlen($title) > 100) {
                            $title = substr($title, 0, 100) . '...';
                        }
                        $projectDir = $data['cwd'] ?? $data['projectDir'] ?? null;
                    }
                }
                fclose($fp);
            }

            $sessions[] = [
                'session_id' => $sessionId,
                'title' => $title,
                'app' => 'claude',
                'last_active' => $lastActive,
                'project_dir' => $projectDir,
                'source_path' => $path,
                'resume_command' => "claude --resume {$sessionId}",
            ];
        }
    }

    /**
     * Scan Codex sessions from ~/.codex/sessions/
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanCodex(): array
    {
        $sessionsDir = $this->home . '/.codex/sessions';
        if (!is_dir($sessionsDir)) {
            return [];
        }

        return $this->scanJsonSessions($sessionsDir, 'codex');
    }

    /**
     * Scan Gemini sessions from ~/.gemini/sessions/
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanGemini(): array
    {
        $sessionsDir = $this->home . '/.gemini/sessions';
        if (!is_dir($sessionsDir)) {
            return [];
        }

        return $this->scanJsonSessions($sessionsDir, 'gemini');
    }

    /**
     * Scan OpenCode sessions from ~/.config/opencode/sessions/
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanOpenCode(): array
    {
        $sessionsDir = $this->home . '/.config/opencode/sessions';
        if (!is_dir($sessionsDir)) {
            return [];
        }

        return $this->scanJsonSessions($sessionsDir, 'opencode');
    }

    /**
     * Generic JSON session scanner for apps that store sessions as JSON files.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanJsonSessions(string $dir, string $app): array
    {
        $sessions = [];
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $dir . '/' . $entry;

            // Support both files and directories
            if (is_dir($path)) {
                $sessionId = $entry;
                $stat = stat($path);
                $sessions[] = [
                    'session_id' => $sessionId,
                    'title' => null,
                    'app' => $app,
                    'last_active' => $stat !== false ? $stat['mtime'] : null,
                    'project_dir' => null,
                    'source_path' => $path,
                    'resume_command' => $this->getResumeCommand($sessionId, $app),
                ];
                continue;
            }

            if (!str_ends_with($entry, '.json') && !str_ends_with($entry, '.jsonl')) {
                continue;
            }

            $sessionId = preg_replace('/\.(json|jsonl)$/', '', $entry) ?? $entry;
            $stat = stat($path);

            $title = null;
            $projectDir = null;
            $content = @file_get_contents($path, false, null, 0, 4096);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $title = $data['title'] ?? $data['name'] ?? null;
                    $projectDir = $data['cwd'] ?? $data['projectDir'] ?? $data['project_dir'] ?? null;
                }
            }

            $sessions[] = [
                'session_id' => $sessionId,
                'title' => $title,
                'app' => $app,
                'last_active' => $stat !== false ? $stat['mtime'] : null,
                'project_dir' => $projectDir,
                'source_path' => $path,
                'resume_command' => $this->getResumeCommand($sessionId, $app),
            ];
        }

        return $sessions;
    }
}
