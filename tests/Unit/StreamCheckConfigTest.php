<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Model\StreamCheckConfig;
use PHPUnit\Framework\TestCase;

class StreamCheckConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new StreamCheckConfig();

        $this->assertSame(45, $config->timeout_secs);
        $this->assertSame(2, $config->max_retries);
        $this->assertSame(6000, $config->degraded_threshold_ms);
        $this->assertSame('claude-haiku-4-5-20251001', $config->claude_model);
        $this->assertSame('gpt-5.1-codex', $config->codex_model);
        $this->assertSame('gemini-3-pro-preview', $config->gemini_model);
        $this->assertSame('Who are you?', $config->test_prompt);
    }

    public function testFromArray(): void
    {
        $config = StreamCheckConfig::fromArray([
            'timeout_secs' => 30,
            'max_retries' => 5,
            'degraded_threshold_ms' => 3000,
            'claude_model' => 'claude-test',
            'codex_model' => 'gpt-test',
            'gemini_model' => 'gemini-test',
            'test_prompt' => 'Hello!',
        ]);

        $this->assertSame(30, $config->timeout_secs);
        $this->assertSame(5, $config->max_retries);
        $this->assertSame(3000, $config->degraded_threshold_ms);
        $this->assertSame('claude-test', $config->claude_model);
        $this->assertSame('gpt-test', $config->codex_model);
        $this->assertSame('gemini-test', $config->gemini_model);
        $this->assertSame('Hello!', $config->test_prompt);
    }

    public function testFromArrayPartial(): void
    {
        $config = StreamCheckConfig::fromArray([
            'timeout_secs' => 10,
        ]);

        $this->assertSame(10, $config->timeout_secs);
        // Other fields keep defaults
        $this->assertSame(2, $config->max_retries);
        $this->assertSame(6000, $config->degraded_threshold_ms);
        $this->assertSame('claude-haiku-4-5-20251001', $config->claude_model);
    }

    public function testFromEmptyArray(): void
    {
        $config = StreamCheckConfig::fromArray([]);

        $this->assertSame(45, $config->timeout_secs);
        $this->assertSame(2, $config->max_retries);
    }

    public function testToArray(): void
    {
        $config = new StreamCheckConfig();
        $arr = $config->toArray();

        $this->assertSame(45, $arr['timeout_secs']);
        $this->assertSame(2, $arr['max_retries']);
        $this->assertSame(6000, $arr['degraded_threshold_ms']);
        $this->assertSame('claude-haiku-4-5-20251001', $arr['claude_model']);
        $this->assertSame('gpt-5.1-codex', $arr['codex_model']);
        $this->assertSame('gemini-3-pro-preview', $arr['gemini_model']);
        $this->assertSame('Who are you?', $arr['test_prompt']);
    }

    public function testRoundTrip(): void
    {
        $original = new StreamCheckConfig();
        $original->timeout_secs = 99;
        $original->max_retries = 7;

        $arr = $original->toArray();
        $restored = StreamCheckConfig::fromArray($arr);

        $this->assertSame($original->timeout_secs, $restored->timeout_secs);
        $this->assertSame($original->max_retries, $restored->max_retries);
        $this->assertSame($original->degraded_threshold_ms, $restored->degraded_threshold_ms);
        $this->assertSame($original->claude_model, $restored->claude_model);
        $this->assertSame($original->codex_model, $restored->codex_model);
        $this->assertSame($original->gemini_model, $restored->gemini_model);
        $this->assertSame($original->test_prompt, $restored->test_prompt);
    }
}
