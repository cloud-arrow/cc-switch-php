<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Proxy\ThinkingSignatureRectifier;
use PHPUnit\Framework\TestCase;

class ThinkingSignatureRectifierTest extends TestCase
{
    private ThinkingSignatureRectifier $rectifier;

    protected function setUp(): void
    {
        $this->rectifier = new ThinkingSignatureRectifier();
    }

    // ==================== shouldRectify tests ====================

    public function testDetectInvalidSignature(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify(
                'messages.1.content.0: Invalid `signature` in `thinking` block'
            )
        );
    }

    public function testDetectInvalidSignatureNoBackticks(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify(
                'Messages.1.Content.0: invalid signature in thinking block'
            )
        );
    }

    public function testDetectMustStartWithThinking(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify(
                'a final `assistant` message must start with a thinking block'
            )
        );
    }

    public function testDetectThinkingExpectedToolUse(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify(
                "messages.69.content.0.type: Expected `thinking` or `redacted_thinking`, but found `tool_use`."
            )
        );
    }

    public function testNoDetectThinkingExpectedWithoutToolUse(): void
    {
        $this->assertFalse(
            $this->rectifier->shouldRectify(
                "messages.69.content.0.type: Expected `thinking` or `redacted_thinking`, but found `text`."
            )
        );
    }

    public function testDetectSignatureFieldRequired(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify('***.***.***.***.***.signature: Field required')
        );
    }

    public function testDetectSignatureExtraInputs(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify('xxx.signature: Extra inputs are not permitted')
        );
    }

    public function testDetectThinkingCannotBeModified(): void
    {
        $this->assertTrue(
            $this->rectifier->shouldRectify(
                'thinking or redacted_thinking blocks in the response cannot be modified'
            )
        );
    }

    public function testDetectInvalidRequest(): void
    {
        $this->assertTrue($this->rectifier->shouldRectify('invalid request: malformed JSON'));
        $this->assertTrue($this->rectifier->shouldRectify('illegal request: tool_use block mismatch'));
        $this->assertTrue($this->rectifier->shouldRectify('非法请求：thinking signature 不合法'));
    }

    public function testNoTriggerForUnrelatedError(): void
    {
        $this->assertFalse($this->rectifier->shouldRectify('Request timeout'));
        $this->assertFalse($this->rectifier->shouldRectify('Connection refused'));
    }

    public function testNoTriggerForEmptyString(): void
    {
        $this->assertFalse($this->rectifier->shouldRectify(''));
    }

    // ==================== rectify tests ====================

    public function testRectifyRemovesThinkingBlocks(): void
    {
        $body = [
            'model' => 'claude-test',
            'messages' => [[
                'role' => 'assistant',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 't', 'signature' => 'sig'],
                    ['type' => 'text', 'text' => 'hello', 'signature' => 'sig_text'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'WebSearch', 'input' => [], 'signature' => 'sig_tool'],
                    ['type' => 'redacted_thinking', 'data' => 'r', 'signature' => 'sig_redacted'],
                ],
            ]],
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['removed_thinking_blocks']);
        $this->assertSame(1, $result['removed_redacted_thinking_blocks']);
        $this->assertSame(2, $result['removed_signature_fields']);

        $content = $body['messages'][0]['content'];
        $this->assertCount(2, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertArrayNotHasKey('signature', $content[0]);
        $this->assertSame('tool_use', $content[1]['type']);
        $this->assertArrayNotHasKey('signature', $content[1]);
    }

    public function testRectifyRemovesTopLevelThinking(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'WebSearch', 'input' => []],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'ok'],
                    ],
                ],
            ],
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function testRectifyNoChangeWhenNoIssues(): void
    {
        $body = [
            'model' => 'claude-test',
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'hello']],
            ]],
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertFalse($result['applied']);
        $this->assertSame(0, $result['removed_thinking_blocks']);
    }

    public function testRectifyNoMessages(): void
    {
        $body = ['model' => 'claude-test'];
        $result = $this->rectifier->rectify($body);
        $this->assertFalse($result['applied']);
    }

    public function testRectifyAdaptiveDoesNotRemoveTopLevel(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'adaptive'],
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'WebSearch', 'input' => []],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'ok'],
                    ],
                ],
            ],
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertFalse($result['applied']);
        $this->assertSame('adaptive', $body['thinking']['type']);
    }

    public function testRectifyAdaptiveStillCleansLegacyBlocks(): void
    {
        $body = [
            'model' => 'claude-test',
            'thinking' => ['type' => 'adaptive'],
            'messages' => [[
                'role' => 'assistant',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 't', 'signature' => 'sig_thinking'],
                    ['type' => 'text', 'text' => 'hello', 'signature' => 'sig_text'],
                ],
            ]],
        ];

        $result = $this->rectifier->rectify($body);

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['removed_thinking_blocks']);
        $content = $body['messages'][0]['content'];
        $this->assertCount(1, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertArrayNotHasKey('signature', $content[0]);
        $this->assertSame('adaptive', $body['thinking']['type']);
    }
}
