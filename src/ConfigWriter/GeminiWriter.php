<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\Provider;
use CcSwitch\Util\AtomicFile;
use RuntimeException;

/**
 * Writes Gemini CLI configuration to ~/.gemini/.env (KEY=VALUE format).
 *
 * The settings_config is expected to have:
 *   { "env": { "GEMINI_API_KEY": "...", "GEMINI_MODEL": "..." }, "config": { ... } }
 *
 * The env field is written to .env as KEY=VALUE lines.
 * The config field (if present and an object) is merged into ~/.gemini/settings.json.
 */
class GeminiWriter implements WriterInterface
{
    public function write(Provider $provider): void
    {
        $settingsConfig = json_decode($provider->settings_config, true);
        if (!is_array($settingsConfig)) {
            $settingsConfig = [];
        }

        $envMap = $settingsConfig['env'] ?? [];
        $config = $settingsConfig['config'] ?? null;

        if (!is_array($envMap)) {
            $envMap = [];
        }

        // Write .env file
        $envPath = $this->getEnvPath();
        $dir = dirname($envPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $envContent = self::serializeEnv($envMap);
        AtomicFile::write($envPath, $envContent);

        // Set restrictive permissions on .env (contains API keys)
        chmod($envPath, 0600);

        // Write settings.json if config is provided
        if (is_array($config) && !empty($config)) {
            $settingsPath = $this->getSettingsPath();

            // Merge with existing settings to preserve mcpServers etc.
            $existing = [];
            if (file_exists($settingsPath)) {
                $content = file_get_contents($settingsPath);
                if ($content !== false) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $existing = $decoded;
                    }
                }
            }

            $merged = array_merge($existing, $config);
            AtomicFile::writeJson($settingsPath, $merged);
        }

        // Update security.auth.selectedType based on whether env has API key
        $this->updateAuthType($envMap);
    }

    public function remove(string $providerId): void
    {
        $envPath = $this->getEnvPath();
        if (file_exists($envPath)) {
            unlink($envPath);
        }
    }

    /**
     * Parse a .env file into a key-value array.
     *
     * @return array<string, string>
     */
    public static function parseEnv(string $content): array
    {
        $map = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));
            if ($key !== '' && preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                $map[$key] = $value;
            }
        }
        return $map;
    }

    /**
     * Serialize a key-value array to .env format.
     *
     * @param array<string, string> $map
     */
    public static function serializeEnv(array $map): string
    {
        ksort($map);
        $lines = [];
        foreach ($map as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        return implode("\n", $lines);
    }

    /**
     * Update the security.auth.selectedType in ~/.gemini/settings.json.
     *
     * @param array<string, string> $envMap
     */
    private function updateAuthType(array $envMap): void
    {
        $settingsPath = $this->getSettingsPath();

        $settings = [];
        if (file_exists($settingsPath)) {
            $content = file_get_contents($settingsPath);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        }

        // Determine auth type: if env has API key, use api-key mode; else OAuth
        $selectedType = !empty($envMap) && isset($envMap['GEMINI_API_KEY'])
            ? 'gemini-api-key'
            : 'oauth-personal';

        $settings['security']['auth']['selectedType'] = $selectedType;

        AtomicFile::writeJson($settingsPath, $settings);
    }

    public function getConfigDir(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return $home . '/.gemini';
    }

    public function getEnvPath(): string
    {
        return $this->getConfigDir() . '/.env';
    }

    public function getSettingsPath(): string
    {
        return $this->getConfigDir() . '/settings.json';
    }
}
