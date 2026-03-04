<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Proxy\ModelMapper;
use PHPUnit\Framework\TestCase;

class ModelMapperTest extends TestCase
{
    private ModelMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ModelMapper();
    }

    // --- map() ---

    public function testMapReturnsOriginalWhenNoEnv(): void
    {
        $this->assertSame('claude-sonnet-4-20250514', $this->mapper->map('claude-sonnet-4-20250514', []));
        $this->assertSame('claude-sonnet-4-20250514', $this->mapper->map('claude-sonnet-4-20250514', ['env' => []]));
    }

    public function testMapHaikuModel(): void
    {
        $config = ['env' => ['ANTHROPIC_DEFAULT_HAIKU_MODEL' => 'custom-haiku-v2']];
        $this->assertSame('custom-haiku-v2', $this->mapper->map('claude-haiku-3', $config));
    }

    public function testMapSonnetModel(): void
    {
        $config = ['env' => ['ANTHROPIC_DEFAULT_SONNET_MODEL' => 'custom-sonnet']];
        $this->assertSame('custom-sonnet', $this->mapper->map('claude-sonnet-4-20250514', $config));
    }

    public function testMapOpusModel(): void
    {
        $config = ['env' => ['ANTHROPIC_DEFAULT_OPUS_MODEL' => 'custom-opus']];
        $this->assertSame('custom-opus', $this->mapper->map('claude-opus-4-20250514', $config));
    }

    public function testMapReasoningModelWhenThinkingEnabled(): void
    {
        $config = ['env' => [
            'ANTHROPIC_REASONING_MODEL' => 'reasoning-v1',
            'ANTHROPIC_DEFAULT_SONNET_MODEL' => 'sonnet-custom',
        ]];

        // With thinking enabled, reasoning model takes precedence
        $this->assertSame('reasoning-v1', $this->mapper->map('claude-sonnet-4-20250514', $config, true));

        // Without thinking, normal mapping applies
        $this->assertSame('sonnet-custom', $this->mapper->map('claude-sonnet-4-20250514', $config, false));
    }

    public function testMapDefaultModelFallback(): void
    {
        $config = ['env' => ['ANTHROPIC_MODEL' => 'default-model']];
        $this->assertSame('default-model', $this->mapper->map('unknown-model', $config));
    }

    public function testMapSpecificOverridesDefault(): void
    {
        $config = ['env' => [
            'ANTHROPIC_MODEL' => 'default-model',
            'ANTHROPIC_DEFAULT_HAIKU_MODEL' => 'haiku-custom',
        ]];
        $this->assertSame('haiku-custom', $this->mapper->map('claude-haiku-3', $config));
        $this->assertSame('default-model', $this->mapper->map('some-unknown', $config));
    }

    public function testMapCaseInsensitiveMatch(): void
    {
        $config = ['env' => ['ANTHROPIC_DEFAULT_SONNET_MODEL' => 'mapped']];
        $this->assertSame('mapped', $this->mapper->map('Claude-Sonnet-4', $config));
    }

    public function testMapEmptyEnvValuesIgnored(): void
    {
        $config = ['env' => [
            'ANTHROPIC_DEFAULT_HAIKU_MODEL' => '',
            'ANTHROPIC_MODEL' => 'fallback',
        ]];
        $this->assertSame('fallback', $this->mapper->map('claude-haiku-3', $config));
    }

    // --- hasThinkingEnabled() ---

    public function testHasThinkingEnabledTrue(): void
    {
        $this->assertTrue($this->mapper->hasThinkingEnabled(['thinking' => ['type' => 'enabled']]));
        $this->assertTrue($this->mapper->hasThinkingEnabled(['thinking' => ['type' => 'adaptive']]));
    }

    public function testHasThinkingEnabledFalse(): void
    {
        $this->assertFalse($this->mapper->hasThinkingEnabled([]));
        $this->assertFalse($this->mapper->hasThinkingEnabled(['thinking' => ['type' => 'disabled']]));
        $this->assertFalse($this->mapper->hasThinkingEnabled(['thinking' => []]));
    }

    // --- apply() ---

    public function testApplyModifiesBody(): void
    {
        $body = ['model' => 'claude-sonnet-4-20250514', 'messages' => []];
        $config = ['env' => ['ANTHROPIC_DEFAULT_SONNET_MODEL' => 'custom-sonnet']];

        $result = $this->mapper->apply($body, $config);

        $this->assertSame('claude-sonnet-4-20250514', $result['originalModel']);
        $this->assertSame('custom-sonnet', $result['mappedModel']);
        $this->assertSame('custom-sonnet', $result['body']['model']);
    }

    public function testApplyNoMappingReturnsNull(): void
    {
        $body = ['model' => 'claude-sonnet-4-20250514', 'messages' => []];
        $config = [];

        $result = $this->mapper->apply($body, $config);

        $this->assertSame('claude-sonnet-4-20250514', $result['originalModel']);
        $this->assertNull($result['mappedModel']);
        $this->assertSame('claude-sonnet-4-20250514', $result['body']['model']);
    }

    public function testApplyNoModelInBody(): void
    {
        $body = ['messages' => []];
        $config = ['env' => ['ANTHROPIC_MODEL' => 'x']];

        $result = $this->mapper->apply($body, $config);

        $this->assertNull($result['originalModel']);
        $this->assertNull($result['mappedModel']);
    }

    public function testApplyWithThinkingMode(): void
    {
        $body = [
            'model' => 'claude-sonnet-4-20250514',
            'thinking' => ['type' => 'enabled'],
            'messages' => [],
        ];
        $config = ['env' => [
            'ANTHROPIC_REASONING_MODEL' => 'reasoning-v1',
            'ANTHROPIC_DEFAULT_SONNET_MODEL' => 'sonnet-custom',
        ]];

        $result = $this->mapper->apply($body, $config);

        $this->assertSame('reasoning-v1', $result['mappedModel']);
        $this->assertSame('reasoning-v1', $result['body']['model']);
    }
}
