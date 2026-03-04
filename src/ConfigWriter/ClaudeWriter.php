<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\Provider;
use CcSwitch\Util\AtomicFile;

/**
 * Writes Claude Code configuration to ~/.claude/settings.json.
 *
 * The provider's settings_config JSON is merged into the existing settings.json:
 * - Only the `env` keys are overwritten (ANTHROPIC_AUTH_TOKEN, ANTHROPIC_BASE_URL, etc.)
 * - Internal-only fields (api_format, apiFormat, openrouter_compat_mode) are stripped
 * - All other user settings are preserved
 */
class ClaudeWriter implements WriterInterface
{
    /**
     * Internal-only fields that must never be written to Claude settings.json.
     */
    private const INTERNAL_FIELDS = [
        'api_format',
        'apiFormat',
        'openrouter_compat_mode',
        'openrouterCompatMode',
    ];

    public function write(Provider $provider): void
    {
        $path = $this->getSettingsPath();

        $newSettings = json_decode($provider->settings_config, true);
        if (!is_array($newSettings)) {
            $newSettings = [];
        }

        // Remove internal-only fields
        foreach (self::INTERNAL_FIELDS as $field) {
            unset($newSettings[$field]);
        }

        // Read existing settings to preserve user customizations
        $existing = [];
        if (file_exists($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
        }

        // Merge: overwrite env from provider, preserve everything else
        if (isset($newSettings['env']) && is_array($newSettings['env'])) {
            if (!isset($existing['env']) || !is_array($existing['env'])) {
                $existing['env'] = [];
            }
            foreach ($newSettings['env'] as $key => $value) {
                $existing['env'][$key] = $value;
            }
        }

        // Also merge any top-level keys from settings_config except env
        // (e.g., model, permissions, etc.)
        foreach ($newSettings as $key => $value) {
            if ($key === 'env') {
                continue;
            }
            $existing[$key] = $value;
        }

        AtomicFile::writeJson($path, $existing);
    }

    public function remove(string $providerId): void
    {
        $path = $this->getSettingsPath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Get the Claude settings.json path.
     *
     * Prefers settings.json, falls back to legacy claude.json if it exists.
     */
    public function getSettingsPath(): string
    {
        $dir = $this->getConfigDir();
        $settings = $dir . '/settings.json';
        if (file_exists($settings)) {
            return $settings;
        }
        $legacy = $dir . '/claude.json';
        if (file_exists($legacy)) {
            return $legacy;
        }
        return $settings;
    }

    public function getConfigDir(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return $home . '/.claude';
    }
}
