<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use PDO;

/**
 * Aggregate statistics from the proxy_request_logs table using raw PDO SQL.
 */
class UsageStatsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getSummary(?int $start = null, ?int $end = null): array
    {
        $start = $start ?? time() - 86400;
        $end = $end ?? time();

        $sql = <<<'SQL'
            SELECT
                COUNT(*) as total_requests,
                COALESCE(SUM(input_tokens), 0) as total_input_tokens,
                COALESCE(SUM(output_tokens), 0) as total_output_tokens,
                COALESCE(SUM(CAST(total_cost_usd AS REAL)), 0) as total_cost,
                COUNT(CASE WHEN status_code < 400 THEN 1 END) as success_count,
                COALESCE(AVG(latency_ms), 0) as avg_latency_ms,
                AVG(first_token_ms) as avg_first_token_ms
            FROM proxy_request_logs
            WHERE created_at >= :start AND created_at <= :end
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int) $row['total_requests'];
        $successCount = (int) $row['success_count'];

        return [
            'total_requests' => $total,
            'total_input_tokens' => (int) $row['total_input_tokens'],
            'total_output_tokens' => (int) $row['total_output_tokens'],
            'total_cost' => (float) $row['total_cost'],
            'success_rate' => $total > 0 ? round($successCount / $total * 100, 2) : 0,
            'avg_latency_ms' => round((float) $row['avg_latency_ms'], 2),
            'avg_first_token_ms' => $row['avg_first_token_ms'] !== null ? round((float) $row['avg_first_token_ms'], 2) : null,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    public function getTrends(?int $start = null, ?int $end = null): array
    {
        $start = $start ?? time() - 86400;
        $end = $end ?? time();
        $range = $end - $start;

        $bucket = $range <= 86400 ? 3600 : 86400;

        $sql = "SELECT
                    (created_at / {$bucket}) * {$bucket} as bucket,
                    COUNT(*) as requests,
                    SUM(input_tokens + output_tokens) as total_tokens,
                    SUM(CAST(total_cost_usd AS REAL)) as total_cost
                FROM proxy_request_logs
                WHERE created_at >= :start AND created_at <= :end
                GROUP BY bucket
                ORDER BY bucket";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(fn(array $row) => [
            'bucket' => (int) $row['bucket'],
            'requests' => (int) $row['requests'],
            'total_tokens' => (int) $row['total_tokens'],
            'total_cost' => (float) $row['total_cost'],
        ], $rows);

        return ['bucket_size' => $bucket, 'data' => $data];
    }

    public function getProviderStats(?int $start = null, ?int $end = null): array
    {
        $start = $start ?? time() - 86400;
        $end = $end ?? time();

        $sql = <<<'SQL'
            SELECT
                l.provider_id,
                p.name as provider_name,
                COUNT(*) as requests,
                SUM(l.input_tokens) + SUM(l.output_tokens) as total_tokens,
                SUM(CAST(l.total_cost_usd AS REAL)) as total_cost,
                COUNT(CASE WHEN l.status_code < 400 THEN 1 END) * 100.0 / COUNT(*) as success_rate,
                AVG(l.latency_ms) as avg_latency
            FROM proxy_request_logs l
            LEFT JOIN providers p ON l.provider_id = p.id
            WHERE l.created_at >= :start AND l.created_at <= :end
            GROUP BY l.provider_id
            ORDER BY total_cost DESC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => [
            'provider_id' => $row['provider_id'],
            'provider_name' => $row['provider_name'],
            'requests' => (int) $row['requests'],
            'total_tokens' => (int) $row['total_tokens'],
            'total_cost' => (float) $row['total_cost'],
            'success_rate' => round((float) $row['success_rate'], 2),
            'avg_latency' => round((float) $row['avg_latency'], 2),
        ], $rows);
    }

    public function getModelStats(?int $start = null, ?int $end = null): array
    {
        $start = $start ?? time() - 86400;
        $end = $end ?? time();

        $sql = <<<'SQL'
            SELECT
                model,
                COUNT(*) as requests,
                SUM(input_tokens + output_tokens) as total_tokens,
                SUM(CAST(total_cost_usd AS REAL)) as total_cost,
                AVG(CAST(total_cost_usd AS REAL)) as avg_cost
            FROM proxy_request_logs
            WHERE created_at >= :start AND created_at <= :end
            GROUP BY model
            ORDER BY total_cost DESC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => [
            'model' => $row['model'],
            'requests' => (int) $row['requests'],
            'total_tokens' => (int) $row['total_tokens'],
            'total_cost' => (float) $row['total_cost'],
            'avg_cost' => round((float) $row['avg_cost'], 6),
        ], $rows);
    }

    public function getRequestDetail(string $requestId): ?array
    {
        $sql = <<<'SQL'
            SELECT l.*, p.name as provider_name
            FROM proxy_request_logs l
            LEFT JOIN providers p ON l.provider_id = p.id
            WHERE l.request_id = :id
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
