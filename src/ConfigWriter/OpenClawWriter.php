<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\Provider;
use CcSwitch\Util\AtomicFile;

/**
 * Writes OpenClaw configuration to ~/.openclaw/openclaw.json.
 *
 * OpenClaw uses additive mode: all providers coexist in a single config file.
 * Each provider is stored under "models.providers" in the JSON structure.
 *
 * Config format:
 *   {
 *     "models": {
 *       "mode": "merge",
 *       "providers": {
 *         "provider-id": { "baseUrl": "...", "apiKey": "...", "models": [...] }
 *       }
 *     }
 *   }
 */
class OpenClawWriter implements WriterInterface
{
    public function write(Provider $provider): void
    {
        $settingsConfig = json_decode($provider->settings_config, true);
        if (!is_array($settingsConfig)) {
            $settingsConfig = [];
        }

        $config = self::readConfig();

        if (!isset($config['models'])) {
            $config['models'] = ['mode' => 'merge', 'providers' => []];
        }
        if (!isset($config['models']['providers'])) {
            $config['models']['providers'] = [];
        }

        $config['models']['providers'][$provider->id] = $settingsConfig;

        self::writeConfig($config);
    }

    /**
     * Write ALL providers at once (additive mode bulk write).
     *
     * Replaces the entire "models.providers" section. Other config keys are preserved.
     *
     * @param Provider[] $providers
     */
    public function writeAll(array $providers): void
    {
        $config = self::readConfig();

        if (!isset($config['models'])) {
            $config['models'] = ['mode' => 'merge'];
        }
        $config['models']['providers'] = [];

        foreach ($providers as $provider) {
            $settingsConfig = json_decode($provider->settings_config, true);
            if (is_array($settingsConfig)) {
                $config['models']['providers'][$provider->id] = $settingsConfig;
            }
        }

        self::writeConfig($config);
    }

    public function remove(string $providerId): void
    {
        $config = self::readConfig();

        if (isset($config['models']['providers'][$providerId])) {
            unset($config['models']['providers'][$providerId]);
            self::writeConfig($config);
        }
    }

    /**
     * Read the current OpenClaw config, returning a default if missing.
     *
     * @return array<string, mixed>
     */
    public static function readConfig(): array
    {
        $path = self::getConfigPath();
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function writeConfig(array $config): void
    {
        AtomicFile::writeJson(self::getConfigPath(), $config);
    }

    public function getConfigDir(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return $home . '/.openclaw';
    }

    public static function getConfigPath(): string
    {
        return (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) . '/.openclaw/openclaw.json';
    }
}
