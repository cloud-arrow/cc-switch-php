<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\ConfigWriter\WriterFactory;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Model\Provider;
use Ramsey\Uuid\Uuid;

/**
 * Provider business logic: CRUD, switch mode, additive mode, preset loading.
 */
class ProviderService
{
    public function __construct(
        private readonly ProviderRepository $repo,
    ) {
    }

    /**
     * List all providers for an app type.
     *
     * @return Provider[]
     */
    public function list(AppType $app): array
    {
        $rows = $this->repo->list($app->value);
        return array_map(fn(array $row) => Provider::fromRow($row), $rows);
    }

    /**
     * Get the current active provider for a switch-mode app.
     * Returns null for additive-mode apps or when no provider is set.
     */
    public function getCurrent(AppType $app): ?Provider
    {
        if ($app->isAdditiveMode()) {
            return null;
        }
        $row = $this->repo->getCurrent($app->value);
        return $row ? Provider::fromRow($row) : null;
    }

    /**
     * Get a single provider by ID.
     */
    public function get(string $id, AppType $app): ?Provider
    {
        $row = $this->repo->get($id, $app->value);
        return $row ? Provider::fromRow($row) : null;
    }

    /**
     * Add a new provider. For switch-mode apps, if no current provider exists,
     * it becomes current automatically. For additive-mode apps, it writes to
     * the live config immediately.
     */
    public function add(AppType $app, Provider $provider): void
    {
        if (empty($provider->id)) {
            $provider->id = Uuid::uuid4()->toString();
        }
        $provider->app_type = $app->value;
        $provider->created_at = $provider->created_at ?? time();

        $this->repo->insert($this->toRow($provider));

        if ($app->isAdditiveMode()) {
            $writer = WriterFactory::create($app);
            $writer->write($provider);
            return;
        }

        // Switch mode: auto-set as current if none exists
        $current = $this->repo->getCurrent($app->value);
        if ($current === null) {
            $this->repo->switchTo($provider->id, $app->value);
            $writer = WriterFactory::create($app);
            $writer->write($provider);
        }
    }

    /**
     * Update an existing provider.
     */
    public function update(AppType $app, Provider $provider): void
    {
        $this->repo->update($provider->id, $app->value, $this->toUpdateRow($provider));

        if ($app->isAdditiveMode()) {
            $writer = WriterFactory::create($app);
            $writer->write($provider);
            return;
        }

        // Switch mode: sync live config if this is the current provider
        $current = $this->repo->getCurrent($app->value);
        if ($current && $current['id'] === $provider->id) {
            $writer = WriterFactory::create($app);
            $writer->write($provider);
        }
    }

    /**
     * Delete a provider by ID.
     *
     * @throws \RuntimeException if trying to delete the current provider in switch mode
     */
    public function delete(string $id, AppType $app): void
    {
        if ($app->isAdditiveMode()) {
            $this->repo->delete($id, $app->value);
            $writer = WriterFactory::create($app);
            $writer->remove($id);
            return;
        }

        // Switch mode: prevent deleting the current provider
        $current = $this->repo->getCurrent($app->value);
        if ($current && $current['id'] === $id) {
            throw new \RuntimeException('Cannot delete the currently active provider');
        }

        $this->repo->delete($id, $app->value);
    }

    /**
     * Switch to a different provider (switch-mode apps only).
     *
     * @throws \RuntimeException if the target provider doesn't exist
     * @throws \LogicException if called on an additive-mode app
     */
    public function switchTo(string $id, AppType $app): void
    {
        if ($app->isAdditiveMode()) {
            throw new \LogicException("Cannot switch providers for additive-mode app: {$app->value}");
        }

        $row = $this->repo->get($id, $app->value);
        if ($row === null) {
            throw new \RuntimeException("Provider {$id} not found for {$app->value}");
        }

        $this->repo->switchTo($id, $app->value);

        $provider = Provider::fromRow($row);
        $writer = WriterFactory::create($app);
        $writer->write($provider);
    }

    /**
     * Load presets for an app type from the config/presets/ JSON files.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadPresets(AppType $app): array
    {
        $file = dirname(__DIR__, 2) . '/config/presets/' . $app->value . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Add a provider from a preset template.
     *
     * @param array<string, mixed> $preset The preset data from loadPresets()
     * @param array<string, string> $overrides Key-value overrides (e.g., apiKey => 'sk-...')
     */
    public function addFromPreset(AppType $app, array $preset, array $overrides = []): Provider
    {
        $provider = new Provider();
        $provider->id = Uuid::uuid4()->toString();
        $provider->app_type = $app->value;
        $provider->name = $preset['name'] ?? 'Unknown';
        $provider->website_url = $preset['websiteUrl'] ?? null;
        $provider->category = $preset['category'] ?? null;
        $provider->icon = $preset['icon'] ?? null;
        $provider->icon_color = $preset['iconColor'] ?? null;
        $provider->created_at = time();

        // Build settings_config from preset, applying overrides
        $settingsConfig = $preset['settingsConfig'] ?? [];
        if ($app === AppType::Codex) {
            // Codex uses auth + config fields
            $settingsConfig = [
                'auth' => $preset['auth'] ?? [],
                'config' => $preset['config'] ?? '',
            ];
            if (isset($overrides['apiKey']) && isset($settingsConfig['auth']['OPENAI_API_KEY'])) {
                $settingsConfig['auth']['OPENAI_API_KEY'] = $overrides['apiKey'];
            }
        } else {
            // Claude, Gemini, OpenCode, OpenClaw use settingsConfig directly
            $settingsConfig = $this->applyOverrides($settingsConfig, $overrides);
        }

        $provider->settings_config = json_encode($settingsConfig, JSON_UNESCAPED_SLASHES);

        // Build meta
        $meta = [];
        if (isset($preset['endpointCandidates'])) {
            $meta['customEndpoints'] = $preset['endpointCandidates'];
        }
        if (isset($preset['apiFormat'])) {
            $meta['apiFormat'] = $preset['apiFormat'];
        }
        $provider->meta = json_encode($meta ?: new \stdClass(), JSON_UNESCAPED_SLASHES);

        $this->add($app, $provider);
        return $provider;
    }

    /**
     * Export all providers for an app type as an array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(AppType $app): array
    {
        $providers = $this->list($app);
        return array_map(fn(Provider $p) => $this->toRow($p), $providers);
    }

    /**
     * Import providers from an exported array.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function import(AppType $app, array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $provider = Provider::fromRow($row);
            $provider->app_type = $app->value;
            if (empty($provider->id)) {
                $provider->id = Uuid::uuid4()->toString();
            }
            // Check if already exists
            $existing = $this->repo->get($provider->id, $app->value);
            if ($existing) {
                $this->repo->update($provider->id, $app->value, $this->toUpdateRow($provider));
            } else {
                $this->repo->insert($this->toRow($provider));
            }
            $count++;
        }
        return $count;
    }

    /**
     * Apply overrides to settings config (recursive for nested env keys).
     */
    private function applyOverrides(array $config, array $overrides): array
    {
        if (empty($overrides)) {
            return $config;
        }

        // For Claude/Gemini: overrides go into env
        if (isset($config['env']) && is_array($config['env'])) {
            foreach ($overrides as $key => $value) {
                // Map common override names to env keys
                if ($key === 'apiKey') {
                    // Find the auth token key
                    foreach (['ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_API_KEY', 'GOOGLE_API_KEY'] as $envKey) {
                        if (array_key_exists($envKey, $config['env'])) {
                            $config['env'][$envKey] = $value;
                            break;
                        }
                    }
                } elseif ($key === 'baseUrl' && isset($config['env']['ANTHROPIC_BASE_URL'])) {
                    $config['env']['ANTHROPIC_BASE_URL'] = $value;
                } else {
                    $config['env'][$key] = $value;
                }
            }
        }

        // For OpenCode/OpenClaw: overrides go into options
        if (isset($config['options']) && is_array($config['options'])) {
            foreach ($overrides as $key => $value) {
                if ($key === 'apiKey' && array_key_exists('apiKey', $config['options'])) {
                    $config['options']['apiKey'] = $value;
                } elseif ($key === 'baseURL' && array_key_exists('baseURL', $config['options'])) {
                    $config['options']['baseURL'] = $value;
                } elseif ($key === 'baseUrl' && array_key_exists('baseUrl', $config)) {
                    $config['baseUrl'] = $value;
                }
            }
        }

        return $config;
    }

    /**
     * Get custom endpoints for a provider.
     *
     * @return array<int, array{url: string, addedAt?: int}>
     */
    public function getEndpoints(string $id, AppType $app): array
    {
        $provider = $this->get($id, $app);
        if (!$provider) {
            return [];
        }
        $meta = $provider->decodeMeta();
        return $meta->customEndpoints ?? [];
    }

    /**
     * Add a custom endpoint to a provider.
     */
    public function addEndpoint(string $id, AppType $app, string $url): void
    {
        $provider = $this->get($id, $app);
        if (!$provider) {
            throw new \RuntimeException("Provider not found");
        }
        $meta = $provider->decodeMeta();
        $endpoints = $meta->customEndpoints ?? [];
        foreach ($endpoints as $ep) {
            if (($ep['url'] ?? '') === $url) {
                return;
            }
        }
        $endpoints[] = ['url' => $url, 'addedAt' => time()];
        $meta->customEndpoints = $endpoints;
        $this->repo->update($id, $app->value, ['meta' => $meta->toJson()]);
    }

    /**
     * Remove a custom endpoint from a provider.
     */
    public function removeEndpoint(string $id, AppType $app, string $url): void
    {
        $provider = $this->get($id, $app);
        if (!$provider) {
            throw new \RuntimeException("Provider not found");
        }
        $meta = $provider->decodeMeta();
        $endpoints = $meta->customEndpoints ?? [];
        $meta->customEndpoints = array_values(array_filter($endpoints, fn($ep) => $ep['url'] !== $url));
        $this->repo->update($id, $app->value, ['meta' => $meta->toJson()]);
    }

    /**
     * Convert Provider model to database row array.
     */
    private function toRow(Provider $provider): array
    {
        return [
            'id' => $provider->id,
            'app_type' => $provider->app_type,
            'name' => $provider->name,
            'settings_config' => $provider->settings_config,
            'website_url' => $provider->website_url,
            'category' => $provider->category,
            'created_at' => $provider->created_at,
            'sort_index' => $provider->sort_index,
            'notes' => $provider->notes,
            'icon' => $provider->icon,
            'icon_color' => $provider->icon_color,
            'meta' => $provider->meta,
            'is_current' => $provider->is_current,
            'in_failover_queue' => $provider->in_failover_queue,
        ];
    }

    /**
     * Convert Provider model to update-only fields (excludes PK fields).
     */
    private function toUpdateRow(Provider $provider): array
    {
        return [
            'name' => $provider->name,
            'settings_config' => $provider->settings_config,
            'website_url' => $provider->website_url,
            'category' => $provider->category,
            'sort_index' => $provider->sort_index,
            'notes' => $provider->notes,
            'icon' => $provider->icon,
            'icon_color' => $provider->icon_color,
            'meta' => $provider->meta,
            'in_failover_queue' => $provider->in_failover_queue,
        ];
    }
}
