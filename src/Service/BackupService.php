<?php

declare(strict_types=1);

namespace CcSwitch\Service;

/**
 * Database backup and restore service.
 *
 * Creates timestamped SQLite backups in ~/.cc-switch/backups/,
 * supports listing, restoring, and cleanup of old backups.
 */
class BackupService
{
    private string $dbPath;
    private string $backupDir;

    public function __construct(string $baseDir)
    {
        $this->dbPath = $baseDir . '/cc-switch.db';
        $this->backupDir = $baseDir . '/backups';
    }

    /**
     * Create a backup of the database.
     *
     * @return string Path to the created backup file
     * @throws \RuntimeException on failure
     */
    public function run(): string
    {
        if (!file_exists($this->dbPath)) {
            throw new \RuntimeException('Database file not found: ' . $this->dbPath);
        }

        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
                throw new \RuntimeException('Failed to create backup directory: ' . $this->backupDir);
            }
        }

        $timestamp = date('Ymd_His');
        $baseId = "backup_{$timestamp}";
        $filename = "{$baseId}.db";
        $backupPath = $this->backupDir . '/' . $filename;

        // Handle collision
        $counter = 1;
        while (file_exists($backupPath)) {
            $filename = "{$baseId}_{$counter}.db";
            $backupPath = $this->backupDir . '/' . $filename;
            $counter++;
        }

        // Use SQLite backup API via PDO
        $source = new \PDO('sqlite:' . $this->dbPath);
        $source->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dest = new \PDO('sqlite:' . $backupPath);
        $dest->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Copy database using SQLite's built-in backup
        $sourceDb = $source->query("PRAGMA database_list")->fetch();
        $this->copyDatabase($source, $dest);

        return $backupPath;
    }

    /**
     * List existing backups sorted by time (newest first).
     *
     * @return array<int, array{filename: string, size_bytes: int, created_at: string}>
     */
    public function list(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $entries = [];
        $files = scandir($this->backupDir);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $this->backupDir . '/' . $file;
            if (!is_file($path) || !str_ends_with($file, '.db')) {
                continue;
            }

            $stat = stat($path);
            $entries[] = [
                'filename' => $file,
                'size_bytes' => $stat !== false ? $stat['size'] : 0,
                'created_at' => $stat !== false ? date('c', $stat['mtime']) : '',
            ];
        }

        // Sort newest first
        usort($entries, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $entries;
    }

    /**
     * Restore database from a backup file.
     *
     * Creates a safety backup before restoring.
     *
     * @throws \RuntimeException on failure
     */
    public function restore(string $backupFile): void
    {
        // Validate filename to prevent path traversal
        if (str_contains($backupFile, '..') || str_contains($backupFile, '/') || str_contains($backupFile, '\\')) {
            throw new \RuntimeException('Invalid backup filename');
        }

        if (!str_ends_with($backupFile, '.db')) {
            throw new \RuntimeException('Backup file must have .db extension');
        }

        $backupPath = $this->backupDir . '/' . $backupFile;
        if (!file_exists($backupPath)) {
            throw new \RuntimeException("Backup file not found: {$backupFile}");
        }

        // Create safety backup first
        if (file_exists($this->dbPath)) {
            $this->run();
        }

        // Restore by copying backup over the database
        $source = new \PDO('sqlite:' . $backupPath);
        $source->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dest = new \PDO('sqlite:' . $this->dbPath);
        $dest->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->copyDatabase($source, $dest);
    }

    /**
     * Delete old backups, keeping only the most recent $retainCount.
     */
    public function cleanup(int $retainCount = 10): void
    {
        $backups = $this->list();

        if (count($backups) <= $retainCount) {
            return;
        }

        // Backups are already sorted newest first; remove excess from the tail
        $toRemove = array_slice($backups, $retainCount);

        foreach ($toRemove as $backup) {
            $path = $this->backupDir . '/' . $backup['filename'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Export database to SQL dump string.
     *
     * @throws \RuntimeException on failure
     */
    public function exportSql(): string
    {
        if (!file_exists($this->dbPath)) {
            throw new \RuntimeException('Database file not found: ' . $this->dbPath);
        }

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $userVersion = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        $output = "-- CC Switch SQLite export\n";
        $output .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
        $output .= "-- user_version: {$userVersion}\n";
        $output .= "PRAGMA foreign_keys=OFF;\n";
        $output .= "PRAGMA user_version={$userVersion};\n";
        $output .= "BEGIN TRANSACTION;\n";

        // Export schema and data
        $tables = $pdo->query(
            "SELECT type, name, sql FROM sqlite_master WHERE sql NOT NULL AND type IN ('table','index','trigger','view') ORDER BY type='table' DESC, name"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $tableNames = [];
        foreach ($tables as $obj) {
            if (str_starts_with($obj['name'], 'sqlite_')) {
                continue;
            }
            $output .= $obj['sql'] . ";\n";
            if ($obj['type'] === 'table') {
                $tableNames[] = $obj['name'];
            }
        }

        foreach ($tableNames as $table) {
            $stmt = $pdo->query("SELECT * FROM \"{$table}\"");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns = array_map(fn($c) => "\"{$c}\"", array_keys($row));
                $values = array_map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }
                    if (is_int($v) || is_float($v)) {
                        return (string) $v;
                    }
                    return "'" . str_replace("'", "''", (string) $v) . "'";
                }, array_values($row));

                $output .= "INSERT INTO \"{$table}\" (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
        }

        $output .= "COMMIT;\nPRAGMA foreign_keys=ON;\n";

        return $output;
    }

    /**
     * Export database to SQL file.
     *
     * @return string Path to the exported file
     * @throws \RuntimeException on failure
     */
    public function exportSqlToFile(string $targetPath): string
    {
        $sql = $this->exportSql();
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Failed to create directory: ' . $dir);
            }
        }

        if (file_put_contents($targetPath, $sql) === false) {
            throw new \RuntimeException('Failed to write SQL file: ' . $targetPath);
        }

        return $targetPath;
    }

    /**
     * Import database from SQL string.
     *
     * Creates a safety backup before importing.
     *
     * @throws \RuntimeException on failure
     */
    public function importSql(string $sql): void
    {
        $trimmed = ltrim($sql, "\xEF\xBB\xBF"); // Strip BOM
        if (!str_starts_with(trim($trimmed), '-- CC Switch SQLite')) {
            throw new \RuntimeException('Only SQL backups exported by CC Switch are supported.');
        }

        // Safety backup
        if (file_exists($this->dbPath)) {
            $this->run();
        }

        // Import into a temp database first to validate
        $tempPath = $this->dbPath . '.import_tmp';
        try {
            $tempPdo = new \PDO('sqlite:' . $tempPath);
            $tempPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $tempPdo->exec($trimmed);

            // Basic validation
            $providerCount = (int) $tempPdo->query("SELECT COUNT(*) FROM providers")->fetchColumn();
            $mcpCount = (int) $tempPdo->query("SELECT COUNT(*) FROM mcp_servers")->fetchColumn();
            if ($providerCount === 0 && $mcpCount === 0) {
                throw new \RuntimeException('Imported SQL contains no provider or MCP data.');
            }

            unset($tempPdo);

            // Replace main database with validated temp
            if (!rename($tempPath, $this->dbPath)) {
                throw new \RuntimeException('Failed to replace database with imported data.');
            }
        } catch (\Throwable $e) {
            @unlink($tempPath);
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('SQL import failed: ' . $e->getMessage());
        }
    }

    /**
     * Import database from SQL file.
     *
     * @throws \RuntimeException on failure
     */
    public function importSqlFromFile(string $sourcePath): void
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException('SQL file not found: ' . $sourcePath);
        }

        $sql = file_get_contents($sourcePath);
        if ($sql === false) {
            throw new \RuntimeException('Failed to read SQL file: ' . $sourcePath);
        }

        $this->importSql($sql);
    }

    /**
     * Check if periodic backup is needed based on interval setting.
     */
    public function periodicBackupIfNeeded(int $intervalHours): void
    {
        if ($intervalHours <= 0) {
            return;
        }

        if (!is_dir($this->backupDir)) {
            $this->run();
            return;
        }

        $backups = $this->list();
        if (empty($backups)) {
            $this->run();
            return;
        }

        // Check age of most recent backup
        $latest = $backups[0];
        $latestTime = strtotime($latest['created_at']);
        if ($latestTime === false) {
            $this->run();
            return;
        }

        $ageSeconds = time() - $latestTime;
        if ($ageSeconds > ($intervalHours * 3600)) {
            $this->run();
            $this->cleanup();
        }
    }

    /**
     * Copy one SQLite database to another using a dump/restore approach.
     *
     * Since PHP PDO doesn't expose the SQLite backup API directly,
     * we use a file-level copy approach.
     */
    private function copyDatabase(\PDO $source, \PDO $dest): void
    {
        $sourcePath = $source->query("PRAGMA database_list")->fetch(\PDO::FETCH_ASSOC)['file'] ?? '';
        $destPath = $dest->query("PRAGMA database_list")->fetch(\PDO::FETCH_ASSOC)['file'] ?? '';

        if ($sourcePath === '' || $destPath === '') {
            throw new \RuntimeException('Cannot determine database file paths');
        }

        // Close connections before file copy
        unset($source, $dest);

        if (!copy($sourcePath, $destPath)) {
            throw new \RuntimeException("Failed to copy database from {$sourcePath} to {$destPath}");
        }
    }
}
