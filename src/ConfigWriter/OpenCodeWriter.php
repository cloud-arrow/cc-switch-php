<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\Provider;
use CcSwitch\Util\AtomicFile;

/**
 * Writes OpenCode configuration to ~/.config/opencode/opencode.json.
 *
 * OpenCode uses additive mode: all providers coexist in a single config file.
 * Each provider is stored under the "provider" key in the JSON structure.
 */
class OpenCodeWriter implements WriterInterface
{
    public function write(Provider $provider): void
    {
        $settingsConfig = json_decode($provider->settings_config, true);
        if (!is_array($settingsConfig)) {
            $settingsConfig = [];
        }

        $config = self::readConfig();

        if (!isset($config['provider'])) {
            $config['provider'] = [];
        }

        $config['provider'][$provider->id] = $settingsConfig;

        self::writeConfig($config);
    }

    /**
     * Write ALL providers at once (additive mode bulk write).
     *
     * Replaces the entire "provider" section. Other config keys are preserved.
     *
     * @param Provider[] $providers
     */
    public function writeAll(array $providers): void
    {
        $config = self::readConfig();
        $config['provider'] = [];

        foreach ($providers as $provider) {
            $settingsConfig = json_decode($provider->settings_config, true);
            if (is_array($settingsConfig)) {
                $config['provider'][$provider->id] = $settingsConfig;
            }
        }

        self::writeConfig($config);
    }

    public function remove(string $providerId): void
    {
        $config = self::readConfig();

        if (isset($config['provider'][$providerId])) {
            unset($config['provider'][$providerId]);
            self::writeConfig($config);
        }
    }

    /**
     * Read the current OpenCode config, returning a default if missing.
     *
     * @return array<string, mixed>
     */
    public static function readConfig(): array
    {
        $path = self::getConfigPath();
        if (!file_exists($path)) {
            return ['$schema' => 'https://opencode.ai/config.json'];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['$schema' => 'https://opencode.ai/config.json'];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : ['$schema' => 'https://opencode.ai/config.json'];
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
        return $home . '/.config/opencode';
    }

    public static function getConfigPath(): string
    {
        return (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) . '/.config/opencode/opencode.json';
    }
}
