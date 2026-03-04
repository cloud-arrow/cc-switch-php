<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Model\McpServer;
use CcSwitch\Model\Prompt;
use CcSwitch\Model\ProviderHealth;
use CcSwitch\Model\RequestLog;
use CcSwitch\Model\Settings;
use CcSwitch\Model\Skill;
use CcSwitch\Model\UniversalProvider;
use PHPUnit\Framework\TestCase;

class ModelFromRowTest extends TestCase
{
    public function testMcpServerFromRow(): void
    {
        $row = [
            'id' => 'mcp-1',
            'name' => 'Test MCP',
            'server_config' => '{"command":"node"}',
            'description' => 'A test server',
            'homepage' => 'https://example.com',
            'docs' => 'https://docs.example.com',
            'tags' => '["tag1","tag2"]',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 1,
            'enabled_opencode' => 0,
        ];

        $model = McpServer::fromRow($row);

        $this->assertSame('mcp-1', $model->id);
        $this->assertSame('Test MCP', $model->name);
        $this->assertSame('{"command":"node"}', $model->server_config);
        $this->assertSame('A test server', $model->description);
        $this->assertSame('https://example.com', $model->homepage);
        $this->assertSame('https://docs.example.com', $model->docs);
        $this->assertSame('["tag1","tag2"]', $model->tags);
        $this->assertSame(1, $model->enabled_claude);
        $this->assertSame(0, $model->enabled_codex);
        $this->assertSame(1, $model->enabled_gemini);
        $this->assertSame(0, $model->enabled_opencode);
    }

    public function testMcpServerFromRowDefaults(): void
    {
        $model = McpServer::fromRow([]);

        $this->assertSame('', $model->id);
        $this->assertSame('', $model->name);
        $this->assertSame('', $model->server_config);
        $this->assertNull($model->description);
        $this->assertNull($model->homepage);
        $this->assertNull($model->docs);
        $this->assertSame('[]', $model->tags);
        $this->assertSame(0, $model->enabled_claude);
        $this->assertSame(0, $model->enabled_codex);
        $this->assertSame(0, $model->enabled_gemini);
        $this->assertSame(0, $model->enabled_opencode);
    }

    public function testPromptFromRow(): void
    {
        $row = [
            'id' => 'prompt-1',
            'app_type' => 'claude',
            'name' => 'My Prompt',
            'content' => 'Hello world',
            'description' => 'A test prompt',
            'enabled' => 1,
            'created_at' => 1700000000,
            'updated_at' => 1700001000,
        ];

        $model = Prompt::fromRow($row);

        $this->assertSame('prompt-1', $model->id);
        $this->assertSame('claude', $model->app_type);
        $this->assertSame('My Prompt', $model->name);
        $this->assertSame('Hello world', $model->content);
        $this->assertSame('A test prompt', $model->description);
        $this->assertSame(1, $model->enabled);
        $this->assertSame(1700000000, $model->created_at);
        $this->assertSame(1700001000, $model->updated_at);
    }

    public function testPromptFromRowDefaults(): void
    {
        $model = Prompt::fromRow([]);

        $this->assertSame('', $model->id);
        $this->assertSame('', $model->app_type);
        $this->assertSame('', $model->name);
        $this->assertSame('', $model->content);
        $this->assertNull($model->description);
        $this->assertSame(1, $model->enabled);
        $this->assertNull($model->created_at);
        $this->assertNull($model->updated_at);
    }

    public function testProviderHealthFromRow(): void
    {
        $row = [
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'is_healthy' => 0,
            'consecutive_failures' => 3,
            'last_success_at' => '2024-01-01T00:00:00Z',
            'last_failure_at' => '2024-01-02T00:00:00Z',
            'last_error' => 'timeout',
            'updated_at' => '2024-01-02T00:00:00Z',
        ];

        $model = ProviderHealth::fromRow($row);

        $this->assertSame('p1', $model->provider_id);
        $this->assertSame('claude', $model->app_type);
        $this->assertSame(0, $model->is_healthy);
        $this->assertSame(3, $model->consecutive_failures);
        $this->assertSame('2024-01-01T00:00:00Z', $model->last_success_at);
        $this->assertSame('2024-01-02T00:00:00Z', $model->last_failure_at);
        $this->assertSame('timeout', $model->last_error);
        $this->assertSame('2024-01-02T00:00:00Z', $model->updated_at);
    }

    public function testProviderHealthFromRowDefaults(): void
    {
        $model = ProviderHealth::fromRow([]);

        $this->assertSame('', $model->provider_id);
        $this->assertSame('', $model->app_type);
        $this->assertSame(1, $model->is_healthy);
        $this->assertSame(0, $model->consecutive_failures);
        $this->assertNull($model->last_success_at);
        $this->assertNull($model->last_failure_at);
        $this->assertNull($model->last_error);
        $this->assertSame('', $model->updated_at);
    }

    public function testProviderHealthCircuitState(): void
    {
        // Healthy = closed
        $healthy = ProviderHealth::fromRow(['is_healthy' => 1]);
        $this->assertSame('closed', $healthy->circuitState());

        // Unhealthy with failures and past success = half-open
        $halfOpen = ProviderHealth::fromRow([
            'is_healthy' => 0,
            'consecutive_failures' => 2,
            'last_success_at' => '2024-01-01',
        ]);
        $this->assertSame('half-open', $halfOpen->circuitState());

        // Unhealthy without success = open
        $open = ProviderHealth::fromRow([
            'is_healthy' => 0,
            'consecutive_failures' => 0,
        ]);
        $this->assertSame('open', $open->circuitState());
    }

    public function testRequestLogFromRow(): void
    {
        $row = [
            'request_id' => 'req-1',
            'provider_id' => 'p1',
            'app_type' => 'claude',
            'model' => 'claude-3-opus',
            'request_model' => 'claude-3-opus',
            'input_tokens' => 100,
            'output_tokens' => 200,
            'cache_read_tokens' => 50,
            'cache_creation_tokens' => 25,
            'input_cost_usd' => '0.001',
            'output_cost_usd' => '0.002',
            'cache_read_cost_usd' => '0.0005',
            'cache_creation_cost_usd' => '0.0003',
            'total_cost_usd' => '0.0038',
            'latency_ms' => 500,
            'first_token_ms' => 100,
            'duration_ms' => 450,
            'status_code' => 200,
            'error_message' => null,
            'session_id' => 'sess-1',
            'provider_type' => 'anthropic',
            'is_streaming' => 1,
            'cost_multiplier' => '1.5',
            'created_at' => 1700000000,
        ];

        $model = RequestLog::fromRow($row);

        $this->assertSame('req-1', $model->request_id);
        $this->assertSame('p1', $model->provider_id);
        $this->assertSame('claude', $model->app_type);
        $this->assertSame('claude-3-opus', $model->model);
        $this->assertSame('claude-3-opus', $model->request_model);
        $this->assertSame(100, $model->input_tokens);
        $this->assertSame(200, $model->output_tokens);
        $this->assertSame(50, $model->cache_read_tokens);
        $this->assertSame(25, $model->cache_creation_tokens);
        $this->assertSame('0.001', $model->input_cost_usd);
        $this->assertSame('0.002', $model->output_cost_usd);
        $this->assertSame('0.0005', $model->cache_read_cost_usd);
        $this->assertSame('0.0003', $model->cache_creation_cost_usd);
        $this->assertSame('0.0038', $model->total_cost_usd);
        $this->assertSame(500, $model->latency_ms);
        $this->assertSame(100, $model->first_token_ms);
        $this->assertSame(450, $model->duration_ms);
        $this->assertSame(200, $model->status_code);
        $this->assertNull($model->error_message);
        $this->assertSame('sess-1', $model->session_id);
        $this->assertSame('anthropic', $model->provider_type);
        $this->assertSame(1, $model->is_streaming);
        $this->assertSame('1.5', $model->cost_multiplier);
        $this->assertSame(1700000000, $model->created_at);
    }

    public function testRequestLogFromRowDefaults(): void
    {
        $model = RequestLog::fromRow([]);

        $this->assertSame('', $model->request_id);
        $this->assertSame('', $model->provider_id);
        $this->assertSame(0, $model->input_tokens);
        $this->assertSame(0, $model->output_tokens);
        $this->assertSame('0', $model->input_cost_usd);
        $this->assertSame('0', $model->total_cost_usd);
        $this->assertNull($model->first_token_ms);
        $this->assertNull($model->duration_ms);
        $this->assertNull($model->error_message);
        $this->assertNull($model->session_id);
        $this->assertNull($model->provider_type);
        $this->assertSame('1.0', $model->cost_multiplier);
    }

    public function testSettingsFromArray(): void
    {
        $data = [
            'theme' => 'dark',
            'language' => 'en',
            'auto_backup' => 'true',
            'backup_dir' => '/tmp/backups',
            'proxy_port' => '8080',
            'web_port' => '3000',
        ];

        $settings = Settings::fromArray($data);

        $this->assertSame('dark', $settings->theme);
        $this->assertSame('en', $settings->language);
        $this->assertSame('true', $settings->autoBackup);
        $this->assertSame('/tmp/backups', $settings->backupDir);
        $this->assertSame('8080', $settings->proxyPort);
        $this->assertSame('3000', $settings->webPort);
    }

    public function testSettingsFromArrayDefaults(): void
    {
        $settings = Settings::fromArray([]);

        $this->assertNull($settings->theme);
        $this->assertNull($settings->language);
        $this->assertNull($settings->autoBackup);
        $this->assertNull($settings->backupDir);
        $this->assertNull($settings->proxyPort);
        $this->assertNull($settings->webPort);
    }

    public function testSkillFromRow(): void
    {
        $row = [
            'id' => 'skill-1',
            'name' => 'Test Skill',
            'description' => 'A test skill',
            'directory' => '/skills/test',
            'repo_owner' => 'owner',
            'repo_name' => 'repo',
            'repo_branch' => 'develop',
            'readme_url' => 'https://example.com/readme',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 1,
            'enabled_opencode' => 0,
            'installed_at' => 1700000000,
        ];

        $model = Skill::fromRow($row);

        $this->assertSame('skill-1', $model->id);
        $this->assertSame('Test Skill', $model->name);
        $this->assertSame('A test skill', $model->description);
        $this->assertSame('/skills/test', $model->directory);
        $this->assertSame('owner', $model->repo_owner);
        $this->assertSame('repo', $model->repo_name);
        $this->assertSame('develop', $model->repo_branch);
        $this->assertSame('https://example.com/readme', $model->readme_url);
        $this->assertSame(1, $model->enabled_claude);
        $this->assertSame(0, $model->enabled_codex);
        $this->assertSame(1, $model->enabled_gemini);
        $this->assertSame(0, $model->enabled_opencode);
        $this->assertSame(1700000000, $model->installed_at);
    }

    public function testSkillFromRowDefaults(): void
    {
        $model = Skill::fromRow([]);

        $this->assertSame('', $model->id);
        $this->assertSame('', $model->name);
        $this->assertNull($model->description);
        $this->assertSame('', $model->directory);
        $this->assertNull($model->repo_owner);
        $this->assertNull($model->repo_name);
        $this->assertSame('main', $model->repo_branch);
        $this->assertNull($model->readme_url);
        $this->assertSame(0, $model->enabled_claude);
        $this->assertSame(0, $model->enabled_codex);
        $this->assertSame(0, $model->enabled_gemini);
        $this->assertSame(0, $model->enabled_opencode);
        $this->assertSame(0, $model->installed_at);
    }

    public function testUniversalProviderFromRow(): void
    {
        $row = [
            'id' => 'up-1',
            'name' => 'Test UP',
            'provider_type' => 'openai',
            'apps' => '{"claude":true}',
            'base_url' => 'https://api.example.com',
            'api_key' => 'sk-test',
            'models' => '{"gpt-4":true}',
            'website_url' => 'https://example.com',
            'notes' => 'Test notes',
            'created_at' => 1700000000,
        ];

        $model = UniversalProvider::fromRow($row);

        $this->assertSame('up-1', $model->id);
        $this->assertSame('Test UP', $model->name);
        $this->assertSame('openai', $model->provider_type);
        $this->assertSame('{"claude":true}', $model->apps);
        $this->assertSame('https://api.example.com', $model->base_url);
        $this->assertSame('sk-test', $model->api_key);
        $this->assertSame('{"gpt-4":true}', $model->models);
        $this->assertSame('https://example.com', $model->website_url);
        $this->assertSame('Test notes', $model->notes);
        $this->assertSame(1700000000, $model->created_at);
    }

    public function testUniversalProviderFromRowDefaults(): void
    {
        $model = UniversalProvider::fromRow([]);

        $this->assertSame('', $model->id);
        $this->assertSame('', $model->name);
        $this->assertSame('', $model->provider_type);
        $this->assertSame('{}', $model->apps);
        $this->assertSame('', $model->base_url);
        $this->assertSame('', $model->api_key);
        $this->assertSame('{}', $model->models);
        $this->assertNull($model->website_url);
        $this->assertNull($model->notes);
        $this->assertNull($model->created_at);
    }
}
