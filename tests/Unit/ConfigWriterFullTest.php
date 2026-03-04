<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\ConfigWriter\ClaudeWriter;
use CcSwitch\ConfigWriter\CodexWriter;
use CcSwitch\ConfigWriter\GeminiWriter;
use CcSwitch\ConfigWriter\OpenClawWriter;
use CcSwitch\ConfigWriter\OpenCodeWriter;
use CcSwitch\Model\Provider;
use PHPUnit\Framework\TestCase;

class ConfigWriterFullTest extends TestCase
{
    private string $origHome;
    private string $tempHome;

    protected function setUp(): void
    {
        $this->origHome = getenv('HOME') ?: '';
        $this->tempHome = sys_get_temp_dir() . '/cc-switch-writer-test-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tempHome, 0755, true);
        putenv('HOME=' . $this->tempHome);
        $_SERVER['HOME'] = $this->tempHome;
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->origHome);
        $_SERVER['HOME'] = $this->origHome;
        $this->removeDir($this->tempHome);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeProvider(string $id, string $app, string $settingsConfig): Provider
    {
        return Provider::fromRow([
            'id' => $id,
            'app_type' => $app,
            'name' => 'Test',
            'settings_config' => $settingsConfig,
        ]);
    }

    // ========================================================================
    // CodexWriter
    // ========================================================================

    public function testCodexWriterWriteCreatesFiles(): void
    {
        $writer = new CodexWriter();
        $provider = $this->makeProvider('test', 'codex', json_encode([
            'auth' => ['OPENAI_API_KEY' => 'sk-test'],
            'config' => "model = \"gpt-5\"\n",
        ]));

        $writer->write($provider);

        $authPath = $this->tempHome . '/.codex/auth.json';
        $configPath = $this->tempHome . '/.codex/config.toml';

        $this->assertFileExists($authPath);
        $this->assertFileExists($configPath);

        $auth = json_decode(file_get_contents($authPath), true);
        $this->assertSame('sk-test', $auth['OPENAI_API_KEY']);

        $config = file_get_contents($configPath);
        $this->assertStringContainsString('model = "gpt-5"', $config);
    }

    public function testCodexWriterPreservesMcpServers(): void
    {
        $writer = new CodexWriter();

        // Pre-populate config.toml with mcp_servers section
        $dir = $this->tempHome . '/.codex';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/config.toml', "model = \"old\"\n\n[mcp_servers.myserver]\ncommand = \"node\"\n");

        $provider = $this->makeProvider('test', 'codex', json_encode([
            'auth' => ['OPENAI_API_KEY' => 'sk-new'],
            'config' => "model = \"new\"\n",
        ]));

        $writer->write($provider);

        $config = file_get_contents($dir . '/config.toml');
        $this->assertStringContainsString('[mcp_servers.myserver]', $config);
        $this->assertStringContainsString('model = "new"', $config);
    }

    public function testCodexWriterWriteWithMcpInNewConfig(): void
    {
        $writer = new CodexWriter();

        $dir = $this->tempHome . '/.codex';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/config.toml', "[mcp_servers.old]\ncommand = \"old\"\n");

        $provider = $this->makeProvider('test', 'codex', json_encode([
            'auth' => ['OPENAI_API_KEY' => 'sk-test'],
            'config' => "model = \"new\"\n\n[mcp_servers.new]\ncommand = \"new\"\n",
        ]));

        $writer->write($provider);

        $config = file_get_contents($dir . '/config.toml');
        // New config already has mcp_servers, so old ones should NOT be preserved
        $this->assertStringContainsString('[mcp_servers.new]', $config);
    }

    public function testCodexWriterRemove(): void
    {
        $writer = new CodexWriter();

        $dir = $this->tempHome . '/.codex';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/auth.json', '{}');
        file_put_contents($dir . '/config.toml', 'model = "test"');

        $writer->remove('test');

        $this->assertFileDoesNotExist($dir . '/auth.json');
        $this->assertFileDoesNotExist($dir . '/config.toml');
    }

    public function testCodexWriterRemoveNonExistent(): void
    {
        $writer = new CodexWriter();
        // Should not throw
        $writer->remove('nonexistent');
        $this->assertTrue(true);
    }

    public function testCodexWriterInvalidSettingsConfig(): void
    {
        $writer = new CodexWriter();
        $provider = $this->makeProvider('test', 'codex', 'invalid json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $writer->write($provider);
    }

    public function testCodexWriterMissingAuthField(): void
    {
        $writer = new CodexWriter();
        $provider = $this->makeProvider('test', 'codex', json_encode([
            'config' => "model = \"test\"\n",
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'auth'");
        $writer->write($provider);
    }

    public function testCodexWriterGetPaths(): void
    {
        $writer = new CodexWriter();
        $this->assertStringEndsWith('/.codex', $writer->getConfigDir());
        $this->assertStringEndsWith('/auth.json', $writer->getAuthPath());
        $this->assertStringEndsWith('/config.toml', $writer->getConfigPath());
    }

    // ========================================================================
    // OpenClawWriter
    // ========================================================================

    public function testOpenClawWriterWrite(): void
    {
        $writer = new OpenClawWriter();
        $provider = $this->makeProvider('my-provider', 'openclaw', json_encode([
            'baseUrl' => 'https://api.example.com',
            'apiKey' => 'sk-test',
        ]));

        $writer->write($provider);

        $config = OpenClawWriter::readConfig();
        $this->assertArrayHasKey('models', $config);
        $this->assertArrayHasKey('providers', $config['models']);
        $this->assertArrayHasKey('my-provider', $config['models']['providers']);
    }

    public function testOpenClawWriterWriteAll(): void
    {
        $writer = new OpenClawWriter();
        $providers = [
            $this->makeProvider('p1', 'openclaw', json_encode(['baseUrl' => 'https://a.com'])),
            $this->makeProvider('p2', 'openclaw', json_encode(['baseUrl' => 'https://b.com'])),
        ];

        $writer->writeAll($providers);

        $config = OpenClawWriter::readConfig();
        $this->assertCount(2, $config['models']['providers']);
        $this->assertArrayHasKey('p1', $config['models']['providers']);
        $this->assertArrayHasKey('p2', $config['models']['providers']);
    }

    public function testOpenClawWriterWriteAllWithInvalidConfig(): void
    {
        $writer = new OpenClawWriter();
        $providers = [
            $this->makeProvider('p1', 'openclaw', 'invalid'),
            $this->makeProvider('p2', 'openclaw', json_encode(['baseUrl' => 'https://b.com'])),
        ];

        $writer->writeAll($providers);

        $config = OpenClawWriter::readConfig();
        // p1 with invalid config should be skipped
        $this->assertCount(1, $config['models']['providers']);
        $this->assertArrayHasKey('p2', $config['models']['providers']);
    }

    public function testOpenClawWriterRemove(): void
    {
        $writer = new OpenClawWriter();
        $provider = $this->makeProvider('to-remove', 'openclaw', json_encode(['key' => 'val']));
        $writer->write($provider);

        $writer->remove('to-remove');

        $config = OpenClawWriter::readConfig();
        $this->assertArrayNotHasKey('to-remove', $config['models']['providers'] ?? []);
    }

    public function testOpenClawWriterRemoveNonExistent(): void
    {
        $writer = new OpenClawWriter();
        // Should not throw
        $writer->remove('nonexistent');
        $this->assertTrue(true);
    }

    public function testOpenClawWriterWriteWithInvalidSettingsConfig(): void
    {
        $writer = new OpenClawWriter();
        $provider = $this->makeProvider('test', 'openclaw', 'not-json');

        $writer->write($provider);

        $config = OpenClawWriter::readConfig();
        // Should store empty array for invalid config
        $this->assertSame([], $config['models']['providers']['test']);
    }

    public function testOpenClawWriterReadConfigMissingFile(): void
    {
        $this->assertSame([], OpenClawWriter::readConfig());
    }

    // ========================================================================
    // OpenCodeWriter
    // ========================================================================

    public function testOpenCodeWriterWrite(): void
    {
        $writer = new OpenCodeWriter();
        $provider = $this->makeProvider('my-provider', 'opencode', json_encode([
            'baseURL' => 'https://api.example.com',
            'apiKey' => 'sk-test',
        ]));

        $writer->write($provider);

        $config = OpenCodeWriter::readConfig();
        $this->assertArrayHasKey('provider', $config);
        $this->assertArrayHasKey('my-provider', $config['provider']);
    }

    public function testOpenCodeWriterWriteAll(): void
    {
        $writer = new OpenCodeWriter();
        $providers = [
            $this->makeProvider('p1', 'opencode', json_encode(['baseURL' => 'https://a.com'])),
            $this->makeProvider('p2', 'opencode', json_encode(['baseURL' => 'https://b.com'])),
        ];

        $writer->writeAll($providers);

        $config = OpenCodeWriter::readConfig();
        $this->assertCount(2, $config['provider']);
    }

    public function testOpenCodeWriterRemove(): void
    {
        $writer = new OpenCodeWriter();
        $provider = $this->makeProvider('to-remove', 'opencode', json_encode(['key' => 'val']));
        $writer->write($provider);

        $writer->remove('to-remove');

        $config = OpenCodeWriter::readConfig();
        $this->assertArrayNotHasKey('to-remove', $config['provider'] ?? []);
    }

    public function testOpenCodeWriterRemoveNonExistent(): void
    {
        $writer = new OpenCodeWriter();
        $writer->remove('nonexistent');
        $this->assertTrue(true);
    }

    public function testOpenCodeWriterReadConfigDefault(): void
    {
        $config = OpenCodeWriter::readConfig();
        $this->assertArrayHasKey('$schema', $config);
    }

    public function testOpenCodeWriterWriteWithInvalidConfig(): void
    {
        $writer = new OpenCodeWriter();
        $provider = $this->makeProvider('test', 'opencode', 'invalid');

        $writer->write($provider);

        $config = OpenCodeWriter::readConfig();
        $this->assertSame([], $config['provider']['test']);
    }

    public function testOpenCodeWriterWriteAllSkipsInvalidConfig(): void
    {
        $writer = new OpenCodeWriter();
        $providers = [
            $this->makeProvider('p1', 'opencode', 'invalid'),
            $this->makeProvider('p2', 'opencode', json_encode(['key' => 'val'])),
        ];

        $writer->writeAll($providers);

        $config = OpenCodeWriter::readConfig();
        $this->assertCount(1, $config['provider']);
    }

    // ========================================================================
    // GeminiWriter
    // ========================================================================

    public function testGeminiWriterWrite(): void
    {
        $writer = new GeminiWriter();
        $provider = $this->makeProvider('test', 'gemini', json_encode([
            'env' => [
                'GEMINI_API_KEY' => 'test-key',
                'GEMINI_MODEL' => 'gemini-2.5-pro',
            ],
        ]));

        $writer->write($provider);

        $envPath = $writer->getEnvPath();
        $this->assertFileExists($envPath);
        $content = file_get_contents($envPath);
        $this->assertStringContainsString('GEMINI_API_KEY=test-key', $content);
        $this->assertStringContainsString('GEMINI_MODEL=gemini-2.5-pro', $content);

        // Verify auth type was set
        $settingsPath = $writer->getSettingsPath();
        $this->assertFileExists($settingsPath);
        $settings = json_decode(file_get_contents($settingsPath), true);
        $this->assertSame('gemini-api-key', $settings['security']['auth']['selectedType']);
    }

    public function testGeminiWriterWriteWithConfig(): void
    {
        $writer = new GeminiWriter();
        $provider = $this->makeProvider('test', 'gemini', json_encode([
            'env' => ['GEMINI_API_KEY' => 'test-key'],
            'config' => ['theme' => 'dark'],
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertSame('dark', $settings['theme']);
    }

    public function testGeminiWriterWriteMergesExistingSettings(): void
    {
        $writer = new GeminiWriter();

        // Pre-populate settings
        $dir = dirname($writer->getSettingsPath());
        mkdir($dir, 0755, true);
        file_put_contents($writer->getSettingsPath(), json_encode([
            'existingKey' => 'preserved',
            'mcpServers' => ['server1' => []],
        ]));

        $provider = $this->makeProvider('test', 'gemini', json_encode([
            'env' => ['GEMINI_API_KEY' => 'test-key'],
            'config' => ['newKey' => 'added'],
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertSame('preserved', $settings['existingKey']);
        $this->assertSame('added', $settings['newKey']);
    }

    public function testGeminiWriterWriteWithoutApiKey(): void
    {
        $writer = new GeminiWriter();
        $provider = $this->makeProvider('test', 'gemini', json_encode([
            'env' => ['GEMINI_MODEL' => 'gemini-pro'],
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertSame('oauth-personal', $settings['security']['auth']['selectedType']);
    }

    public function testGeminiWriterRemove(): void
    {
        $writer = new GeminiWriter();

        // Create env file first
        $dir = dirname($writer->getEnvPath());
        mkdir($dir, 0755, true);
        file_put_contents($writer->getEnvPath(), 'GEMINI_API_KEY=test');

        $writer->remove('test');

        $this->assertFileDoesNotExist($writer->getEnvPath());
    }

    public function testGeminiWriterRemoveNonExistent(): void
    {
        $writer = new GeminiWriter();
        $writer->remove('test');
        $this->assertTrue(true);
    }

    public function testGeminiParseEnv(): void
    {
        $content = "GEMINI_API_KEY=test-key\nGEMINI_MODEL=gemini-pro\n# comment\n\nINVALID LINE\n=bad\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertSame('test-key', $result['GEMINI_API_KEY']);
        $this->assertSame('gemini-pro', $result['GEMINI_MODEL']);
        $this->assertCount(2, $result);
    }

    public function testGeminiSerializeEnv(): void
    {
        $result = GeminiWriter::serializeEnv([
            'B_KEY' => 'value2',
            'A_KEY' => 'value1',
        ]);

        // Keys should be sorted
        $this->assertSame("A_KEY=value1\nB_KEY=value2", $result);
    }

    public function testGeminiWriterWriteWithEmptyEnv(): void
    {
        $writer = new GeminiWriter();
        $provider = $this->makeProvider('test', 'gemini', json_encode([
            'env' => [],
        ]));

        $writer->write($provider);

        $this->assertFileExists($writer->getEnvPath());
    }

    public function testGeminiWriterWriteWithInvalidSettings(): void
    {
        $writer = new GeminiWriter();
        $provider = $this->makeProvider('test', 'gemini', 'not json');

        // Should write empty env
        $writer->write($provider);
        $this->assertFileExists($writer->getEnvPath());
    }

    // ========================================================================
    // ClaudeWriter
    // ========================================================================

    public function testClaudeWriterWrite(): void
    {
        $writer = new ClaudeWriter();
        $provider = $this->makeProvider('test', 'claude', json_encode([
            'env' => [
                'ANTHROPIC_AUTH_TOKEN' => 'sk-test',
                'ANTHROPIC_BASE_URL' => 'https://api.anthropic.com',
            ],
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertSame('sk-test', $settings['env']['ANTHROPIC_AUTH_TOKEN']);
    }

    public function testClaudeWriterStripsInternalFields(): void
    {
        $writer = new ClaudeWriter();
        $provider = $this->makeProvider('test', 'claude', json_encode([
            'env' => ['ANTHROPIC_AUTH_TOKEN' => 'sk-test'],
            'api_format' => 'anthropic',
            'apiFormat' => 'anthropic',
            'openrouter_compat_mode' => true,
            'openrouterCompatMode' => true,
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertArrayNotHasKey('api_format', $settings);
        $this->assertArrayNotHasKey('apiFormat', $settings);
        $this->assertArrayNotHasKey('openrouter_compat_mode', $settings);
        $this->assertArrayNotHasKey('openrouterCompatMode', $settings);
    }

    public function testClaudeWriterPreservesExistingSettings(): void
    {
        $writer = new ClaudeWriter();

        // Pre-populate settings
        $dir = dirname($writer->getSettingsPath());
        mkdir($dir, 0755, true);
        file_put_contents($writer->getSettingsPath(), json_encode([
            'env' => [
                'EXISTING_KEY' => 'preserved',
            ],
            'permissions' => ['allow' => ['*']],
        ]));

        $provider = $this->makeProvider('test', 'claude', json_encode([
            'env' => ['ANTHROPIC_AUTH_TOKEN' => 'sk-new'],
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertSame('preserved', $settings['env']['EXISTING_KEY']);
        $this->assertSame('sk-new', $settings['env']['ANTHROPIC_AUTH_TOKEN']);
        $this->assertSame(['allow' => ['*']], $settings['permissions']);
    }

    public function testClaudeWriterMergesTopLevelKeys(): void
    {
        $writer = new ClaudeWriter();
        $provider = $this->makeProvider('test', 'claude', json_encode([
            'env' => ['ANTHROPIC_AUTH_TOKEN' => 'sk-test'],
            'model' => 'claude-opus-4-6-20260206',
        ]));

        $writer->write($provider);

        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertSame('claude-opus-4-6-20260206', $settings['model']);
    }

    public function testClaudeWriterRemove(): void
    {
        $writer = new ClaudeWriter();

        $dir = dirname($writer->getSettingsPath());
        mkdir($dir, 0755, true);
        file_put_contents($writer->getSettingsPath(), '{}');

        $writer->remove('test');

        $this->assertFileDoesNotExist($writer->getSettingsPath());
    }

    public function testClaudeWriterRemoveNonExistent(): void
    {
        $writer = new ClaudeWriter();
        $writer->remove('test');
        $this->assertTrue(true);
    }

    public function testClaudeWriterLegacyPath(): void
    {
        $writer = new ClaudeWriter();

        // Create legacy claude.json instead of settings.json
        $dir = $this->tempHome . '/.claude';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/claude.json', '{}');

        $this->assertStringEndsWith('/claude.json', $writer->getSettingsPath());
    }

    public function testClaudeWriterWriteWithInvalidConfig(): void
    {
        $writer = new ClaudeWriter();
        $provider = $this->makeProvider('test', 'claude', 'not json');

        $writer->write($provider);

        // Should create settings.json with empty content
        $settings = json_decode(file_get_contents($writer->getSettingsPath()), true);
        $this->assertIsArray($settings);
    }
}
