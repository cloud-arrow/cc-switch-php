<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\ModelPricingRepository;
use CcSwitch\Database\Repository\RequestLogRepository;
use CcSwitch\Proxy\UsageLogger;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class UsageLoggerTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private ModelPricingRepository $pricingRepo;
    private RequestLogRepository $logRepo;
    private UsageLogger $logger;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $this->medoo = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:',
            'pdo' => $this->pdo,
        ]);

        $this->pricingRepo = new ModelPricingRepository($this->medoo);
        $this->logRepo = new RequestLogRepository($this->medoo);
        $this->logger = new UsageLogger($this->logRepo, $this->pricingRepo);
    }

    public function testCalculateCostFromDatabase(): void
    {
        // claude-sonnet-4-20250514 has $3 input, $15 output in seeded data
        $this->logger->log([
            'provider_id' => 'test-provider',
            'app_type' => 'claude',
            'model' => 'claude-sonnet-4-20250514',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
        ]);

        $logs = $this->logRepo->list();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        // input: 1M tokens * $3/M = $3.00
        $this->assertEqualsWithDelta(3.0, (float) $log['input_cost_usd'], 0.001);
        // output: 1M tokens * $15/M = $15.00
        $this->assertEqualsWithDelta(15.0, (float) $log['output_cost_usd'], 0.001);
        // total: $3 + $15 = $18
        $this->assertEqualsWithDelta(18.0, (float) $log['total_cost_usd'], 0.001);
    }

    public function testCalculateCostWithNormalization(): void
    {
        // "anthropic/claude-sonnet-4-20250514:beta" should normalize to "claude-sonnet-4-20250514"
        $this->logger->log([
            'provider_id' => 'test-provider',
            'app_type' => 'claude',
            'model' => 'anthropic/claude-sonnet-4-20250514:beta',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
        ]);

        $logs = $this->logRepo->list();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        // Should use DB pricing ($3/$15) not hardcoded defaults
        $this->assertEqualsWithDelta(3.0, (float) $log['input_cost_usd'], 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $log['output_cost_usd'], 0.001);
    }

    public function testCalculateCostUnknownModelReturnsDefaultCost(): void
    {
        // Unknown model falls back to default rates ($3 input, $15 output)
        $this->logger->log([
            'provider_id' => 'test-provider',
            'app_type' => 'claude',
            'model' => 'unknown-model-xyz',
            'input_tokens' => 0,
            'output_tokens' => 0,
        ]);

        $logs = $this->logRepo->list();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        // With 0 tokens, cost should be 0 regardless of rates
        $this->assertEqualsWithDelta(0.0, (float) $log['total_cost_usd'], 0.001);
        // Verify no error occurred (the log was inserted successfully)
        $this->assertNull($log['error_message']);
    }

    public function testCalculateCostWithCacheTokens(): void
    {
        // claude-sonnet-4-20250514: cache_read=$0.30/M, cache_creation=$3.75/M
        $this->logger->log([
            'provider_id' => 'test-provider',
            'app_type' => 'claude',
            'model' => 'claude-sonnet-4-20250514',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 1_000_000,
            'cache_creation_tokens' => 1_000_000,
        ]);

        $logs = $this->logRepo->list();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        // cache_read: 1M * $0.30/M = $0.30
        $this->assertEqualsWithDelta(0.30, (float) $log['cache_read_cost_usd'], 0.001);
        // cache_creation: 1M * $3.75/M = $3.75
        $this->assertEqualsWithDelta(3.75, (float) $log['cache_creation_cost_usd'], 0.001);
        // total: $0.30 + $3.75 = $4.05
        $this->assertEqualsWithDelta(4.05, (float) $log['total_cost_usd'], 0.001);
    }

    public function testAllSeededModelsHavePricing(): void
    {
        $importantModels = [
            'claude-opus-4-6-20260206',
            'claude-opus-4-5-20251101',
            'claude-sonnet-4-5-20250929',
            'claude-haiku-4-5-20251001',
            'claude-opus-4-20250514',
            'claude-sonnet-4-20250514',
            'claude-3-5-haiku-20241022',
            'claude-3-5-sonnet-20241022',
            'gpt-5.2',
            'gpt-5.1',
            'gpt-5',
            'gemini-3-pro-preview',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
        ];

        foreach ($importantModels as $modelId) {
            $pricing = $this->pricingRepo->findByModelId($modelId);
            $this->assertNotNull($pricing, "Model '{$modelId}' should have pricing data in the database");
            $this->assertGreaterThan(0, (float) $pricing['input_cost_per_million'], "Model '{$modelId}' should have positive input cost");
            $this->assertGreaterThan(0, (float) $pricing['output_cost_per_million'], "Model '{$modelId}' should have positive output cost");
        }
    }

    public function testCostMultiplierIsApplied(): void
    {
        $this->logger->log([
            'provider_id' => 'test-provider',
            'app_type' => 'claude',
            'model' => 'claude-sonnet-4-20250514',
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'cost_multiplier' => '2.0',
        ]);

        $logs = $this->logRepo->list();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        // input: 1M tokens * $3/M * 2.0 = $6.00
        $this->assertEqualsWithDelta(6.0, (float) $log['input_cost_usd'], 0.001);
    }
}
