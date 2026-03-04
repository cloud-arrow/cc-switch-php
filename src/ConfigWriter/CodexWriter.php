<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\Provider;
use CcSwitch\Util\AtomicFile;
use RuntimeException;

/**
 * Writes Codex configuration to ~/.codex/auth.json + ~/.codex/config.toml.
 *
 * The settings_config is expected to have:
 *   { "auth": { ... }, "config": "toml-string" }
 *
 * Write is two-phase: auth.json first, then config.toml. If config.toml
 * write fails, auth.json is rolled back to its previous content.
 * The existing mcp_servers section in config.toml is preserved.
 */
class CodexWriter implements WriterInterface
{
    public function write(Provider $provider): void
    {
        $settingsConfig = json_decode($provider->settings_config, true);
        if (!is_array($settingsConfig)) {
            throw new RuntimeException("Codex provider settings_config is not valid JSON");
        }

        $authData = $settingsConfig['auth'] ?? null;
        $configText = $settingsConfig['config'] ?? '';

        if ($authData === null) {
            throw new RuntimeException("Codex provider config missing 'auth' field");
        }

        $dir = $this->getConfigDir();
        $authPath = $dir . '/auth.json';
        $configPath = $dir . '/config.toml';

        // Save old content for rollback
        $oldAuth = file_exists($authPath) ? file_get_contents($authPath) : null;

        // Preserve existing mcp_servers section from config.toml
        if (is_string($configText)) {
            $configText = $this->preserveMcpServers($configPath, $configText);
        }

        // Phase 1: write auth.json
        AtomicFile::writeJson($authPath, $authData);

        // Phase 2: write config.toml (rollback auth.json on failure)
        try {
            if (is_string($configText)) {
                AtomicFile::write($configPath, $configText);
            }
        } catch (RuntimeException $e) {
            // Rollback auth.json
            if ($oldAuth !== null) {
                AtomicFile::write($authPath, $oldAuth);
            } else {
                @unlink($authPath);
            }
            throw $e;
        }
    }

    public function remove(string $providerId): void
    {
        $dir = $this->getConfigDir();
        $authPath = $dir . '/auth.json';
        $configPath = $dir . '/config.toml';

        if (file_exists($authPath)) {
            unlink($authPath);
        }
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    }

    /**
     * Preserve the [mcp_servers] section from the existing config.toml.
     *
     * If the new config text does not contain [mcp_servers] but the existing
     * file does, append the existing section to the new content.
     */
    private function preserveMcpServers(string $configPath, string $newText): string
    {
        if (!file_exists($configPath)) {
            return $newText;
        }

        // If new text already has mcp_servers, no need to preserve
        if (str_contains($newText, '[mcp_servers]') || str_contains($newText, '[mcp_servers.')) {
            return $newText;
        }

        $oldContent = file_get_contents($configPath);
        if ($oldContent === false) {
            return $newText;
        }

        // Extract [mcp_servers] section and everything after it until the next top-level section
        $mcpSection = $this->extractMcpSection($oldContent);
        if ($mcpSection === '') {
            return $newText;
        }

        return rtrim($newText) . "\n\n" . $mcpSection . "\n";
    }

    /**
     * Extract the [mcp_servers] section (including sub-tables) from TOML text.
     */
    private function extractMcpSection(string $toml): string
    {
        $lines = explode("\n", $toml);
        $capturing = false;
        $captured = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Start capturing at [mcp_servers] or [mcp_servers.*]
            if (preg_match('/^\[mcp_servers(?:\..*)?\]/', $trimmed)) {
                $capturing = true;
                $captured[] = $line;
                continue;
            }

            // Stop capturing at the next non-mcp_servers top-level section
            if ($capturing && preg_match('/^\[[a-zA-Z]/', $trimmed) && !str_starts_with($trimmed, '[mcp_servers')) {
                break;
            }

            if ($capturing) {
                $captured[] = $line;
            }
        }

        return $capturing ? rtrim(implode("\n", $captured)) : '';
    }

    public function getConfigDir(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return $home . '/.codex';
    }

    public function getAuthPath(): string
    {
        return $this->getConfigDir() . '/auth.json';
    }

    public function getConfigPath(): string
    {
        return $this->getConfigDir() . '/config.toml';
    }
}
