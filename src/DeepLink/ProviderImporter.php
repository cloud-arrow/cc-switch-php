<?php

declare(strict_types=1);

namespace CcSwitch\DeepLink;

use CcSwitch\Database\Repository\ProviderRepository;
use Ramsey\Uuid\Uuid;

/**
 * Import parsed provider deep link data into the database.
 */
class ProviderImporter
{
    public function __construct(
        private readonly ProviderRepository $repo,
    ) {
    }

    /**
     * Import a provider from parsed deep link data.
     *
     * @param array{app: string, name: string, homepage?: string|null, endpoint?: string|null, apiKey?: string|null, icon?: string|null, model?: string|null, notes?: string|null, enabled?: bool, config?: string, configFormat?: string} $data
     * @return string The created provider ID
     */
    public function import(array $data): string
    {
        $app = $data['app'];
        $name = $data['name'];

        // Build settings_config based on app type
        $settingsConfig = $this->buildSettingsConfig($data);

        // Generate ID
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?? 'custom');
        $timestamp = (int) (microtime(true) * 1000);
        $id = "{$sanitized}-{$timestamp}";

        $providerData = [
            'id' => $id,
            'app_type' => $app,
            'name' => $name,
            'settings_config' => json_encode($settingsConfig, JSON_UNESCAPED_SLASHES),
            'website_url' => $data['homepage'] ?? null,
            'notes' => $data['notes'] ?? null,
            'icon' => $data['icon'] ?? null,
            'created_at' => time(),
        ];

        $this->repo->insert($providerData);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildSettingsConfig(array $data): array
    {
        $app = $data['app'] ?? '';
        $endpoint = $data['endpoint'] ?? '';
        $apiKey = $data['apiKey'] ?? '';
        $model = $data['model'] ?? null;

        return match ($app) {
            'claude' => $this->buildClaudeSettings($apiKey, $endpoint, $model, $data),
            'codex' => $this->buildCodexSettings($apiKey, $endpoint, $model, $data),
            'gemini' => $this->buildGeminiSettings($apiKey, $endpoint, $model),
            'opencode' => $this->buildOpenCodeSettings($apiKey, $endpoint, $model),
            default => ['env' => []],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClaudeSettings(string $apiKey, string $endpoint, ?string $model, array $data): array
    {
        $env = [
            'ANTHROPIC_AUTH_TOKEN' => $apiKey,
            'ANTHROPIC_BASE_URL' => $endpoint,
        ];
        if ($model) {
            $env['ANTHROPIC_MODEL'] = $model;
        }
        return ['env' => $env];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCodexSettings(string $apiKey, string $endpoint, ?string $model, array $data): array
    {
        $providerName = strtolower(preg_replace('/[^a-z0-9_]/', '_', $data['name'] ?? 'custom') ?? 'custom');
        $modelName = $model ?? 'gpt-5-codex';
        $endpoint = rtrim($endpoint, '/');

        $configToml = "model_provider = \"{$providerName}\"\n"
            . "model = \"{$modelName}\"\n"
            . "model_reasoning_effort = \"high\"\n"
            . "\n"
            . "[model_providers.{$providerName}]\n"
            . "name = \"{$providerName}\"\n"
            . "base_url = \"{$endpoint}\"\n"
            . "wire_api = \"responses\"\n"
            . "requires_openai_auth = true\n";

        return [
            'auth' => ['OPENAI_API_KEY' => $apiKey],
            'config' => $configToml,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGeminiSettings(string $apiKey, string $endpoint, ?string $model): array
    {
        $env = [
            'GEMINI_API_KEY' => $apiKey,
            'GOOGLE_GEMINI_BASE_URL' => $endpoint,
        ];
        if ($model) {
            $env['GEMINI_MODEL'] = $model;
        }
        return ['env' => $env];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOpenCodeSettings(string $apiKey, string $endpoint, ?string $model): array
    {
        $options = [];
        if ($endpoint !== '') {
            $options['baseURL'] = $endpoint;
        }
        if ($apiKey !== '') {
            $options['apiKey'] = $apiKey;
        }

        $models = [];
        if ($model) {
            $models[$model] = ['name' => $model];
        }

        return [
            'npm' => '@ai-sdk/openai-compatible',
            'options' => $options,
            'models' => $models,
        ];
    }
}
