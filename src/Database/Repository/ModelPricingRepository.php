<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use CcSwitch\Model\ModelPricing;
use Medoo\Medoo;

/**
 * Repository for the model_pricing table.
 */
class ModelPricingRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * Find pricing by model ID, with name normalization.
     */
    public function findByModelId(string $modelId): ?array
    {
        $normalized = ModelPricing::normalizeModelId($modelId);

        $row = $this->db->get('model_pricing', '*', ['model_id' => $normalized]);
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->db->select('model_pricing', '*', [
            'ORDER' => ['model_id' => 'ASC'],
        ]) ?? [];
    }

    /**
     * Insert or update a model pricing entry.
     */
    public function upsert(array $data): void
    {
        $modelId = $data['model_id'] ?? '';
        $existing = $this->db->get('model_pricing', 'model_id', ['model_id' => $modelId]);

        if ($existing) {
            $this->db->update('model_pricing', $data, ['model_id' => $modelId]);
        } else {
            $this->db->insert('model_pricing', $data);
        }
    }

    /**
     * Delete a model pricing entry.
     */
    public function delete(string $modelId): void
    {
        $this->db->delete('model_pricing', ['model_id' => $modelId]);
    }
}
