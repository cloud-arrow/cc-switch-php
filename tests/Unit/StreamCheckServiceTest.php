<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\StreamCheckRepository;
use CcSwitch\Model\StreamCheckConfig;
use CcSwitch\Model\StreamCheckResult;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StreamCheckService-related models and repository logic.
 *
 * Since StreamCheckService's check methods require real HTTP connections,
 * we test the testable components: StreamCheckResult, StreamCheckConfig,
 * and StreamCheckRepository persistence.
 */
class StreamCheckServiceTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private SettingsRepository $settingsRepo;
    private StreamCheckRepository $streamCheckRepo;

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

        $this->settingsRepo = new SettingsRepository($this->medoo);
        $this->streamCheckRepo = new StreamCheckRepository($this->medoo, $this->settingsRepo);
    }

    // --- StreamCheckResult status determination ---

    public function testCheckResultOperational(): void
    {
        $result = new StreamCheckResult();
        $result->status = 'operational';
        $result->success = true;
        $result->message = 'Check succeeded';
        $result->response_time_ms = 500; // Well below 6000ms threshold
        $result->tested_at = time();

        $this->assertSame('operational', $result->status);
        $this->assertTrue($result->success);

        $arr = $result->toArray();
        $this->assertSame('operational', $arr['status']);
        $this->assertTrue($arr['success']);
        $this->assertSame(500, $arr['response_time_ms']);
    }

    public function testCheckResultDegraded(): void
    {
        $result = new StreamCheckResult();
        $result->status = 'degraded';
        $result->success = true;
        $result->message = 'Check succeeded';
        $result->response_time_ms = 8000; // Above 6000ms threshold
        $result->tested_at = time();

        $this->assertSame('degraded', $result->status);
        $this->assertTrue($result->success);
    }

    public function testCheckResultFailed(): void
    {
        $result = new StreamCheckResult();
        $result->status = 'failed';
        $result->success = false;
        $result->message = 'Connection timed out';
        $result->response_time_ms = 45000;
        $result->tested_at = time();

        $this->assertSame('failed', $result->status);
        $this->assertFalse($result->success);
    }

    // --- StreamCheckConfig merge logic ---

    public function testConfigMerging(): void
    {
        // Default config
        $defaultConfig = new StreamCheckConfig();
        $this->assertSame(6000, $defaultConfig->degraded_threshold_ms);
        $this->assertSame(45, $defaultConfig->timeout_secs);

        // Per-provider override via fromArray
        $overrideConfig = StreamCheckConfig::fromArray([
            'degraded_threshold_ms' => 3000,
            'timeout_secs' => 30,
        ]);

        $this->assertSame(3000, $overrideConfig->degraded_threshold_ms);
        $this->assertSame(30, $overrideConfig->timeout_secs);
        // Non-overridden fields keep defaults
        $this->assertSame(2, $overrideConfig->max_retries);
        $this->assertSame('claude-haiku-4-5-20251001', $overrideConfig->claude_model);
    }

    // --- StreamCheckRepository persistence ---

    public function testSaveLogAndRetrieval(): void
    {
        $result = new StreamCheckResult();
        $result->status = 'operational';
        $result->success = true;
        $result->message = 'Check succeeded';
        $result->response_time_ms = 1200;
        $result->http_status = 200;
        $result->model_used = 'claude-haiku-4-5-20251001';
        $result->tested_at = time();
        $result->retry_count = 0;

        $id = $this->streamCheckRepo->saveLog('provider-1', 'Test Provider', 'claude', $result);
        $this->assertGreaterThan(0, $id);

        // Verify the log was persisted
        $row = $this->pdo->query("SELECT * FROM stream_check_logs WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('provider-1', $row['provider_id']);
        $this->assertSame('Test Provider', $row['provider_name']);
        $this->assertSame('claude', $row['app_type']);
        $this->assertSame('operational', $row['status']);
        $this->assertSame(1, (int) $row['success']);
        $this->assertSame(1200, (int) $row['response_time_ms']);
        $this->assertSame(200, (int) $row['http_status']);
        $this->assertSame('claude-haiku-4-5-20251001', $row['model_used']);
    }

    public function testSaveLogFailedResult(): void
    {
        $result = new StreamCheckResult();
        $result->status = 'failed';
        $result->success = false;
        $result->message = 'HTTP 529: Overloaded';
        $result->response_time_ms = 3000;
        $result->http_status = null;
        $result->model_used = 'claude-haiku-4-5-20251001';
        $result->tested_at = time();
        $result->retry_count = 2;

        $id = $this->streamCheckRepo->saveLog('provider-2', 'Failed Provider', 'claude', $result);
        $this->assertGreaterThan(0, $id);

        $row = $this->pdo->query("SELECT * FROM stream_check_logs WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(0, (int) $row['success']);
        $this->assertSame(2, (int) $row['retry_count']);
    }

    // --- StreamCheckRepository config persistence ---

    public function testConfigPersistenceRoundTrip(): void
    {
        $config = new StreamCheckConfig();
        $config->timeout_secs = 30;
        $config->max_retries = 5;
        $config->degraded_threshold_ms = 3000;
        $config->claude_model = 'claude-opus-4-6-20260206';

        $this->streamCheckRepo->saveConfig($config);

        $loaded = $this->streamCheckRepo->getConfig();
        $this->assertSame(30, $loaded->timeout_secs);
        $this->assertSame(5, $loaded->max_retries);
        $this->assertSame(3000, $loaded->degraded_threshold_ms);
        $this->assertSame('claude-opus-4-6-20260206', $loaded->claude_model);
    }

    public function testGetConfigReturnsDefaultsWhenNotSet(): void
    {
        $config = $this->streamCheckRepo->getConfig();
        $this->assertSame(45, $config->timeout_secs);
        $this->assertSame(2, $config->max_retries);
        $this->assertSame(6000, $config->degraded_threshold_ms);
    }

    public function testResultToArrayContainsAllFields(): void
    {
        $result = new StreamCheckResult();
        $result->status = 'operational';
        $result->success = true;
        $result->message = 'OK';
        $result->response_time_ms = 100;
        $result->http_status = 200;
        $result->model_used = 'test-model';
        $result->tested_at = 1234567890;
        $result->retry_count = 1;

        $arr = $result->toArray();
        $this->assertArrayHasKey('status', $arr);
        $this->assertArrayHasKey('success', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayHasKey('response_time_ms', $arr);
        $this->assertArrayHasKey('http_status', $arr);
        $this->assertArrayHasKey('model_used', $arr);
        $this->assertArrayHasKey('tested_at', $arr);
        $this->assertArrayHasKey('retry_count', $arr);

        $this->assertSame('operational', $arr['status']);
        $this->assertSame(1234567890, $arr['tested_at']);
        $this->assertSame(1, $arr['retry_count']);
    }
}
