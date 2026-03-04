<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\UniversalProviderRepository;
use CcSwitch\Model\UniversalProvider;
use Ramsey\Uuid\Uuid;

/**
 * Universal provider service: manages cross-app shared provider configurations.
 *
 * Universal providers are shared across Claude, Codex, and Gemini.
 * When modified, they synchronize settings to each enabled app.
 */
class UniversalProviderService
{
    public function __construct(
        private readonly UniversalProviderRepository $repo,
    ) {
    }

    /**
     * List all universal providers.
     *
     * @return UniversalProvider[]
     */
    public function list(): array
    {
        $rows = $this->repo->list();
        return array_map(fn(array $row) => UniversalProvider::fromRow($row), $rows);
    }

    /**
     * Get a universal provider by ID.
     */
    public function get(string $id): ?UniversalProvider
    {
        $row = $this->repo->get($id);
        return $row ? UniversalProvider::fromRow($row) : null;
    }

    /**
     * Add a new universal provider.
     */
    public function add(UniversalProvider $provider): void
    {
        if (empty($provider->id)) {
            $provider->id = Uuid::uuid4()->toString();
        }
        $provider->created_at = $provider->created_at ?? time();

        $this->repo->insert($this->toRow($provider));
    }

    /**
     * Create a universal provider from a preset template.
     *
     * @param array<string, mixed> $preset Preset data from config/presets/universal.json
     * @param string $baseUrl The base URL for the provider
     * @param string $apiKey The API key
     * @param string|null $customName Optional custom name override
     */
    public function addFromPreset(array $preset, string $baseUrl, string $apiKey, ?string $customName = null): UniversalProvider
    {
        $provider = new UniversalProvider();
        $provider->id = Uuid::uuid4()->toString();
        $provider->name = $customName ?? ($preset['name'] ?? 'Unknown');
        $provider->provider_type = $preset['providerType'] ?? 'custom_gateway';
        $provider->apps = json_encode($preset['defaultApps'] ?? ['claude' => true, 'codex' => true, 'gemini' => true]);
        $provider->base_url = $baseUrl;
        $provider->api_key = $apiKey;
        $provider->models = json_encode($preset['defaultModels'] ?? new \stdClass());
        $provider->website_url = $preset['websiteUrl'] ?? null;
        $provider->created_at = time();

        $this->add($provider);
        return $provider;
    }

    /**
     * Update an existing universal provider.
     */
    public function update(UniversalProvider $provider): void
    {
        $this->repo->update($provider->id, [
            'name' => $provider->name,
            'provider_type' => $provider->provider_type,
            'apps' => $provider->apps,
            'base_url' => $provider->base_url,
            'api_key' => $provider->api_key,
            'models' => $provider->models,
            'website_url' => $provider->website_url,
            'notes' => $provider->notes,
        ]);
    }

    /**
     * Delete a universal provider.
     */
    public function delete(string $id): void
    {
        $this->repo->delete($id);
    }

    /**
     * Load universal provider presets.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadPresets(): array
    {
        $file = dirname(__DIR__, 2) . '/config/presets/universal.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function toRow(UniversalProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'provider_type' => $provider->provider_type,
            'apps' => $provider->apps,
            'base_url' => $provider->base_url,
            'api_key' => $provider->api_key,
            'models' => $provider->models,
            'website_url' => $provider->website_url,
            'notes' => $provider->notes,
            'created_at' => $provider->created_at,
        ];
    }
}
