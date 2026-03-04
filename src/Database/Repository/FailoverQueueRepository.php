<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for managing the failover queue (via providers.in_failover_queue flag).
 */
class FailoverQueueRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    /**
     * List providers in the failover queue for an app type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $app): array
    {
        return $this->db->select('providers', '*', [
            'app_type' => $app,
            'in_failover_queue' => 1,
            'ORDER' => ['sort_index' => 'ASC'],
        ]) ?? [];
    }

    /**
     * Add a provider to the failover queue.
     */
    public function add(string $app, string $providerId, int $position): void
    {
        $this->db->update('providers', [
            'in_failover_queue' => 1,
            'sort_index' => $position,
        ], [
            'id' => $providerId,
            'app_type' => $app,
        ]);
    }

    /**
     * Remove a provider from the failover queue.
     */
    public function remove(string $app, string $providerId): void
    {
        $this->db->update('providers', [
            'in_failover_queue' => 0,
        ], [
            'id' => $providerId,
            'app_type' => $app,
        ]);
    }

    /**
     * Reorder providers in the failover queue.
     *
     * @param array<int, array{id: string, position: int}> $items
     */
    public function reorder(string $app, array $items): void
    {
        $this->db->action(function (Medoo $db) use ($app, $items) {
            foreach ($items as $item) {
                $db->update('providers', [
                    'sort_index' => $item['position'],
                ], [
                    'id' => $item['id'],
                    'app_type' => $app,
                    'in_failover_queue' => 1,
                ]);
            }
        });
    }
}
