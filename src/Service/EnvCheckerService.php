<?php

declare(strict_types=1);

namespace CcSwitch\Service;

/**
 * Environment variable conflict checker.
 *
 * Scans system environment and shell config files for API-related
 * variables (ANTHROPIC_*, OPENAI_*, GEMINI_*) that may conflict
 * with CC Switch proxy configurations.
 */
class EnvCheckerService
{
    /** @var string[] Variable name prefixes to check */
    private const KEYWORDS = ['ANTHROPIC_', 'OPENAI_', 'GEMINI_', 'GOOGLE_GEMINI_'];

    /**
     * Check for environment variable conflicts.
     *
     * @return array<int, array{var_name: string, value: string, source_type: string, source_path: string}>
     */
    public function check(): array
    {
        $conflicts = [];

        // Check system environment
        $conflicts = array_merge($conflicts, $this->checkSystemEnv());

        // Check shell config files
        $conflicts = array_merge($conflicts, $this->checkShellConfigs());

        return $conflicts;
    }

    /**
     * @return array<int, array{var_name: string, value: string, source_type: string, source_path: string}>
     */
    private function checkSystemEnv(): array
    {
        $conflicts = [];
        $env = getenv();

        if (!is_array($env)) {
            return $conflicts;
        }

        foreach ($env as $name => $value) {
            if ($this->matchesKeyword((string) $name)) {
                $conflicts[] = [
                    'var_name' => (string) $name,
                    'value' => (string) $value,
                    'source_type' => 'system',
                    'source_path' => 'Process Environment',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @return array<int, array{var_name: string, value: string, source_type: string, source_path: string}>
     */
    private function checkShellConfigs(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        if ($home === '') {
            return [];
        }

        $files = [
            $home . '/.bashrc',
            $home . '/.bash_profile',
            $home . '/.zshrc',
            $home . '/.zprofile',
            $home . '/.profile',
            '/etc/profile',
            '/etc/bashrc',
        ];

        $conflicts = [];

        foreach ($files as $filePath) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                $trimmed = trim($line);

                // Skip comments
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // Match: export VAR=value or VAR=value
                $exportLine = $trimmed;
                if (str_starts_with($trimmed, 'export ')) {
                    $exportLine = substr($trimmed, 7);
                } elseif (!str_contains($trimmed, '=')) {
                    continue;
                }

                $eqPos = strpos($exportLine, '=');
                if ($eqPos === false) {
                    continue;
                }

                $varName = trim(substr($exportLine, 0, $eqPos));
                $varValue = trim(substr($exportLine, $eqPos + 1));

                if ($this->matchesKeyword($varName)) {
                    // Strip quotes from value
                    $varValue = trim($varValue, '"\'');

                    $conflicts[] = [
                        'var_name' => $varName,
                        'value' => $varValue,
                        'source_type' => 'file',
                        'source_path' => $filePath . ':' . ($lineNum + 1),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Delete conflicting environment variable lines from shell config files.
     *
     * @param array<int, array{var_name: string, source_type: string, source_path: string}> $conflicts
     * @return array<int, array{file: string, removed_count: int, backup_path: string}>
     */
    public function deleteConflicts(array $conflicts): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        $backupDir = $home . '/.cc-switch/env-backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Group conflicts by file path (skip 'system' source_type)
        $grouped = [];
        foreach ($conflicts as $conflict) {
            if (($conflict['source_type'] ?? '') === 'system') {
                continue;
            }
            $sourcePath = $conflict['source_path'] ?? '';
            // source_path format: "/path/to/file:linenum"
            $colonPos = strrpos($sourcePath, ':');
            if ($colonPos === false) {
                continue;
            }
            $filePath = substr($sourcePath, 0, $colonPos);
            $lineNum = (int) substr($sourcePath, $colonPos + 1);
            $grouped[$filePath][] = [
                'var_name' => $conflict['var_name'] ?? '',
                'line_num' => $lineNum,
            ];
        }

        $results = [];
        foreach ($grouped as $filePath => $entries) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);
            $removedLines = [];
            $varNames = array_column($entries, 'var_name');

            foreach ($lines as $lineIdx => $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                $exportLine = $trimmed;
                if (str_starts_with($trimmed, 'export ')) {
                    $exportLine = substr($trimmed, 7);
                } elseif (!str_contains($trimmed, '=')) {
                    continue;
                }

                $eqPos = strpos($exportLine, '=');
                if ($eqPos === false) {
                    continue;
                }

                $varName = trim(substr($exportLine, 0, $eqPos));
                if (in_array($varName, $varNames, true)) {
                    $removedLines[] = [
                        'line_num' => $lineIdx + 1,
                        'content' => $line,
                    ];
                    unset($lines[$lineIdx]);
                }
            }

            if (empty($removedLines)) {
                continue;
            }

            // Save backup
            $timestamp = date('Ymd_His');
            $baseName = basename($filePath);
            $backupFilename = "{$baseName}_{$timestamp}.json";
            $backupPath = $backupDir . '/' . $backupFilename;

            $backupData = [
                'file' => $filePath,
                'lines' => $removedLines,
            ];
            file_put_contents($backupPath, json_encode($backupData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // Write modified content back
            file_put_contents($filePath, implode("\n", $lines));

            $results[] = [
                'file' => $filePath,
                'removed_count' => count($removedLines),
                'backup_path' => $backupPath,
            ];
        }

        return $results;
    }

    /**
     * Restore environment variable lines from a JSON backup file.
     *
     * @throws \RuntimeException on failure
     */
    public function restoreBackup(string $backupFile): void
    {
        // Validate filename (no path traversal)
        if (str_contains($backupFile, '..') || str_contains($backupFile, '/') || str_contains($backupFile, '\\')) {
            throw new \RuntimeException('Invalid backup filename');
        }

        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        $backupDir = $home . '/.cc-switch/env-backups';
        $backupPath = $backupDir . '/' . $backupFile;

        if (!file_exists($backupPath)) {
            throw new \RuntimeException("Backup file not found: {$backupFile}");
        }

        $json = file_get_contents($backupPath);
        if ($json === false) {
            throw new \RuntimeException("Failed to read backup file: {$backupFile}");
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['file']) || !isset($data['lines'])) {
            throw new \RuntimeException('Invalid backup file format');
        }

        $filePath = $data['file'];
        $linesToRestore = $data['lines'];

        if (!is_file($filePath)) {
            throw new \RuntimeException("Target file not found: {$filePath}");
        }

        // Append the removed lines back to the file
        $linesToAppend = array_map(fn($entry) => $entry['content'], $linesToRestore);
        $appendContent = "\n" . implode("\n", $linesToAppend) . "\n";
        file_put_contents($filePath, $appendContent, FILE_APPEND);
    }

    private function matchesKeyword(string $name): bool
    {
        $upper = strtoupper($name);
        foreach (self::KEYWORDS as $keyword) {
            if (str_starts_with($upper, $keyword)) {
                return true;
            }
        }
        return false;
    }
}
