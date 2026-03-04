<?php

declare(strict_types=1);

namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

/**
 * Repository for the proxy_request_logs table.
 */
class RequestLogRepository
{
    public function __construct(private readonly Medoo $db)
    {
    }

    public function insert(array $data): void
    {
        $this->db->insert('proxy_request_logs', $data);
    }

    /**
     * List request logs with optional filters.
     *
     * @param array<string, mixed> $filters Medoo WHERE conditions
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = $filters;
        $where['ORDER'] = ['created_at' => 'DESC'];
        $where['LIMIT'] = [$offset, $limit];

        return $this->db->select('proxy_request_logs', '*', $where) ?? [];
    }

    /**
     * Get aggregated stats for a given app type and time range.
     *
     * @return array<string, mixed>
     */
    public function stats(string $app, int $startTime, int $endTime): array
    {
        $where = [
            'app_type' => $app,
            'created_at[>=]' => $startTime,
            'created_at[<=]' => $endTime,
        ];

        $count = $this->db->count('proxy_request_logs', $where);

        $pdo = $this->db->pdo;
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) as total_requests,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(CAST(total_cost_usd AS REAL)) as total_cost,
                AVG(latency_ms) as avg_latency_ms,
                AVG(first_token_ms) as avg_first_token_ms
            FROM proxy_request_logs
            WHERE app_type = ? AND created_at >= ? AND created_at <= ?'
        );
        $stmt->execute([$app, $startTime, $endTime]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_requests' => (int) ($row['total_requests'] ?? 0),
            'total_input_tokens' => (int) ($row['total_input_tokens'] ?? 0),
            'total_output_tokens' => (int) ($row['total_output_tokens'] ?? 0),
            'total_cost' => (float) ($row['total_cost'] ?? 0),
            'avg_latency_ms' => (float) ($row['avg_latency_ms'] ?? 0),
            'avg_first_token_ms' => (float) ($row['avg_first_token_ms'] ?? 0),
        ];
    }
}
