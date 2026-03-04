<?php

declare(strict_types=1);

namespace CcSwitch\Util;

use RuntimeException;

/**
 * Atomic file write utility.
 *
 * Writes to a temporary file first, then renames to the target path.
 * This prevents partial writes from corrupting the target file.
 */
class AtomicFile
{
    /**
     * Write content to a file atomically.
     *
     * Creates the parent directory if needed, writes to a temp file in the
     * same directory, then renames over the target.
     *
     * @throws RuntimeException on failure
     */
    public static function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create directory: {$dir}");
            }
        }

        $tmpPath = $path . '.tmp.' . getmypid() . '.' . hrtime(true);

        if (file_put_contents($tmpPath, $content) === false) {
            throw new RuntimeException("Cannot write temporary file: {$tmpPath}");
        }

        // Preserve permissions of original file if it exists
        if (file_exists($path)) {
            $perms = fileperms($path);
            if ($perms !== false) {
                chmod($tmpPath, $perms & 0777);
            }
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException("Cannot rename temp file to target: {$path}");
        }
    }

    /**
     * Atomically write JSON to a file (pretty-printed).
     *
     * @param array<string, mixed>|object $data JSON-encodable data
     * @throws RuntimeException on failure
     */
    public static function writeJson(string $path, array|object $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }
        self::write($path, $json . "\n");
    }

    /**
     * Read a JSON file and return the decoded data.
     *
     * @return array<string, mixed>
     * @throws RuntimeException if the file exists but cannot be read
     */
    public static function readJson(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }
}
