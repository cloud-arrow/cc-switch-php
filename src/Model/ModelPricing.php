<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * ModelPricing model representing a row in the model_pricing table.
 */
class ModelPricing
{
    public string $model_id = '';
    public string $display_name = '';
    public string $input_cost_per_million = '0';
    public string $output_cost_per_million = '0';
    public string $cache_read_cost_per_million = '0';
    public string $cache_creation_cost_per_million = '0';

    /**
     * Create a ModelPricing from a database row array.
     */
    public static function fromRow(array $row): self
    {
        $pricing = new self();
        $pricing->model_id = (string) ($row['model_id'] ?? '');
        $pricing->display_name = (string) ($row['display_name'] ?? '');
        $pricing->input_cost_per_million = (string) ($row['input_cost_per_million'] ?? '0');
        $pricing->output_cost_per_million = (string) ($row['output_cost_per_million'] ?? '0');
        $pricing->cache_read_cost_per_million = (string) ($row['cache_read_cost_per_million'] ?? '0');
        $pricing->cache_creation_cost_per_million = (string) ($row['cache_creation_cost_per_million'] ?? '0');
        return $pricing;
    }

    /**
     * Normalize a model name for pricing lookup.
     *
     * - Strip provider prefix before "/" (e.g. "moonshotai/kimi-k2" -> "kimi-k2")
     * - Strip suffix after ":" (e.g. "model:exa" -> "model")
     * - Replace "@" with "-" (e.g. "model@v2" -> "model-v2")
     */
    public static function normalizeModelId(string $modelId): string
    {
        // Strip provider prefix (e.g. "openai/gpt-5" -> "gpt-5")
        if (($slashPos = strrpos($modelId, '/')) !== false) {
            $modelId = substr($modelId, $slashPos + 1);
        }

        // Strip suffix after ":" (e.g. "model:exa" -> "model")
        if (($colonPos = strpos($modelId, ':')) !== false) {
            $modelId = substr($modelId, 0, $colonPos);
        }

        // Replace "@" with "-"
        $modelId = str_replace('@', '-', $modelId);

        return $modelId;
    }
}
