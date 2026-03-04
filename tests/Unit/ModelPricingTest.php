<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\ModelPricingRepository;
use CcSwitch\Model\ModelPricing;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class ModelPricingTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private ModelPricingRepository $repo;

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

        $this->repo = new ModelPricingRepository($this->medoo);
    }

    // --- Model name normalization tests ---

    public function testNormalizeStripProviderPrefix(): void
    {
        $this->assertSame('kimi-k2', ModelPricing::normalizeModelId('moonshotai/kimi-k2'));
        $this->assertSame('gpt-5', ModelPricing::normalizeModelId('openai/gpt-5'));
    }

    public function testNormalizeStripSuffixAfterColon(): void
    {
        $this->assertSame('model', ModelPricing::normalizeModelId('model:exa'));
        $this->assertSame('gpt-5', ModelPricing::normalizeModelId('gpt-5:latest'));
    }

    public function testNormalizeReplaceAtWithDash(): void
    {
        $this->assertSame('model-v2', ModelPricing::normalizeModelId('model@v2'));
    }

    public function testNormalizeCombined(): void
    {
        $this->assertSame('kimi-k2-v1', ModelPricing::normalizeModelId('moonshotai/kimi-k2@v1:exa'));
    }

    public function testNormalizeNoChange(): void
    {
        $this->assertSame('claude-opus-4-6-20260206', ModelPricing::normalizeModelId('claude-opus-4-6-20260206'));
    }

    // --- Repository tests ---

    public function testFindByModelIdDirect(): void
    {
        $result = $this->repo->findByModelId('claude-opus-4-6-20260206');
        $this->assertNotNull($result);
        $this->assertSame('Claude Opus 4.6', $result['display_name']);
        $this->assertSame('5', $result['input_cost_per_million']);
        $this->assertSame('25', $result['output_cost_per_million']);
    }

    public function testFindByModelIdWithNormalization(): void
    {
        // With provider prefix
        $result = $this->repo->findByModelId('anthropic/claude-opus-4-6-20260206');
        $this->assertNotNull($result);
        $this->assertSame('Claude Opus 4.6', $result['display_name']);
    }

    public function testFindByModelIdNotFound(): void
    {
        $result = $this->repo->findByModelId('nonexistent-model');
        $this->assertNull($result);
    }

    public function testSeedDataCount(): void
    {
        $all = $this->repo->findAll();
        // Should have 60+ seeded models from migration
        $this->assertGreaterThanOrEqual(55, count($all));
    }

    public function testFindAllSortedByModelId(): void
    {
        $all = $this->repo->findAll();
        $modelIds = array_column($all, 'model_id');
        $sorted = $modelIds;
        sort($sorted);
        $this->assertSame($sorted, $modelIds);
    }

    public function testUpsertInsert(): void
    {
        $this->repo->upsert([
            'model_id' => 'custom-model-1',
            'display_name' => 'Custom Model',
            'input_cost_per_million' => '10',
            'output_cost_per_million' => '50',
            'cache_read_cost_per_million' => '1',
            'cache_creation_cost_per_million' => '5',
        ]);

        $result = $this->repo->findByModelId('custom-model-1');
        $this->assertNotNull($result);
        $this->assertSame('Custom Model', $result['display_name']);
        $this->assertSame('10', $result['input_cost_per_million']);
    }

    public function testUpsertUpdate(): void
    {
        $this->repo->upsert([
            'model_id' => 'claude-opus-4-6-20260206',
            'display_name' => 'Claude Opus 4.6 Updated',
            'input_cost_per_million' => '6',
            'output_cost_per_million' => '30',
            'cache_read_cost_per_million' => '0.60',
            'cache_creation_cost_per_million' => '7.50',
        ]);

        $result = $this->repo->findByModelId('claude-opus-4-6-20260206');
        $this->assertSame('Claude Opus 4.6 Updated', $result['display_name']);
        $this->assertSame('6', $result['input_cost_per_million']);
    }

    public function testDelete(): void
    {
        $this->repo->delete('claude-opus-4-6-20260206');
        $result = $this->repo->findByModelId('claude-opus-4-6-20260206');
        $this->assertNull($result);
    }

    // --- Model class tests ---

    public function testFromRow(): void
    {
        $pricing = ModelPricing::fromRow([
            'model_id' => 'test-model',
            'display_name' => 'Test Model',
            'input_cost_per_million' => '5',
            'output_cost_per_million' => '25',
            'cache_read_cost_per_million' => '0.50',
            'cache_creation_cost_per_million' => '6.25',
        ]);

        $this->assertSame('test-model', $pricing->model_id);
        $this->assertSame('Test Model', $pricing->display_name);
        $this->assertSame('5', $pricing->input_cost_per_million);
        $this->assertSame('25', $pricing->output_cost_per_million);
    }

    public function testFromRowDefaults(): void
    {
        $pricing = ModelPricing::fromRow([]);
        $this->assertSame('', $pricing->model_id);
        $this->assertSame('0', $pricing->input_cost_per_million);
    }

    // --- Specific model pricing verification ---

    public function testClaudeOpusPricing(): void
    {
        $result = $this->repo->findByModelId('claude-opus-4-5-20251101');
        $this->assertNotNull($result);
        $this->assertSame('5', $result['input_cost_per_million']);
        $this->assertSame('25', $result['output_cost_per_million']);
        $this->assertSame('0.50', $result['cache_read_cost_per_million']);
        $this->assertSame('6.25', $result['cache_creation_cost_per_million']);
    }

    public function testGptPricing(): void
    {
        $result = $this->repo->findByModelId('gpt-5.1-codex');
        $this->assertNotNull($result);
        $this->assertSame('1.25', $result['input_cost_per_million']);
        $this->assertSame('10', $result['output_cost_per_million']);
    }

    public function testGeminiPricing(): void
    {
        $result = $this->repo->findByModelId('gemini-3-pro-preview');
        $this->assertNotNull($result);
        $this->assertSame('2', $result['input_cost_per_million']);
        $this->assertSame('12', $result['output_cost_per_million']);
    }

    public function testDeepSeekPricing(): void
    {
        $result = $this->repo->findByModelId('deepseek-v3.2');
        $this->assertNotNull($result);
        $this->assertSame('2.00', $result['input_cost_per_million']);
        $this->assertSame('3.00', $result['output_cost_per_million']);
    }
}
