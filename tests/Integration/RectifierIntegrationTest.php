<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Proxy\ThinkingBudgetRectifier;
use CcSwitch\Proxy\ThinkingSignatureRectifier;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the rectifier chain: ThinkingSignatureRectifier + ThinkingBudgetRectifier.
 *
 * Since RequestHandler requires Swoole, we test the rectifier logic as integrated components
 * that are applied sequentially to the same request body.
 */
class RectifierIntegrationTest extends TestCase
{
    private ThinkingSignatureRectifier $signatureRectifier;
    private ThinkingBudgetRectifier $budgetRectifier;

    protected function setUp(): void
    {
        $this->signatureRectifier = new ThinkingSignatureRectifier();
        $this->budgetRectifier = new ThinkingBudgetRectifier();
    }

    public function testSignatureRectifierThenBudgetRectifier(): void
    {
        // Body with both signature issues and budget issues
        $body = [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 1024,
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 500, // Too low (needs >= 1024)
            ],
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'some thought', 'signature' => 'sig123'],
                        ['type' => 'text', 'text' => 'Hello', 'signature' => 'sig456'],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => 'Continue',
                ],
            ],
        ];

        // Signature rectifier fires first - removes thinking blocks and signatures
        $sigResult = $this->signatureRectifier->rectify($body);
        $this->assertTrue($sigResult['applied']);
        $this->assertSame(1, $sigResult['removed_thinking_blocks']);
        $this->assertSame(1, $sigResult['removed_signature_fields']);

        // Budget rectifier fires second - fixes budget tokens
        $budgetResult = $this->budgetRectifier->rectify($body);
        $this->assertTrue($budgetResult['applied']);
        $this->assertSame(ThinkingBudgetRectifier::MAX_THINKING_BUDGET, $body['thinking']['budget_tokens']);
        $this->assertSame(ThinkingBudgetRectifier::MAX_TOKENS_VALUE, $body['max_tokens']);
    }

    public function testSignatureRectifierCleansAndBudgetRectifierFixes(): void
    {
        $body = [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 2048,
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 100,
            ],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hello',
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'internal monologue', 'signature' => 'abc'],
                        ['type' => 'redacted_thinking', 'data' => 'redacted'],
                        ['type' => 'text', 'text' => 'Response text', 'signature' => 'def'],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => 'More please',
                ],
            ],
        ];

        // Apply signature rectifier
        $sigResult = $this->signatureRectifier->rectify($body);
        $this->assertTrue($sigResult['applied']);
        $this->assertSame(1, $sigResult['removed_thinking_blocks']);
        $this->assertSame(1, $sigResult['removed_redacted_thinking_blocks']);
        $this->assertSame(1, $sigResult['removed_signature_fields']);

        // Verify thinking/redacted blocks are gone from assistant content
        $assistantContent = $body['messages'][1]['content'];
        $this->assertCount(1, $assistantContent);
        $this->assertSame('text', $assistantContent[0]['type']);
        $this->assertArrayNotHasKey('signature', $assistantContent[0]);

        // Apply budget rectifier
        $budgetResult = $this->budgetRectifier->rectify($body);
        $this->assertTrue($budgetResult['applied']);
        $this->assertSame(ThinkingBudgetRectifier::MAX_THINKING_BUDGET, $body['thinking']['budget_tokens']);
        $this->assertSame(ThinkingBudgetRectifier::MAX_TOKENS_VALUE, $body['max_tokens']);
    }

    public function testRectifierFlowWithRealErrorMessages(): void
    {
        // Real Claude API error message for signature issues
        $signatureError = '{"type":"error","error":{"type":"invalid_request_error","message":"Invalid signature in thinking block at messages[1].content[0]"}}';
        $this->assertTrue($this->signatureRectifier->shouldRectify($signatureError));
        $this->assertFalse($this->budgetRectifier->shouldRectify($signatureError));

        // Real Claude API error message for budget issues
        $budgetError = '{"type":"error","error":{"type":"invalid_request_error","message":"thinking.budget_tokens: Input should be greater than or equal to 1024"}}';
        $this->assertTrue($this->budgetRectifier->shouldRectify($budgetError));
        $this->assertFalse($this->signatureRectifier->shouldRectify($budgetError));

        // "must start with a thinking block" error
        $thinkingStartError = '{"type":"error","error":{"type":"invalid_request_error","message":"When thinking is enabled, assistant turn must start with a thinking block"}}';
        $this->assertTrue($this->signatureRectifier->shouldRectify($thinkingStartError));
        $this->assertFalse($this->budgetRectifier->shouldRectify($thinkingStartError));

        // "expected thinking but found tool_use" error
        $expectedThinkingError = '{"type":"error","error":{"type":"invalid_request_error","message":"Expected thinking or redacted_thinking type, but found tool_use"}}';
        $this->assertTrue($this->signatureRectifier->shouldRectify($expectedThinkingError));
        $this->assertFalse($this->budgetRectifier->shouldRectify($expectedThinkingError));

        // "signature: Field required" error
        $fieldRequiredError = '{"type":"error","error":{"type":"invalid_request_error","message":"messages[0].content[0].signature: Field required"}}';
        $this->assertTrue($this->signatureRectifier->shouldRectify($fieldRequiredError));

        // Neither rectifier should fire for generic errors
        $genericError = '{"type":"error","error":{"type":"authentication_error","message":"Invalid API key"}}';
        $this->assertFalse($this->signatureRectifier->shouldRectify($genericError));
        $this->assertFalse($this->budgetRectifier->shouldRectify($genericError));
    }

    public function testRectifierDoesNotModifyValidRequest(): void
    {
        $body = [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 64000,
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 32000,
            ],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hello, how are you?',
                ],
            ],
        ];

        $originalBody = $body;

        // Signature rectifier should not modify (no thinking blocks in messages, no signatures)
        $sigResult = $this->signatureRectifier->rectify($body);
        $this->assertFalse($sigResult['applied']);

        // Budget rectifier should not modify (budget_tokens=32000 is valid, max_tokens=64000 is sufficient)
        $budgetResult = $this->budgetRectifier->rectify($body);
        $this->assertFalse($budgetResult['applied']);

        // Body should be unchanged
        $this->assertSame($originalBody, $body);
    }

    public function testRectifierChainWithAdaptiveThinking(): void
    {
        $body = [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 4096,
            'thinking' => [
                'type' => 'adaptive',
            ],
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'internal', 'signature' => 'abc'],
                        ['type' => 'text', 'text' => 'Hello'],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => 'Continue',
                ],
            ],
        ];

        // Signature rectifier should still clean thinking blocks
        $sigResult = $this->signatureRectifier->rectify($body);
        $this->assertTrue($sigResult['applied']);
        $this->assertSame(1, $sigResult['removed_thinking_blocks']);

        // Budget rectifier should skip adaptive type
        $budgetResult = $this->budgetRectifier->rectify($body);
        $this->assertFalse($budgetResult['applied']);

        // thinking.type should remain "adaptive"
        $this->assertSame('adaptive', $body['thinking']['type']);
        // max_tokens should remain unchanged
        $this->assertSame(4096, $body['max_tokens']);
    }
}
