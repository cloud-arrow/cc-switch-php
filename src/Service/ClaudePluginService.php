<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Util\AtomicFile;

/**
 * Manages the Claude Code plugin configuration (~/.claude/config.json).
 *
 * Controls the primaryApiKey field to enable/disable proxy mode.
 */
class ClaudePluginService
{
    private ?string $claudeOverrideDir;

    public function __construct(?string $claudeOverrideDir = null)
    {
        $this->claudeOverrideDir = $claudeOverrideDir;
    }

    /**
     * Get the path to ~/.claude/config.json.
     */
    public function getConfigPath(): string
    {
        if ($this->claudeOverrideDir !== null && $this->claudeOverrideDir !== '') {
            return $this->claudeOverrideDir . '/config.json';
        }
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return $home . '/.claude/config.json';
    }

    /**
     * Check if primaryApiKey is set to "any".
     */
    public function isApplied(): bool
    {
        $config = $this->readConfig();
        if ($config === null) {
            return false;
        }
        return ($config['primaryApiKey'] ?? null) === 'any';
    }

    /**
     * Set primaryApiKey = "any" in config.json.
     *
     * @return bool True if config was changed, false if already applied.
     */
    public function apply(): bool
    {
        $path = $this->getConfigPath();
        $config = $this->readConfig() ?? [];

        if (($config['primaryApiKey'] ?? null) === 'any') {
            return false;
        }

        $config['primaryApiKey'] = 'any';
        $this->writeConfig($config);
        return true;
    }

    /**
     * Remove primaryApiKey from config.json.
     *
     * @return bool True if field was removed, false if not present.
     */
    public function clear(): bool
    {
        $config = $this->readConfig();
        if ($config === null || !array_key_exists('primaryApiKey', $config)) {
            return false;
        }

        unset($config['primaryApiKey']);
        $this->writeConfig($config);
        return true;
    }

    /**
     * Get the current plugin status.
     *
     * @return array{exists: bool, path: string, applied: bool}
     */
    public function getStatus(): array
    {
        $path = $this->getConfigPath();
        return [
            'exists' => file_exists($path),
            'path' => $path,
            'applied' => $this->isApplied(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readConfig(): ?array
    {
        $path = $this->getConfigPath();
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $path = $this->getConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        AtomicFile::writeJson($path, $config);
    }
}
