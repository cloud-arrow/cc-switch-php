<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the providers table.
 */
class ProviderRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * List all providers for an app type, ordered by sort_index.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $app): array
    {
        return $this->db->select('providers', '*', [
            'app_type' => $app,
            'ORDER' => ['sort_index' => 'ASC'],
        ]) ?? [];
    }

    /**
     * Get the current active provider for an app type.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrent(string $app): ?array
    {
        $row = $this->db->get('providers', '*', [
            'app_type' => $app,
            'is_current' => 1,
        ]);
        return $row ?: null;
    }

    /**
     * Get a single provider by id and app type.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id, string $app): ?array
    {
        $row = $this->db->get('providers', '*', [
            'id' => $id,
            'app_type' => $app,
        ]);
        return $row ?: null;
    }

    /**
     * Insert a new provider.
     */
    public function insert(array $data): void
    {
        $this->db->insert('providers', $data);
    }

    /**
     * Update a provider by id and app type.
     */
    public function update(string $id, string $app, array $data): void
    {
        $this->db->update('providers', $data, [
            'id' => $id,
            'app_type' => $app,
        ]);
    }

    /**
     * Delete a provider by id and app type.
     */
    public function delete(string $id, string $app): void
    {
        $this->db->delete('providers', [
            'id' => $id,
            'app_type' => $app,
        ]);
    }

    /**
     * Clear the current provider flag for an app type.
     */
    public function clearCurrent(string $app): void
    {
        $this->db->update('providers', ['is_current' => 0], [
            'app_type' => $app,
            'is_current' => 1,
        ]);
    }

    /**
     * Set a provider as current for an app type.
     */
    public function setCurrent(string $id, string $app): void
    {
        $this->db->update('providers', ['is_current' => 1], [
            'id' => $id,
            'app_type' => $app,
        ]);
    }

    /**
     * Atomically switch the current provider (clear old, set new).
     */
    public function switchTo(string $id, string $app): void
    {
        $this->db->action(function (Medoo $db) use ($id, $app) {
            $db->update('providers', ['is_current' => 0], [
                'app_type' => $app,
                'is_current' => 1,
            ]);
            $db->update('providers', ['is_current' => 1], [
                'id' => $id,
                'app_type' => $app,
            ]);
        });
    }

    /**
     * Get providers in the failover queue for an app type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByFailoverQueue(string $app): array
    {
        return $this->db->select('providers', '*', [
            'app_type' => $app,
            'in_failover_queue' => 1,
            'ORDER' => ['sort_index' => 'ASC'],
        ]) ?? [];
    }
}
