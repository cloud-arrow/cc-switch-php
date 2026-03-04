<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Proxy\ThinkingBudgetRectifier;
use PHPUnit\Framework\TestCase;

class ThinkingBudgetRectifierTest extends TestCase
{
    private ThinkingBudgetRectifier $rectifier;

    protected function setUp(): void
    {
        $this->rectifier = new ThinkingBudgetRectifier();
    }

    // ==================== shouldRectify tests ====================

    public function testDetectBudgetTokensThinkingError(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify(
                'thinking.budget_tokens: Input should be greater than or equal to 1024'
            )
        );
    }

    public function testDetectBudgetTokensWithGreaterEqual1024(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify('thinking budget_tokens must be >= 1024')
        );
    }

    public function testNoTriggerWithoutThinking(): void
    {
        $this->assertFalse(
            $this->rectifier->shouldRectify('budget_tokens must be less than max_tokens')
        );
    }

    public function testNoTriggerWithout1024(): void
    {
        $this->assertFalse(
            $this->rectifier->shouldRectify('budget_tokens: value must be at least 1024')
        );
    }

    public function testNoTriggerForUnrelatedError(): void
    {
        $this->assertFalse($this->rectifier->shouldRectify('Request timeout'));
    }

    public function testNoTriggerForEmptyString(): void
    {
        $this->assertFalse($this->rectifier->shouldRectify(''));
    }

    // ==================== rectify tests ====================

    public function testRectifyBasic(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 512],
            'max_tokens' => 1024,
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertSame('enabled', $result['before']['thinking_type']);
        $this->assertSame(512, $result['before']['thinking_budget_tokens']);
        $this->assertSame(1024, $result['before']['max_tokens']);
        $this->assertSame('enabled', $result['after']['thinking_type']);
        $this->assertSame(32000, $result['after']['thinking_budget_tokens']);
        $this->assertSame(64000, $result['after']['max_tokens']);
        $this->assertSame('enabled', $body['thinking']['type']);
        $this->assertSame(32000, $body['thinking']['budget_tokens']);
        $this->assertSame(64000, $body['max_tokens']);
    }

    public function testRectifySkipsAdaptive(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'adaptive', 'budget_tokens' => 512],
            'max_tokens' => 1024,
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertFalse($result['applied']);
        $this->assertSame($result['before'], $result['after']);
        $this->assertSame('adaptive', $body['thinking']['type']);
        $this->assertSame(512, $body['thinking']['budget_tokens']);
        $this->assertSame(1024, $body['max_tokens']);
    }

    public function testRectifyPreservesLargeMaxTokens(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 512],
            'max_tokens' => 100000,
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertSame(100000, $result['before']['max_tokens']);
        $this->assertSame(100000, $result['after']['max_tokens']);
        $this->assertSame(100000, $body['max_tokens']);
    }

    public function testRectifyCreatesThinkingWhenMissing(): void
    {
        $body = [
            'model' => 'claude-test',
            'max_tokens' => 1024,
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertNull($result['before']['thinking_type']);
        $this->assertSame('enabled', $result['after']['thinking_type']);
        $this->assertSame(32000, $result['after']['thinking_budget_tokens']);
        $this->assertSame(64000, $result['after']['max_tokens']);
        $this->assertSame('enabled', $body['thinking']['type']);
        $this->assertSame(32000, $body['thinking']['budget_tokens']);
    }

    public function testRectifyNoMaxTokens(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 512],
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertNull($result['before']['max_tokens']);
        $this->assertSame(64000, $result['after']['max_tokens']);
        $this->assertSame(64000, $body['max_tokens']);
    }

    public function testRectifyNormalizesDisabledType(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'disabled', 'budget_tokens' => 512],
            'max_tokens' => 1024,
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertSame('disabled', $result['before']['thinking_type']);
        $this->assertSame('enabled', $result['after']['thinking_type']);
    }

    public function testRectifyNoChangeWhenAlreadyValid(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 32000],
            'max_tokens' => 64001,
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertFalse($result['applied']);
        $this->assertSame($result['before'], $result['after']);
    }
}
