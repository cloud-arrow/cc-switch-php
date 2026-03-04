<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Schema Consistency Tests
 *
 * Validates that the PHP migration-generated database schema matches
 * the original Rust schema.rs definitions. Uses PRAGMA introspection
 * on an in-memory SQLite database after running all migrations.
 */
class SchemaConsistencyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();
    }

    // ─── helpers ────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTableInfo(string $table): array
    {
        return $this->pdo->query("PRAGMA table_info('{$table}')")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return string[]
     */
    private function getColumnNames(string $table): array
    {
        return array_column($this->getTableInfo($table), 'name');
    }

    /**
     * @return array<string, array<string, mixed>>  keyed by column name
     */
    private function getColumnsKeyed(string $table): array
    {
        $rows = $this->getTableInfo($table);
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row['name']] = $row;
        }
        return $keyed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getForeignKeys(string $table): array
    {
        return $this->pdo->query("PRAGMA foreign_key_list('{$table}')")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getIndexList(string $table): array
    {
        return $this->pdo->query("PRAGMA index_list('{$table}')")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assert a column exists with expected properties.
     *
     * @param array<string, array<string, mixed>> $columns  keyed column info
     */
    private function assertColumn(
        array $columns,
        string $name,
        string $type,
        bool $notNull,
        ?string $defaultValue = null,
        bool $isPk = false,
        string $table = '',
    ): void {
        $ctx = $table ? "[{$table}.{$name}]" : "[{$name}]";
        $this->assertArrayHasKey($name, $columns, "{$ctx} column must exist");

        $col = $columns[$name];
        $this->assertSame($type, $col['type'], "{$ctx} type mismatch");
        $this->assertSame(
            $notNull ? '1' : '0',
            (string) $col['notnull'],
            "{$ctx} NOT NULL mismatch",
        );

        if ($defaultValue !== null) {
            $actual = $col['dflt_value'];
            $this->assertNotNull($actual, "{$ctx} expected default '{$defaultValue}' but got NULL");
            // Normalize: strip outer quotes for comparison
            $normalized = trim((string) $actual, "'\"");
            $expectedNormalized = trim($defaultValue, "'\"");
            $this->assertSame($expectedNormalized, $normalized, "{$ctx} default value mismatch");
        }

        if ($isPk) {
            $this->assertGreaterThan(0, (int) $col['pk'], "{$ctx} should be part of PRIMARY KEY");
        }
    }

    // ─── 1. providers ──────────────────────────────────────────────

    public function testProvidersTableSchema(): void
    {
        $expectedColumns = [
            'id', 'app_type', 'name', 'settings_config', 'website_url',
            'category', 'created_at', 'sort_index', 'notes', 'icon',
            'icon_color', 'meta', 'is_current', 'in_failover_queue',
        ];

        $columns = $this->getColumnNames('providers');
        $this->assertSame($expectedColumns, $columns, 'providers: column names or order mismatch');
        $this->assertCount(14, $columns, 'providers: column count mismatch');

        $keyed = $this->getColumnsKeyed('providers');

        // Primary key columns
        $this->assertColumn($keyed, 'id', 'TEXT', true, null, true, 'providers');
        $this->assertColumn($keyed, 'app_type', 'TEXT', true, null, true, 'providers');

        // NOT NULL columns
        $this->assertColumn($keyed, 'name', 'TEXT', true, null, false, 'providers');
        $this->assertColumn($keyed, 'settings_config', 'TEXT', true, null, false, 'providers');
        $this->assertColumn($keyed, 'meta', 'TEXT', true, '{}', false, 'providers');
        $this->assertColumn($keyed, 'is_current', 'INTEGER', true, '0', false, 'providers');
        $this->assertColumn($keyed, 'in_failover_queue', 'INTEGER', true, '0', false, 'providers');

        // Nullable columns
        $this->assertColumn($keyed, 'website_url', 'TEXT', false, null, false, 'providers');
        $this->assertColumn($keyed, 'category', 'TEXT', false, null, false, 'providers');
        $this->assertColumn($keyed, 'created_at', 'INTEGER', false, null, false, 'providers');
        $this->assertColumn($keyed, 'sort_index', 'INTEGER', false, null, false, 'providers');
        $this->assertColumn($keyed, 'notes', 'TEXT', false, null, false, 'providers');
        $this->assertColumn($keyed, 'icon', 'TEXT', false, null, false, 'providers');
        $this->assertColumn($keyed, 'icon_color', 'TEXT', false, null, false, 'providers');
    }

    public function testProvidersIndexes(): void
    {
        $indexes = $this->getIndexList('providers');
        $indexNames = array_column($indexes, 'name');
        $this->assertContains('idx_providers_failover', $indexNames, 'providers: missing failover index');
    }

    // ─── 2. provider_endpoints ─────────────────────────────────────

    public function testProviderEndpointsTableSchema(): void
    {
        $expectedColumns = ['id', 'provider_id', 'app_type', 'url', 'added_at'];

        $columns = $this->getColumnNames('provider_endpoints');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(5, $columns);

        $keyed = $this->getColumnsKeyed('provider_endpoints');

        $this->assertColumn($keyed, 'id', 'INTEGER', false, null, true, 'provider_endpoints');
        $this->assertColumn($keyed, 'provider_id', 'TEXT', true, null, false, 'provider_endpoints');
        $this->assertColumn($keyed, 'app_type', 'TEXT', true, null, false, 'provider_endpoints');
        $this->assertColumn($keyed, 'url', 'TEXT', true, null, false, 'provider_endpoints');
        $this->assertColumn($keyed, 'added_at', 'INTEGER', false, null, false, 'provider_endpoints');
    }

    public function testProviderEndpointsForeignKeys(): void
    {
        $fks = $this->getForeignKeys('provider_endpoints');
        $this->assertNotEmpty($fks, 'provider_endpoints: should have foreign keys');

        // FK to providers(id, app_type) ON DELETE CASCADE
        $fkColumns = array_column($fks, 'from');
        $this->assertContains('provider_id', $fkColumns);
        $this->assertContains('app_type', $fkColumns);

        // All FK rows should reference 'providers' table with CASCADE delete
        foreach ($fks as $fk) {
            $this->assertSame('providers', $fk['table']);
            $this->assertSame('CASCADE', $fk['on_delete']);
        }
    }

    // ─── 3. universal_providers ────────────────────────────────────

    public function testUniversalProvidersTableSchema(): void
    {
        $expectedColumns = [
            'id', 'name', 'provider_type', 'apps', 'base_url',
            'api_key', 'models', 'website_url', 'notes', 'created_at',
        ];

        $columns = $this->getColumnNames('universal_providers');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(10, $columns);

        $keyed = $this->getColumnsKeyed('universal_providers');

        $this->assertColumn($keyed, 'id', 'TEXT', false, null, true, 'universal_providers');
        $this->assertColumn($keyed, 'name', 'TEXT', true, null, false, 'universal_providers');
        $this->assertColumn($keyed, 'provider_type', 'TEXT', true, null, false, 'universal_providers');
        $this->assertColumn($keyed, 'apps', 'TEXT', true, '{}', false, 'universal_providers');
        $this->assertColumn($keyed, 'base_url', 'TEXT', true, null, false, 'universal_providers');
        $this->assertColumn($keyed, 'api_key', 'TEXT', true, null, false, 'universal_providers');
        $this->assertColumn($keyed, 'models', 'TEXT', true, '{}', false, 'universal_providers');
        $this->assertColumn($keyed, 'website_url', 'TEXT', false, null, false, 'universal_providers');
        $this->assertColumn($keyed, 'notes', 'TEXT', false, null, false, 'universal_providers');
        $this->assertColumn($keyed, 'created_at', 'INTEGER', false, null, false, 'universal_providers');
    }

    // ─── 4. mcp_servers ────────────────────────────────────────────

    public function testMcpServersTableSchema(): void
    {
        $expectedColumns = [
            'id', 'name', 'server_config', 'description', 'homepage',
            'docs', 'tags', 'enabled_claude', 'enabled_codex',
            'enabled_gemini', 'enabled_opencode',
        ];

        $columns = $this->getColumnNames('mcp_servers');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(11, $columns);

        $keyed = $this->getColumnsKeyed('mcp_servers');

        $this->assertColumn($keyed, 'id', 'TEXT', false, null, true, 'mcp_servers');
        $this->assertColumn($keyed, 'name', 'TEXT', true, null, false, 'mcp_servers');
        $this->assertColumn($keyed, 'server_config', 'TEXT', true, null, false, 'mcp_servers');
        $this->assertColumn($keyed, 'description', 'TEXT', false, null, false, 'mcp_servers');
        $this->assertColumn($keyed, 'homepage', 'TEXT', false, null, false, 'mcp_servers');
        $this->assertColumn($keyed, 'docs', 'TEXT', false, null, false, 'mcp_servers');
        $this->assertColumn($keyed, 'tags', 'TEXT', true, '[]', false, 'mcp_servers');

        foreach (['enabled_claude', 'enabled_codex', 'enabled_gemini', 'enabled_opencode'] as $col) {
            $this->assertColumn($keyed, $col, 'INTEGER', true, '0', false, 'mcp_servers');
        }
    }

    // ─── 5. prompts ────────────────────────────────────────────────

    public function testPromptsTableSchema(): void
    {
        $expectedColumns = [
            'id', 'app_type', 'name', 'content', 'description',
            'enabled', 'created_at', 'updated_at',
        ];

        $columns = $this->getColumnNames('prompts');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(8, $columns);

        $keyed = $this->getColumnsKeyed('prompts');

        $this->assertColumn($keyed, 'id', 'TEXT', true, null, true, 'prompts');
        $this->assertColumn($keyed, 'app_type', 'TEXT', true, null, true, 'prompts');
        $this->assertColumn($keyed, 'name', 'TEXT', true, null, false, 'prompts');
        $this->assertColumn($keyed, 'content', 'TEXT', true, null, false, 'prompts');
        $this->assertColumn($keyed, 'description', 'TEXT', false, null, false, 'prompts');
        $this->assertColumn($keyed, 'enabled', 'INTEGER', true, '1', false, 'prompts');
        $this->assertColumn($keyed, 'created_at', 'INTEGER', false, null, false, 'prompts');
        $this->assertColumn($keyed, 'updated_at', 'INTEGER', false, null, false, 'prompts');
    }

    // ─── 6. skills ─────────────────────────────────────────────────

    public function testSkillsTableSchema(): void
    {
        $expectedColumns = [
            'id', 'name', 'description', 'directory', 'repo_owner',
            'repo_name', 'repo_branch', 'readme_url', 'enabled_claude',
            'enabled_codex', 'enabled_gemini', 'enabled_opencode',
            'installed_at',
        ];

        $columns = $this->getColumnNames('skills');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(13, $columns);

        $keyed = $this->getColumnsKeyed('skills');

        $this->assertColumn($keyed, 'id', 'TEXT', false, null, true, 'skills');
        $this->assertColumn($keyed, 'name', 'TEXT', true, null, false, 'skills');
        $this->assertColumn($keyed, 'description', 'TEXT', false, null, false, 'skills');
        $this->assertColumn($keyed, 'directory', 'TEXT', true, null, false, 'skills');
        $this->assertColumn($keyed, 'repo_owner', 'TEXT', false, null, false, 'skills');
        $this->assertColumn($keyed, 'repo_name', 'TEXT', false, null, false, 'skills');
        $this->assertColumn($keyed, 'repo_branch', 'TEXT', false, 'main', false, 'skills');
        $this->assertColumn($keyed, 'readme_url', 'TEXT', false, null, false, 'skills');

        foreach (['enabled_claude', 'enabled_codex', 'enabled_gemini', 'enabled_opencode'] as $col) {
            $this->assertColumn($keyed, $col, 'INTEGER', true, '0', false, 'skills');
        }

        $this->assertColumn($keyed, 'installed_at', 'INTEGER', true, '0', false, 'skills');
    }

    // ─── 7. skill_repos ────────────────────────────────────────────

    public function testSkillReposTableSchema(): void
    {
        $expectedColumns = ['owner', 'name', 'branch', 'enabled'];

        $columns = $this->getColumnNames('skill_repos');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(4, $columns);

        $keyed = $this->getColumnsKeyed('skill_repos');

        $this->assertColumn($keyed, 'owner', 'TEXT', true, null, true, 'skill_repos');
        $this->assertColumn($keyed, 'name', 'TEXT', true, null, true, 'skill_repos');
        $this->assertColumn($keyed, 'branch', 'TEXT', true, 'main', false, 'skill_repos');
        $this->assertColumn($keyed, 'enabled', 'INTEGER', true, '1', false, 'skill_repos');
    }

    public function testSkillReposSeedData(): void
    {
        $rows = $this->pdo->query("SELECT owner, name, branch FROM skill_repos ORDER BY owner")
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows, 'skill_repos should be seeded with 2 default repos');
        $this->assertSame('ComposioHQ', $rows[0]['owner']);
        $this->assertSame('awesome-claude-skills', $rows[0]['name']);
        $this->assertSame('anthropics', $rows[1]['owner']);
        $this->assertSame('skills', $rows[1]['name']);
    }

    // ─── 8. settings ───────────────────────────────────────────────

    public function testSettingsTableSchema(): void
    {
        $expectedColumns = ['key', 'value'];

        $columns = $this->getColumnNames('settings');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(2, $columns);

        $keyed = $this->getColumnsKeyed('settings');

        $this->assertColumn($keyed, 'key', 'TEXT', false, null, true, 'settings');
        $this->assertColumn($keyed, 'value', 'TEXT', false, null, false, 'settings');
    }

    // ─── 9. proxy_config ───────────────────────────────────────────

    public function testProxyConfigTableSchema(): void
    {
        $expectedColumns = [
            'app_type', 'proxy_enabled', 'listen_address', 'listen_port',
            'enable_logging', 'enabled', 'auto_failover_enabled', 'max_retries',
            'streaming_first_byte_timeout', 'streaming_idle_timeout',
            'non_streaming_timeout', 'circuit_failure_threshold',
            'circuit_success_threshold', 'circuit_timeout_seconds',
            'circuit_error_rate_threshold', 'circuit_min_requests',
            'default_cost_multiplier', 'pricing_model_source',
            'created_at', 'updated_at',
        ];

        $columns = $this->getColumnNames('proxy_config');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(20, $columns);

        $keyed = $this->getColumnsKeyed('proxy_config');

        // PK
        $this->assertColumn($keyed, 'app_type', 'TEXT', false, null, true, 'proxy_config');

        // INTEGER NOT NULL with defaults
        $intDefaults = [
            'proxy_enabled' => '0',
            'listen_port' => '15721',
            'enable_logging' => '1',
            'enabled' => '0',
            'auto_failover_enabled' => '0',
            'max_retries' => '3',
            'streaming_first_byte_timeout' => '60',
            'streaming_idle_timeout' => '120',
            'non_streaming_timeout' => '600',
            'circuit_failure_threshold' => '4',
            'circuit_success_threshold' => '2',
            'circuit_timeout_seconds' => '60',
            'circuit_min_requests' => '10',
        ];
        foreach ($intDefaults as $col => $default) {
            $this->assertColumn($keyed, $col, 'INTEGER', true, $default, false, 'proxy_config');
        }

        // TEXT NOT NULL with defaults
        $this->assertColumn($keyed, 'listen_address', 'TEXT', true, '127.0.0.1', false, 'proxy_config');
        $this->assertColumn($keyed, 'default_cost_multiplier', 'TEXT', true, '1', false, 'proxy_config');
        $this->assertColumn($keyed, 'pricing_model_source', 'TEXT', true, 'response', false, 'proxy_config');

        // REAL NOT NULL
        $this->assertColumn($keyed, 'circuit_error_rate_threshold', 'REAL', true, '0.6', false, 'proxy_config');

        // TEXT NOT NULL with expression defaults (created_at, updated_at)
        $this->assertSame('1', (string) $keyed['created_at']['notnull'], 'proxy_config.created_at NOT NULL');
        $this->assertSame('1', (string) $keyed['updated_at']['notnull'], 'proxy_config.updated_at NOT NULL');
    }

    public function testProxyConfigSeedData(): void
    {
        $rows = $this->pdo->query("SELECT app_type, max_retries FROM proxy_config ORDER BY app_type")
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(3, $rows, 'proxy_config should have 3 rows (claude, codex, gemini)');
        $this->assertSame('claude', $rows[0]['app_type']);
        $this->assertSame('6', (string) $rows[0]['max_retries']);
        $this->assertSame('codex', $rows[1]['app_type']);
        $this->assertSame('3', (string) $rows[1]['max_retries']);
        $this->assertSame('gemini', $rows[2]['app_type']);
        $this->assertSame('5', (string) $rows[2]['max_retries']);
    }

    // ─── 10. provider_health ───────────────────────────────────────

    public function testProviderHealthTableSchema(): void
    {
        $expectedColumns = [
            'provider_id', 'app_type', 'is_healthy', 'consecutive_failures',
            'last_success_at', 'last_failure_at', 'last_error', 'updated_at',
        ];

        $columns = $this->getColumnNames('provider_health');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(8, $columns);

        $keyed = $this->getColumnsKeyed('provider_health');

        $this->assertColumn($keyed, 'provider_id', 'TEXT', true, null, true, 'provider_health');
        $this->assertColumn($keyed, 'app_type', 'TEXT', true, null, true, 'provider_health');
        $this->assertColumn($keyed, 'is_healthy', 'INTEGER', true, '1', false, 'provider_health');
        $this->assertColumn($keyed, 'consecutive_failures', 'INTEGER', true, '0', false, 'provider_health');
        $this->assertColumn($keyed, 'last_success_at', 'TEXT', false, null, false, 'provider_health');
        $this->assertColumn($keyed, 'last_failure_at', 'TEXT', false, null, false, 'provider_health');
        $this->assertColumn($keyed, 'last_error', 'TEXT', false, null, false, 'provider_health');
        $this->assertColumn($keyed, 'updated_at', 'TEXT', true, null, false, 'provider_health');
    }

    public function testProviderHealthForeignKeys(): void
    {
        $fks = $this->getForeignKeys('provider_health');
        $this->assertNotEmpty($fks, 'provider_health: should have foreign keys');

        foreach ($fks as $fk) {
            $this->assertSame('providers', $fk['table']);
            $this->assertSame('CASCADE', $fk['on_delete']);
        }
    }

    // ─── 11. proxy_request_logs ────────────────────────────────────

    public function testProxyRequestLogsTableSchema(): void
    {
        $expectedColumns = [
            'request_id', 'provider_id', 'app_type', 'model', 'request_model',
            'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens',
            'input_cost_usd', 'output_cost_usd', 'cache_read_cost_usd',
            'cache_creation_cost_usd', 'total_cost_usd', 'latency_ms',
            'first_token_ms', 'duration_ms', 'status_code', 'error_message',
            'session_id', 'provider_type', 'is_streaming', 'cost_multiplier',
            'created_at',
        ];

        $columns = $this->getColumnNames('proxy_request_logs');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(24, $columns);

        $keyed = $this->getColumnsKeyed('proxy_request_logs');

        // PK
        $this->assertColumn($keyed, 'request_id', 'TEXT', false, null, true, 'proxy_request_logs');

        // NOT NULL without defaults
        $this->assertColumn($keyed, 'provider_id', 'TEXT', true, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'app_type', 'TEXT', true, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'model', 'TEXT', true, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'latency_ms', 'INTEGER', true, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'status_code', 'INTEGER', true, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'created_at', 'INTEGER', true, null, false, 'proxy_request_logs');

        // NOT NULL INTEGER with default 0
        foreach (['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens'] as $col) {
            $this->assertColumn($keyed, $col, 'INTEGER', true, '0', false, 'proxy_request_logs');
        }

        // NOT NULL TEXT with default '0'
        foreach (['input_cost_usd', 'output_cost_usd', 'cache_read_cost_usd', 'cache_creation_cost_usd', 'total_cost_usd'] as $col) {
            $this->assertColumn($keyed, $col, 'TEXT', true, '0', false, 'proxy_request_logs');
        }

        $this->assertColumn($keyed, 'is_streaming', 'INTEGER', true, '0', false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'cost_multiplier', 'TEXT', true, '1.0', false, 'proxy_request_logs');

        // Nullable columns
        $this->assertColumn($keyed, 'request_model', 'TEXT', false, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'first_token_ms', 'INTEGER', false, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'duration_ms', 'INTEGER', false, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'error_message', 'TEXT', false, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'session_id', 'TEXT', false, null, false, 'proxy_request_logs');
        $this->assertColumn($keyed, 'provider_type', 'TEXT', false, null, false, 'proxy_request_logs');
    }

    public function testProxyRequestLogsIndexes(): void
    {
        $indexes = $this->getIndexList('proxy_request_logs');
        $indexNames = array_column($indexes, 'name');

        $expectedIndexes = [
            'idx_request_logs_provider',
            'idx_request_logs_created_at',
            'idx_request_logs_model',
            'idx_request_logs_session',
            'idx_request_logs_status',
        ];

        foreach ($expectedIndexes as $idx) {
            $this->assertContains($idx, $indexNames, "proxy_request_logs: missing index {$idx}");
        }
    }

    // ─── 12. model_pricing ─────────────────────────────────────────

    public function testModelPricingTableSchema(): void
    {
        $expectedColumns = [
            'model_id', 'display_name', 'input_cost_per_million',
            'output_cost_per_million', 'cache_read_cost_per_million',
            'cache_creation_cost_per_million',
        ];

        $columns = $this->getColumnNames('model_pricing');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(6, $columns);

        $keyed = $this->getColumnsKeyed('model_pricing');

        $this->assertColumn($keyed, 'model_id', 'TEXT', false, null, true, 'model_pricing');
        $this->assertColumn($keyed, 'display_name', 'TEXT', true, null, false, 'model_pricing');
        $this->assertColumn($keyed, 'input_cost_per_million', 'TEXT', true, null, false, 'model_pricing');
        $this->assertColumn($keyed, 'output_cost_per_million', 'TEXT', true, null, false, 'model_pricing');
        $this->assertColumn($keyed, 'cache_read_cost_per_million', 'TEXT', true, '0', false, 'model_pricing');
        $this->assertColumn($keyed, 'cache_creation_cost_per_million', 'TEXT', true, '0', false, 'model_pricing');
    }

    public function testModelPricingSeedData(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM model_pricing')->fetchColumn();
        $this->assertGreaterThan(0, $count, 'model_pricing should have seeded pricing data');

        // Verify a known model exists with correct pricing
        $row = $this->pdo->query(
            "SELECT * FROM model_pricing WHERE model_id = 'claude-opus-4-5-20251101'"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'model_pricing: Claude Opus 4.5 should be seeded');
        $this->assertSame('Claude Opus 4.5', $row['display_name']);
        $this->assertSame('5', $row['input_cost_per_million']);
        $this->assertSame('25', $row['output_cost_per_million']);
    }

    // ─── 13. stream_check_logs ─────────────────────────────────────

    public function testStreamCheckLogsTableSchema(): void
    {
        $expectedColumns = [
            'id', 'provider_id', 'provider_name', 'app_type', 'status',
            'success', 'message', 'response_time_ms', 'http_status',
            'model_used', 'retry_count', 'tested_at',
        ];

        $columns = $this->getColumnNames('stream_check_logs');
        $this->assertSame($expectedColumns, $columns);
        $this->assertCount(12, $columns);

        $keyed = $this->getColumnsKeyed('stream_check_logs');

        $this->assertColumn($keyed, 'id', 'INTEGER', false, null, true, 'stream_check_logs');
        $this->assertColumn($keyed, 'provider_id', 'TEXT', true, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'provider_name', 'TEXT', true, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'app_type', 'TEXT', true, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'status', 'TEXT', true, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'success', 'INTEGER', true, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'message', 'TEXT', true, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'response_time_ms', 'INTEGER', false, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'http_status', 'INTEGER', false, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'model_used', 'TEXT', false, null, false, 'stream_check_logs');
        $this->assertColumn($keyed, 'retry_count', 'INTEGER', false, '0', false, 'stream_check_logs');
        $this->assertColumn($keyed, 'tested_at', 'INTEGER', true, null, false, 'stream_check_logs');
    }

    public function testStreamCheckLogsIndexes(): void
    {
        $indexes = $this->getIndexList('stream_check_logs');
        $indexNames = array_column($indexes, 'name');
        $this->assertContains(
            'idx_stream_check_logs_provider',
            $indexNames,
            'stream_check_logs: missing provider index',
        );
    }

    // ─── Cross-table: all expected tables exist ────────────────────

    public function testAllExpectedTablesExist(): void
    {
        $expectedTables = [
            'providers',
            'provider_endpoints',
            'universal_providers',
            'mcp_servers',
            'prompts',
            'skills',
            'skill_repos',
            'settings',
            'proxy_config',
            'provider_health',
            'proxy_request_logs',
            'model_pricing',
            'stream_check_logs',
            'migrations',
        ];

        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expectedTables as $table) {
            $this->assertContains($table, $tables, "Expected table '{$table}' does not exist");
        }
    }

    // ─── Rust schema cross-reference: column counts match ──────────

    public function testColumnCountsMatchRustSchema(): void
    {
        $expectedCounts = [
            'providers' => 14,
            'provider_endpoints' => 5,
            'mcp_servers' => 11,
            'prompts' => 8,
            'skills' => 13,
            'skill_repos' => 4,
            'settings' => 2,
            'proxy_config' => 20,
            'provider_health' => 8,
            'proxy_request_logs' => 24,
            'model_pricing' => 6,
            'stream_check_logs' => 12,
        ];

        foreach ($expectedCounts as $table => $expectedCount) {
            $columns = $this->getColumnNames($table);
            $this->assertCount(
                $expectedCount,
                $columns,
                "Table '{$table}' has " . count($columns) . " columns, expected {$expectedCount}",
            );
        }
    }
}
