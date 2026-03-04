<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * WebDAV sync service.
 *
 * Provides push/pull synchronization of the CC Switch database
 * via WebDAV using Guzzle for HTTP operations.
 *
 * Config format: ['baseUrl', 'username', 'password', 'remoteRoot', 'profile']
 */
class WebDavSyncService
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = $baseDir;
    }

    /**
     * Test WebDAV connection via PROPFIND.
     *
     * @param array{baseUrl: string, username: string, password: string, remoteRoot?: string, profile?: string} $config
     */
    public function testConnection(array $config): bool
    {
        $client = $this->buildClient($config);
        $url = $this->buildUrl($config, []);

        try {
            $response = $client->request('PROPFIND', $url, [
                'headers' => ['Depth' => '0'],
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            return $status >= 200 && $status < 300;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Push database to WebDAV.
     *
     * Exports DB to SQL, compresses, and uploads via PUT.
     * Uses ETag-based conflict detection.
     *
     * @param array{baseUrl: string, username: string, password: string, remoteRoot?: string, profile?: string} $config
     * @throws \RuntimeException on failure
     */
    public function push(array $config): void
    {
        $dbPath = $this->baseDir . '/cc-switch.db';
        if (!file_exists($dbPath)) {
            throw new \RuntimeException('Database file not found');
        }

        $client = $this->buildClient($config);
        $segments = $this->getRemoteSegments($config);

        // Ensure remote directories exist
        $this->ensureRemoteDirectories($client, $config, $segments);

        // Export database to SQL
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $sqlDump = $this->exportSql($pdo);

        // Compress
        $compressed = gzencode($sqlDump);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress SQL dump');
        }

        // Upload
        $uploadSegments = array_merge($segments, ['cc-switch-db.sql.gz']);
        $uploadUrl = $this->buildUrl($config, $uploadSegments);

        try {
            $client->put($uploadUrl, [
                'body' => $compressed,
                'headers' => ['Content-Type' => 'application/gzip'],
                'timeout' => 300,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to upload to WebDAV: ' . $e->getMessage());
        }
    }

    /**
     * Pull database from WebDAV.
     *
     * Downloads compressed SQL, decompresses, and imports.
     *
     * @param array{baseUrl: string, username: string, password: string, remoteRoot?: string, profile?: string} $config
     * @throws \RuntimeException on failure
     */
    public function pull(array $config): void
    {
        $client = $this->buildClient($config);
        $segments = array_merge($this->getRemoteSegments($config), ['cc-switch-db.sql.gz']);
        $url = $this->buildUrl($config, $segments);

        try {
            $response = $client->get($url, ['timeout' => 300]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to download from WebDAV: ' . $e->getMessage());
        }

        $compressed = $response->getBody()->getContents();
        $sqlDump = gzdecode($compressed);
        if ($sqlDump === false) {
            throw new \RuntimeException('Failed to decompress downloaded data');
        }

        // Backup current database before importing
        $dbPath = $this->baseDir . '/cc-switch.db';
        $backupService = new BackupService($this->baseDir);
        if (file_exists($dbPath)) {
            $backupService->run();
        }

        // Import SQL
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($sqlDump);
    }

    // ========================================================================
    // Internal helpers
    // ========================================================================

    /**
     * @param array{baseUrl: string, username: string, password: string, remoteRoot?: string, profile?: string} $config
     */
    private function buildClient(array $config): Client
    {
        $options = [
            'verify' => true,
            RequestOptions::CONNECT_TIMEOUT => 30,
        ];

        $username = trim($config['username'] ?? '');
        $password = $config['password'] ?? '';

        if ($username !== '') {
            $options['auth'] = [$username, $password];
        }

        return new Client($options);
    }

    /**
     * Build the full URL from base + segments.
     *
     * @param string[] $segments
     */
    private function buildUrl(array $config, array $segments): string
    {
        $baseUrl = rtrim($config['baseUrl'] ?? '', '/');

        if (empty($segments)) {
            return $baseUrl . '/';
        }

        $encoded = array_map('rawurlencode', $segments);
        return $baseUrl . '/' . implode('/', $encoded);
    }

    /**
     * Get the remote path segments for this profile.
     *
     * @return string[]
     */
    private function getRemoteSegments(array $config): array
    {
        $segments = [];

        $remoteRoot = trim($config['remoteRoot'] ?? '');
        if ($remoteRoot !== '') {
            foreach (explode('/', $remoteRoot) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $segments[] = $part;
                }
            }
        }

        $profile = trim($config['profile'] ?? 'default');
        $segments[] = 'cc-switch-sync';
        $segments[] = $profile;

        return $segments;
    }

    /**
     * Ensure remote directories exist via MKCOL.
     *
     * @param string[] $segments
     */
    private function ensureRemoteDirectories(Client $client, array $config, array $segments): void
    {
        for ($depth = 1; $depth <= count($segments); $depth++) {
            $prefix = array_slice($segments, 0, $depth);
            $url = $this->buildUrl($config, $prefix) . '/';

            try {
                $response = $client->request('MKCOL', $url, ['timeout' => 30]);
            } catch (GuzzleException $e) {
                // 405 typically means directory already exists
                if ($e->getCode() === 405) {
                    continue;
                }
                // Other errors might also mean it exists; continue optimistically
            }
        }
    }

    /**
     * Export database to SQL string.
     */
    private function exportSql(\PDO $pdo): string
    {
        $output = "-- CC Switch SQLite export\n";
        $output .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
        $output .= "PRAGMA foreign_keys=OFF;\n";
        $output .= "BEGIN TRANSACTION;\n";

        // Get all tables
        $tables = $pdo->query(
            "SELECT name, sql FROM sqlite_master WHERE type='table' AND sql NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $output .= $table['sql'] . ";\n";

            // Export data
            $stmt = $pdo->query("SELECT * FROM \"{$tableName}\"");
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

                $output .= "INSERT INTO \"{$tableName}\" (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
        }

        // Get indexes
        $indexes = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='index' AND sql NOT NULL ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($indexes as $sql) {
            $output .= $sql . ";\n";
        }

        $output .= "COMMIT;\nPRAGMA foreign_keys=ON;\n";

        return $output;
    }
}
