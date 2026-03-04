<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Model\Provider;

/**
 * Maps model names based on provider configuration.
 *
 * Supports mapping haiku/sonnet/opus model variants and
 * reasoning model override when thinking mode is enabled.
 */
class ModelMapper
{
    /**
     * Map a model name according to the provider's settings_config.
     *
     * @param string $model          The original model name from the request
     * @param array  $providerConfig Decoded settings_config (JSON object with "env" key)
     * @param bool   $hasThinking    Whether the request has thinking/reasoning enabled
     * @return string The mapped model name (unchanged if no mapping applies)
     */
    public function map(string $model, array $providerConfig, bool $hasThinking = false): string
    {
        $env = $providerConfig['env'] ?? [];
        if (empty($env)) {
            return $model;
        }

        $haiku = $this->envValue($env, 'ANTHROPIC_DEFAULT_HAIKU_MODEL');
        $sonnet = $this->envValue($env, 'ANTHROPIC_DEFAULT_SONNET_MODEL');
        $opus = $this->envValue($env, 'ANTHROPIC_DEFAULT_OPUS_MODEL');
        $default = $this->envValue($env, 'ANTHROPIC_MODEL');
        $reasoning = $this->envValue($env, 'ANTHROPIC_REASONING_MODEL');

        // No mapping configured at all
        if ($haiku === null && $sonnet === null && $opus === null && $default === null && $reasoning === null) {
            return $model;
        }

        // 1. Thinking mode: prefer reasoning model
        if ($hasThinking && $reasoning !== null) {
            return $reasoning;
        }

        $modelLower = strtolower($model);

        // 2. Match by model family
        if (str_contains($modelLower, 'haiku') && $haiku !== null) {
            return $haiku;
        }
        if (str_contains($modelLower, 'opus') && $opus !== null) {
            return $opus;
        }
        if (str_contains($modelLower, 'sonnet') && $sonnet !== null) {
            return $sonnet;
        }

        // 3. Default model fallback
        if ($default !== null) {
            return $default;
        }

        // 4. No mapping applies
        return $model;
    }

    /**
     * Detect whether thinking/reasoning mode is enabled in a request body.
     */
    public function hasThinkingEnabled(array $body): bool
    {
        $thinkingType = $body['thinking']['type'] ?? null;
        return $thinkingType === 'enabled' || $thinkingType === 'adaptive';
    }

    /**
     * Apply model mapping to a request body.
     *
     * @return array{body: array, originalModel: ?string, mappedModel: ?string}
     */
    public function apply(array $body, array $providerConfig): array
    {
        $originalModel = $body['model'] ?? null;
        if ($originalModel === null) {
            return ['body' => $body, 'originalModel' => null, 'mappedModel' => null];
        }

        $hasThinking = $this->hasThinkingEnabled($body);
        $mapped = $this->map($originalModel, $providerConfig, $hasThinking);

        if ($mapped !== $originalModel) {
            $body['model'] = $mapped;
            return ['body' => $body, 'originalModel' => $originalModel, 'mappedModel' => $mapped];
        }

        return ['body' => $body, 'originalModel' => $originalModel, 'mappedModel' => null];
    }

    private function envValue(array $env, string $key): ?string
    {
        $val = $env[$key] ?? null;
        if ($val === null || $val === '') {
            return null;
        }
        return (string) $val;
    }
}
