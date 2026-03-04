<?php

declare(strict_types=1);

namespace CcSwitch;

use CcSwitch\Database\Database;
use CcSwitch\Database\Migrator;
use Medoo\Medoo;
use PDO;

/**
 * Application bootstrap and container.
 */
class App
{
    private Database $database;
    private string $baseDir;

    private function __construct(Database $database, string $baseDir)
    {
        $this->database = $database;
        $this->baseDir = $baseDir;
    }

    /**
     * Check if portable mode is active (portable.ini next to executable).
     */
    public static function isPortableMode(): bool
    {
        $exeDir = dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? __DIR__) ?: __DIR__);
        return file_exists($exeDir . '/portable.ini');
    }

    /**
     * Get the data directory path, respecting portable mode.
     */
    public static function getDataDir(): string
    {
        if (self::isPortableMode()) {
            return dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? __DIR__) ?: __DIR__) . '/data';
        }
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return $home . '/.cc-switch';
    }

    /**
     * Boot the application: create data directory, initialize database, run migrations.
     */
    public static function boot(): self
    {
        $baseDir = self::getDataDir();

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        $dbPath = $baseDir . '/cc-switch.db';
        $dsn = 'sqlite:' . $dbPath;

        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

        $medoo = new Medoo([
            'type' => 'sqlite',
            'database' => $dbPath,
        ]);

        $migrationsDir = dirname(__DIR__) . '/migrations';
        $migrator = new Migrator($pdo, $migrationsDir);
        $migrator->migrate();

        $database = new Database($pdo, $medoo);

        return new self($database, $baseDir);
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getPdo(): PDO
    {
        return $this->database->getPdo();
    }

    public function getMedoo(): Medoo
    {
        return $this->database->getMedoo();
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }
}
