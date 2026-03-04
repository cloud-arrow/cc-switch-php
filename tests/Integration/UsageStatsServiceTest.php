<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use CcSwitch\Service\UsageStatsService;
use PDO;
use PHPUnit\Framework\TestCase;

class UsageStatsServiceTest extends TestCase
{
    private PDO $pdo;
    private UsageStatsService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $this->service = new UsageStatsService($this->pdo);

        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        // Insert providers
        $this->pdo->exec("INSERT INTO providers (id, app_type, name, settings_config, meta) VALUES
            ('prov-1', 'claude', 'Anthropic', '{}', '{}'),
            ('prov-2', 'claude', 'OpenRouter', '{}', '{}')
        ");

        $baseTime = 1700000000;

        // Insert request logs with varying timestamps, providers, models, and statuses
        $logs = [
            ['req-1', 'prov-1', 'claude', 'claude-sonnet-4', 1000, 500, '0.003', '0.0075', '0.0105', 150, 50, 200, $baseTime],
            ['req-2', 'prov-1', 'claude', 'claude-sonnet-4', 2000, 800, '0.006', '0.012', '0.018', 200, 60, 200, $baseTime + 1800],
            ['req-3', 'prov-2', 'claude', 'claude-opus-4', 500, 200, '0.0075', '0.003', '0.0105', 300, 80, 200, $baseTime + 3600],
            ['req-4', 'prov-1', 'claude', 'claude-sonnet-4', 100, 50, '0.0003', '0.00075', '0.00105', 100, 40, 500, $baseTime + 5400],
            ['req-5', 'prov-2', 'claude', 'claude-opus-4', 3000, 1500, '0.045', '0.0225', '0.0675', 250, 70, 200, $baseTime + 90000],
        ];

        $sql = "INSERT INTO proxy_request_logs
            (request_id, provider_id, app_type, model, input_tokens, output_tokens,
             input_cost_usd, output_cost_usd, total_cost_usd, latency_ms, first_token_ms, status_code, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->pdo->prepare($sql);
        foreach ($logs as $log) {
            $stmt->execute($log);
        }
    }

    public function testGetSummaryReturnsTotals(): void
    {
        $start = 1700000000;
        $end = 1700100000;
        $summary = $this->service->getSummary($start, $end);

        $this->assertSame(5, $summary['total_requests']);
        $this->assertSame(6600, $summary['total_input_tokens']); // 1000+2000+500+100+3000
        $this->assertSame(3050, $summary['total_output_tokens']); // 500+800+200+50+1500
        $this->assertEqualsWithDelta(0.10755, $summary['total_cost'], 0.0001);
        $this->assertEqualsWithDelta(80.0, $summary['success_rate'], 0.01); // 4/5 * 100
        $this->assertSame($start, $summary['period_start']);
        $this->assertSame($end, $summary['period_end']);
    }

    public function testGetSummaryWithEmptyRange(): void
    {
        $summary = $this->service->getSummary(0, 1);

        $this->assertSame(0, $summary['total_requests']);
        $this->assertSame(0, $summary['total_input_tokens']);
        $this->assertEqualsWithDelta(0.0, $summary['success_rate'], 0.01);
    }

    public function testGetTrendsHourlyBucketing(): void
    {
        // Range <= 24h => hourly bucketing (3600s)
        $start = 1700000000;
        $end = $start + 86400;
        $result = $this->service->getTrends($start, $end);

        $this->assertSame(3600, $result['bucket_size']);
        $this->assertNotEmpty($result['data']);

        // First bucket should have req-1, req-2 (same hour)
        $firstBucket = $result['data'][0];
        $this->assertSame(2, $firstBucket['requests']);
    }

    public function testGetTrendsDailyBucketing(): void
    {
        // Range > 24h => daily bucketing (86400s)
        $start = 1700000000;
        $end = $start + 200000;
        $result = $this->service->getTrends($start, $end);

        $this->assertSame(86400, $result['bucket_size']);
        $this->assertNotEmpty($result['data']);
    }

    public function testGetProviderStats(): void
    {
        $start = 1700000000;
        $end = 1700100000;
        $stats = $this->service->getProviderStats($start, $end);

        $this->assertCount(2, $stats);

        // Find prov-1 and prov-2 stats
        $byProvider = [];
        foreach ($stats as $s) {
            $byProvider[$s['provider_id']] = $s;
        }

        // prov-1: 3 requests (req-1, req-2, req-4), 1 error
        $prov1 = $byProvider['prov-1'];
        $this->assertSame(3, $prov1['requests']);
        $this->assertSame('Anthropic', $prov1['provider_name']);
        $this->assertEqualsWithDelta(66.67, $prov1['success_rate'], 0.01);

        // prov-2: 2 requests (req-3, req-5), all success
        $prov2 = $byProvider['prov-2'];
        $this->assertSame(2, $prov2['requests']);
        $this->assertSame('OpenRouter', $prov2['provider_name']);
        $this->assertEqualsWithDelta(100.0, $prov2['success_rate'], 0.01);
    }

    public function testGetModelStats(): void
    {
        $start = 1700000000;
        $end = 1700100000;
        $stats = $this->service->getModelStats($start, $end);

        $this->assertCount(2, $stats);

        $byModel = [];
        foreach ($stats as $s) {
            $byModel[$s['model']] = $s;
        }

        // claude-sonnet-4: 3 requests
        $this->assertSame(3, $byModel['claude-sonnet-4']['requests']);
        // claude-opus-4: 2 requests
        $this->assertSame(2, $byModel['claude-opus-4']['requests']);
    }

    public function testGetRequestDetail(): void
    {
        $detail = $this->service->getRequestDetail('req-1');

        $this->assertNotNull($detail);
        $this->assertSame('req-1', $detail['request_id']);
        $this->assertSame('prov-1', $detail['provider_id']);
        $this->assertSame('Anthropic', $detail['provider_name']);
        $this->assertSame('claude-sonnet-4', $detail['model']);
    }

    public function testGetRequestDetailNotFound(): void
    {
        $detail = $this->service->getRequestDetail('nonexistent');

        $this->assertNull($detail);
    }
}
