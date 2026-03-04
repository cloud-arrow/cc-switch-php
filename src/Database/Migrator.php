<?php

declare(strict_types=1);

namespace CcSwitch\Database;

use PDO;
use RuntimeException;

/**
 * SQL file-based database migrator.
 *
 * Scans the migrations directory for *.sql files, executes them in order,
 * and tracks which files have been applied via a `migrations` table.
 */
class Migrator
{
    private PDO $pdo;
    private string $migrationsDir;

    public function __construct(PDO $pdo, string $migrationsDir)
    {
        $this->pdo = $pdo;
        $this->migrationsDir = $migrationsDir;
    }

    /**
     * Run all pending migrations.
     *
     * @return int Number of migrations applied
     */
    public function migrate(): int
    {
        $this->ensureMigrationsTable();

        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();
        $batch = $this->getNextBatch();
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration file: {$file}");
            }

            $this->pdo->exec($sql);
            $this->recordMigration($name, $batch);
            $count++;
        }

        return $count;
    }

    /**
     * Get the status of all migration files.
     *
     * @return array<int, array{file: string, applied: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->getAppliedMigrationsWithBatch();
        $files = $this->getMigrationFiles();
        $result = [];

        foreach ($files as $file) {
            $name = basename($file);
            $result[] = [
                'file' => $name,
                'applied' => isset($applied[$name]),
                'batch' => $applied[$name] ?? null,
            ];
        }

        return $result;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                executed_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\'))
            )'
        );
    }

    /**
     * @return string[]
     */
    private function getAppliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM migrations ORDER BY id');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, int>
     */
    private function getAppliedMigrationsWithBatch(): array
    {
        $stmt = $this->pdo->query('SELECT migration, batch FROM migrations ORDER BY id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['migration']] = (int) $row['batch'];
        }
        return $result;
    }

    private function getNextBatch(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return string[]
     */
    private function getMigrationFiles(): array
    {
        // Use scandir instead of glob for phar:// compatibility
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $entries = scandir($this->migrationsDir);
        if ($entries === false) {
            return [];
        }
        $files = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.sql')) {
                $files[] = $this->migrationsDir . '/' . $entry;
            }
        }
        sort($files);
        return $files;
    }

    private function recordMigration(string $name, int $batch): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
        $stmt->execute([$name, $batch]);
    }
}
