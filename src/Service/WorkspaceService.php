<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Util\AtomicFile;

/**
 * OpenClaw workspace file management service.
 *
 * Manages workspace markdown files (AGENTS.md, SOUL.md, etc.)
 * and daily memory files under ~/.openclaw/workspace/memory/.
 */
class WorkspaceService
{
    private const ALLOWED_FILES = [
        'AGENTS.md',
        'SOUL.md',
        'USER.md',
        'IDENTITY.md',
        'TOOLS.md',
        'MEMORY.md',
        'HEARTBEAT.md',
        'BOOTSTRAP.md',
        'BOOT.md',
    ];

    public function getWorkspacePath(): string
    {
        return $this->getOpenClawDir() . '/workspace';
    }

    /**
     * Read a whitelisted workspace file.
     */
    public function readFile(string $filename): ?string
    {
        $this->validateFilename($filename);

        $path = $this->getWorkspacePath() . '/' . $filename;
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Write a whitelisted workspace file atomically.
     */
    public function writeFile(string $filename, string $content): void
    {
        $this->validateFilename($filename);

        $path = $this->getWorkspacePath() . '/' . $filename;
        AtomicFile::write($path, $content);
    }

    /**
     * List all whitelisted files with existence and size info.
     *
     * @return array<int, array{filename: string, exists: bool, size: int}>
     */
    public function listFiles(): array
    {
        $result = [];
        $dir = $this->getWorkspacePath();

        foreach (self::ALLOWED_FILES as $filename) {
            $path = $dir . '/' . $filename;
            $exists = file_exists($path);
            $result[] = [
                'filename' => $filename,
                'exists' => $exists,
                'size' => $exists ? (int) filesize($path) : 0,
            ];
        }

        return $result;
    }

    /**
     * List daily memory files with metadata.
     *
     * @return array<int, array{filename: string, date: string, size: int, modifiedAt: int, preview: string}>
     */
    public function listDailyMemory(): array
    {
        $memoryDir = $this->getWorkspacePath() . '/memory';
        if (!is_dir($memoryDir)) {
            return [];
        }

        $files = scandir($memoryDir);
        if ($files === false) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.md')) {
                continue;
            }

            $path = $memoryDir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $date = substr($file, 0, -3); // strip .md
            $stat = stat($path);
            $size = $stat !== false ? $stat['size'] : 0;
            $mtime = $stat !== false ? $stat['mtime'] : 0;

            // Read first 200 chars as preview
            $content = file_get_contents($path);
            $preview = $content !== false ? mb_substr($content, 0, 200) : '';

            $result[] = [
                'filename' => $file,
                'date' => $date,
                'size' => $size,
                'modifiedAt' => $mtime,
                'preview' => $preview,
            ];
        }

        // Sort newest first
        usort($result, fn($a, $b) => strcmp($b['filename'], $a['filename']));

        return $result;
    }

    /**
     * Read a daily memory file by date.
     */
    public function readDailyMemory(string $date): ?string
    {
        $this->validateDate($date);

        $path = $this->getWorkspacePath() . '/memory/' . $date . '.md';
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Write a daily memory file atomically.
     */
    public function writeDailyMemory(string $date, string $content): void
    {
        $this->validateDate($date);

        $path = $this->getWorkspacePath() . '/memory/' . $date . '.md';
        AtomicFile::write($path, $content);
    }

    /**
     * Delete a daily memory file.
     */
    public function deleteDailyMemory(string $date): void
    {
        $this->validateDate($date);

        $path = $this->getWorkspacePath() . '/memory/' . $date . '.md';
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Search daily memory files with case-insensitive matching.
     *
     * @return array<int, array{filename: string, date: string, size: int, modifiedAt: int, snippet: string, matchCount: int}>
     */
    public function searchDailyMemory(string $query): array
    {
        $memoryDir = $this->getWorkspacePath() . '/memory';
        if (!is_dir($memoryDir) || $query === '') {
            return [];
        }

        $queryLower = mb_strtolower($query);
        $files = scandir($memoryDir);
        if ($files === false) {
            return [];
        }

        $results = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.md')) {
                continue;
            }

            $path = $memoryDir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $date = substr($file, 0, -3);
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $contentLower = mb_strtolower($content);

            // Find all match positions
            $matchPositions = [];
            $offset = 0;
            while (($pos = mb_strpos($contentLower, $queryLower, $offset)) !== false) {
                $matchPositions[] = $pos;
                $offset = $pos + 1;
            }

            $dateMatches = str_contains(mb_strtolower($date), $queryLower);

            if (empty($matchPositions) && !$dateMatches) {
                continue;
            }

            $stat = stat($path);

            // Build snippet around first match (~120 chars context)
            if (!empty($matchPositions)) {
                $firstPos = $matchPositions[0];
                $start = max(0, $firstPos - 50);
                $end = min(mb_strlen($content), $firstPos + 70);
                $snippet = '';
                if ($start > 0) {
                    $snippet .= '...';
                }
                $snippet .= mb_substr($content, $start, $end - $start);
                if ($end < mb_strlen($content)) {
                    $snippet .= '...';
                }
            } else {
                // Date-only match — use beginning of file
                $snippet = mb_substr($content, 0, 120);
                if (mb_strlen($content) > 120) {
                    $snippet .= '...';
                }
            }

            $results[] = [
                'filename' => $file,
                'date' => $date,
                'size' => $stat !== false ? $stat['size'] : 0,
                'modifiedAt' => $stat !== false ? $stat['mtime'] : 0,
                'snippet' => $snippet,
                'matchCount' => count($matchPositions),
            ];
        }

        // Sort newest first
        usort($results, fn($a, $b) => strcmp($b['filename'], $a['filename']));

        return $results;
    }

    private function validateFilename(string $filename): void
    {
        if (!in_array($filename, self::ALLOWED_FILES, true)) {
            throw new \InvalidArgumentException(
                "Invalid workspace filename: {$filename}. Allowed: " . implode(', ', self::ALLOWED_FILES)
            );
        }
    }

    private function validateDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException(
                "Invalid date format: {$date}. Expected: YYYY-MM-DD"
            );
        }
    }

    private function getOpenClawDir(): string
    {
        return (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) . '/.openclaw';
    }
}
