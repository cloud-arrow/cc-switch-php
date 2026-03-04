<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\ConfigWriter\ClaudeWriter;
use CcSwitch\ConfigWriter\CodexWriter;
use CcSwitch\ConfigWriter\GeminiWriter;
use CcSwitch\ConfigWriter\OpenClawWriter;
use CcSwitch\ConfigWriter\OpenCodeWriter;
use CcSwitch\Model\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot tests for ConfigWriter implementations.
 *
 * Each test loads a JSON fixture describing input (provider + optional pre-existing files)
 * and expected output, then executes the writer and compares results.
 */
class ConfigWriterSnapshotTest extends TestCase
{
    private string $origHome;
    private string $tempHome;

    protected function setUp(): void
    {
        $this->origHome = getenv('HOME') ?: '';
        $this->tempHome = sys_get_temp_dir() . '/cc-switch-snapshot-' . getmypid() . '-' . hrtime(true);
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

    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../fixtures/config-snapshots/' . $name;
        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Failed to read fixture: {$name}");
        $data = json_decode($content, true);
        $this->assertIsArray($data, "Fixture is not valid JSON: {$name}");
        return $data;
    }

    /**
     * Resolve a path like "~/.claude/settings.json" to the temp home directory.
     */
    private function resolvePath(string $tilded): string
    {
        return str_replace('~', $this->tempHome, $tilded);
    }

    /**
     * Write pre-existing files from fixture input.
     */
    private function writePreExisting(array $preExisting): void
    {
        foreach ($preExisting as $tilded => $content) {
            $path = $this->resolvePath($tilded);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (is_array($content)) {
                file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            } else {
                file_put_contents($path, $content);
            }
        }
    }

    private function makeProvider(array $data): Provider
    {
        return Provider::fromRow([
            'id' => $data['id'],
            'app_type' => $data['app_type'],
            'name' => $data['name'],
            'settings_config' => $data['settings_config'],
        ]);
    }

    private function getWriter(string $appType): object
    {
        return match ($appType) {
            'claude' => new ClaudeWriter(),
            'codex' => new CodexWriter(),
            'gemini' => new GeminiWriter(),
            'opencode' => new OpenCodeWriter(),
            'openclaw' => new OpenClawWriter(),
            default => throw new \InvalidArgumentException("Unknown app_type: {$appType}"),
        };
    }

    /**
     * Compare two arrays recursively (order-independent for associative arrays).
     */
    private function assertJsonEquals(array $expected, array $actual, string $context = ''): void
    {
        foreach ($expected as $key => $value) {
            $path = $context ? "{$context}.{$key}" : (string) $key;
            $this->assertArrayHasKey($key, $actual, "Missing key '{$path}' in output");
            if (is_array($value) && is_array($actual[$key])) {
                $this->assertJsonEquals($value, $actual[$key], $path);
            } else {
                $this->assertSame($value, $actual[$key], "Value mismatch at '{$path}'");
            }
        }
        // Also check no unexpected keys
        foreach ($actual as $key => $value) {
            $path = $context ? "{$context}.{$key}" : (string) $key;
            $this->assertArrayHasKey($key, $expected, "Unexpected key '{$path}' in output");
        }
    }

    // ========================================================================
    // Claude Snapshot Tests
    // ========================================================================

    public function testClaudeStandard(): void
    {
        $fixture = $this->loadFixture('claude-standard.json');
        $provider = $this->makeProvider($fixture['input']['provider']);

        $writer = new ClaudeWriter();
        $writer->write($provider);

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            $path = $this->resolvePath($tilded);
            $this->assertFileExists($path, "Expected file missing: {$tilded}");
            $actual = json_decode(file_get_contents($path), true);
            $this->assertIsArray($actual);
            $this->assertJsonEquals($expectedContent, $actual, $tilded);
        }
    }

    public function testClaudeWithMcp(): void
    {
        $fixture = $this->loadFixture('claude-with-mcp.json');

        // Write pre-existing files
        $this->writePreExisting($fixture['input']['preExisting']);

        $provider = $this->makeProvider($fixture['input']['provider']);
        $writer = new ClaudeWriter();
        $writer->write($provider);

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            $path = $this->resolvePath($tilded);
            $this->assertFileExists($path, "Expected file missing: {$tilded}");
            $actual = json_decode(file_get_contents($path), true);
            $this->assertIsArray($actual);
            $this->assertJsonEquals($expectedContent, $actual, $tilded);
        }
    }

    // ========================================================================
    // Codex Snapshot Tests
    // ========================================================================

    public function testCodexStandard(): void
    {
        $fixture = $this->loadFixture('codex-standard.json');
        $provider = $this->makeProvider($fixture['input']['provider']);

        $writer = new CodexWriter();
        $writer->write($provider);

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            $path = $this->resolvePath($tilded);
            $this->assertFileExists($path, "Expected file missing: {$tilded}");

            if (is_array($expectedContent)) {
                // JSON comparison
                $actual = json_decode(file_get_contents($path), true);
                $this->assertIsArray($actual);
                $this->assertJsonEquals($expectedContent, $actual, $tilded);
            } else {
                // String comparison (TOML)
                $actual = file_get_contents($path);
                $this->assertSame($expectedContent, $actual, "Content mismatch for {$tilded}");
            }
        }
    }

    public function testCodexWithToml(): void
    {
        $fixture = $this->loadFixture('codex-with-toml.json');

        // Write pre-existing files
        if (isset($fixture['input']['preExisting'])) {
            $this->writePreExisting($fixture['input']['preExisting']);
        }

        $provider = $this->makeProvider($fixture['input']['provider']);
        $writer = new CodexWriter();
        $writer->write($provider);

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            if (str_ends_with($tilded, '_contains')) {
                // String contains assertions for TOML
                $realPath = str_replace('_contains', '', $tilded);
                $path = $this->resolvePath($realPath);
                $this->assertFileExists($path, "Expected file missing: {$realPath}");
                $actual = file_get_contents($path);
                foreach ($expectedContent as $needle) {
                    $this->assertStringContainsString($needle, $actual, "TOML missing expected content: {$needle}");
                }
            } elseif (is_array($expectedContent)) {
                $path = $this->resolvePath($tilded);
                $this->assertFileExists($path, "Expected file missing: {$tilded}");
                $actual = json_decode(file_get_contents($path), true);
                $this->assertIsArray($actual);
                $this->assertJsonEquals($expectedContent, $actual, $tilded);
            }
        }
    }

    // ========================================================================
    // Gemini Snapshot Tests
    // ========================================================================

    public function testGeminiEnv(): void
    {
        $fixture = $this->loadFixture('gemini-env.json');
        $provider = $this->makeProvider($fixture['input']['provider']);

        $writer = new GeminiWriter();
        $writer->write($provider);

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            if (str_ends_with($tilded, '_permissions')) {
                // Check file permissions
                $realPath = str_replace('_permissions', '', $tilded);
                $path = $this->resolvePath($realPath);
                $this->assertFileExists($path);
                $perms = fileperms($path) & 0777;
                $this->assertSame(octdec($expectedContent), $perms, "Permission mismatch for {$realPath}");
            } elseif (is_array($expectedContent)) {
                $path = $this->resolvePath($tilded);
                $this->assertFileExists($path, "Expected file missing: {$tilded}");
                $actual = json_decode(file_get_contents($path), true);
                $this->assertIsArray($actual);
                $this->assertJsonEquals($expectedContent, $actual, $tilded);
            } else {
                // .env string comparison
                $path = $this->resolvePath($tilded);
                $this->assertFileExists($path, "Expected file missing: {$tilded}");
                $actual = file_get_contents($path);
                $this->assertSame($expectedContent, $actual, "Content mismatch for {$tilded}");
            }
        }
    }

    public function testGeminiOauth(): void
    {
        $fixture = $this->loadFixture('gemini-oauth.json');
        $provider = $this->makeProvider($fixture['input']['provider']);

        $writer = new GeminiWriter();
        $writer->write($provider);

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            if (str_ends_with($tilded, '_permissions')) {
                $realPath = str_replace('_permissions', '', $tilded);
                $path = $this->resolvePath($realPath);
                $this->assertFileExists($path);
                $perms = fileperms($path) & 0777;
                $this->assertSame(octdec($expectedContent), $perms, "Permission mismatch for {$realPath}");
            } elseif (is_array($expectedContent)) {
                $path = $this->resolvePath($tilded);
                $this->assertFileExists($path, "Expected file missing: {$tilded}");
                $actual = json_decode(file_get_contents($path), true);
                $this->assertIsArray($actual);
                $this->assertJsonEquals($expectedContent, $actual, $tilded);
            } else {
                $path = $this->resolvePath($tilded);
                $this->assertFileExists($path, "Expected file missing: {$tilded}");
                $actual = file_get_contents($path);
                $this->assertSame($expectedContent, $actual, "Content mismatch for {$tilded}");
            }
        }
    }

    // ========================================================================
    // OpenCode Snapshot Tests
    // ========================================================================

    public function testOpenCodeAdditive(): void
    {
        $fixture = $this->loadFixture('opencode-additive.json');
        $writer = new OpenCodeWriter();

        $providers = [];
        foreach ($fixture['input']['providers'] as $pData) {
            $providers[] = $this->makeProvider($pData);
        }

        // Write each provider individually (additive)
        foreach ($providers as $provider) {
            $writer->write($provider);
        }

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            $path = $this->resolvePath($tilded);
            $this->assertFileExists($path, "Expected file missing: {$tilded}");
            $actual = json_decode(file_get_contents($path), true);
            $this->assertIsArray($actual);
            $this->assertJsonEquals($expectedContent, $actual, $tilded);
        }
    }

    // ========================================================================
    // OpenClaw Snapshot Tests
    // ========================================================================

    public function testOpenClawAdditive(): void
    {
        $fixture = $this->loadFixture('openclaw-additive.json');
        $writer = new OpenClawWriter();

        $providers = [];
        foreach ($fixture['input']['providers'] as $pData) {
            $providers[] = $this->makeProvider($pData);
        }

        // Write each provider individually (additive)
        foreach ($providers as $provider) {
            $writer->write($provider);
        }

        foreach ($fixture['expected'] as $tilded => $expectedContent) {
            $path = $this->resolvePath($tilded);
            $this->assertFileExists($path, "Expected file missing: {$tilded}");
            $actual = json_decode(file_get_contents($path), true);
            $this->assertIsArray($actual);
            $this->assertJsonEquals($expectedContent, $actual, $tilded);
        }
    }
}
