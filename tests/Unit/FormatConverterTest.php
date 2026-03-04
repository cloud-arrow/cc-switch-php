<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Proxy\FormatConverter;
use PHPUnit\Framework\TestCase;

class FormatConverterTest extends TestCase
{
    private FormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new FormatConverter();
    }

    // --- detectFormat ---

    public function testDetectAnthropicBySystemKey(): void
    {
        $this->assertSame('anthropic', $this->converter->detectFormat([
            'system' => 'You are helpful.',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]));
    }

    public function testDetectOpenAIBySystemRole(): void
    {
        $this->assertSame('openai', $this->converter->detectFormat([
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'Hi'],
            ],
        ]));
    }

    public function testDetectAnthropicByStopSequences(): void
    {
        $this->assertSame('anthropic', $this->converter->detectFormat([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'stop_sequences' => ["\n\nHuman:"],
        ]));
    }

    public function testDetectOpenAIByStop(): void
    {
        $this->assertSame('openai', $this->converter->detectFormat([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'stop' => ["\n"],
        ]));
    }

    public function testDetectDefaultsToAnthropic(): void
    {
        $this->assertSame('anthropic', $this->converter->detectFormat([
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]));
    }

    // --- anthropicToOpenAI ---

    public function testAnthropicToOpenAIBasicMessage(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'model' => 'claude-sonnet-4-20250514',
            'system' => 'Be concise.',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 1024,
            'temperature' => 0.7,
            'stream' => true,
        ]);

        $this->assertSame('claude-sonnet-4-20250514', $result['model']);
        $this->assertSame(1024, $result['max_tokens']);
        $this->assertSame(0.7, $result['temperature']);
        $this->assertTrue($result['stream']);

        // System prompt becomes first message
        $this->assertSame('system', $result['messages'][0]['role']);
        $this->assertSame('Be concise.', $result['messages'][0]['content']);

        // User message follows
        $this->assertSame('user', $result['messages'][1]['role']);
        $this->assertSame('Hello', $result['messages'][1]['content']);
    }

    public function testAnthropicToOpenAISystemAsArray(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'system' => [
                ['type' => 'text', 'text' => 'Part one.'],
                ['type' => 'text', 'text' => 'Part two.'],
            ],
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);

        $this->assertSame('system', $result['messages'][0]['role']);
        $this->assertSame('Part one.', $result['messages'][0]['content']);
        $this->assertSame('system', $result['messages'][1]['role']);
        $this->assertSame('Part two.', $result['messages'][1]['content']);
    }

    public function testAnthropicToOpenAIStopSequences(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'messages' => [],
            'stop_sequences' => ["\n\nHuman:"],
        ]);

        $this->assertSame(["\n\nHuman:"], $result['stop']);
        $this->assertArrayNotHasKey('stop_sequences', $result);
    }

    public function testAnthropicToOpenAIToolConversion(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'messages' => [],
            'tools' => [
                [
                    'name' => 'get_weather',
                    'description' => 'Get weather for a city',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => ['city' => ['type' => 'string']],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $result['tools']);
        $tool = $result['tools'][0];
        $this->assertSame('function', $tool['type']);
        $this->assertSame('get_weather', $tool['function']['name']);
        $this->assertSame('Get weather for a city', $tool['function']['description']);
        $this->assertArrayHasKey('properties', $tool['function']['parameters']);
    }

    public function testAnthropicToOpenAIToolUseBlock(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Let me check.'],
                        [
                            'type' => 'tool_use',
                            'id' => 'call_123',
                            'name' => 'get_weather',
                            'input' => ['city' => 'Tokyo'],
                        ],
                    ],
                ],
            ],
        ]);

        $msg = $result['messages'][0];
        $this->assertSame('assistant', $msg['role']);
        $this->assertSame('Let me check.', $msg['content']);
        $this->assertCount(1, $msg['tool_calls']);
        $this->assertSame('call_123', $msg['tool_calls'][0]['id']);
        $this->assertSame('get_weather', $msg['tool_calls'][0]['function']['name']);
    }

    public function testAnthropicToOpenAIToolResultBlock(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => 'call_123',
                            'content' => '{"temp": 22}',
                        ],
                    ],
                ],
            ],
        ]);

        $msg = $result['messages'][0];
        $this->assertSame('tool', $msg['role']);
        $this->assertSame('call_123', $msg['tool_call_id']);
        $this->assertSame('{"temp": 22}', $msg['content']);
    }

    public function testAnthropicToOpenAIImageBlock(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['media_type' => 'image/png', 'data' => 'abc123']],
                    ],
                ],
            ],
        ]);

        $msg = $result['messages'][0];
        $this->assertSame('user', $msg['role']);
        $this->assertIsArray($msg['content']);
        $this->assertSame('image_url', $msg['content'][0]['type']);
        $this->assertSame('data:image/png;base64,abc123', $msg['content'][0]['image_url']['url']);
    }

    public function testAnthropicToOpenAISkipsThinkingBlocks(): void
    {
        $result = $this->converter->anthropicToOpenAI([
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'internal thoughts'],
                        ['type' => 'text', 'text' => 'Final answer.'],
                    ],
                ],
            ],
        ]);

        $msg = $result['messages'][0];
        $this->assertSame('Final answer.', $msg['content']);
    }

    // --- openAIToAnthropic ---

    public function testOpenAIToAnthropicBasicMessage(): void
    {
        $result = $this->converter->openAIToAnthropic([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Hi'],
            ],
            'max_tokens' => 500,
            'stop' => ["\n"],
            'stream' => false,
        ]);

        $this->assertSame('gpt-4', $result['model']);
        $this->assertSame('Be brief.', $result['system']);
        $this->assertSame(500, $result['max_tokens']);
        $this->assertSame(["\n"], $result['stop_sequences']);
        $this->assertFalse($result['stream']);

        $this->assertCount(1, $result['messages']);
        $this->assertSame('user', $result['messages'][0]['role']);
        $this->assertSame('Hi', $result['messages'][0]['content']);
    }

    public function testOpenAIToAnthropicMultipleSystemMessages(): void
    {
        $result = $this->converter->openAIToAnthropic([
            'messages' => [
                ['role' => 'system', 'content' => 'Part A.'],
                ['role' => 'system', 'content' => 'Part B.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ]);

        $this->assertSame("Part A.\n\nPart B.", $result['system']);
        $this->assertCount(1, $result['messages']);
    }

    public function testOpenAIToAnthropicToolCallMessage(): void
    {
        $result = $this->converter->openAIToAnthropic([
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'Calling tool.',
                    'tool_calls' => [
                        [
                            'id' => 'tc_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search',
                                'arguments' => '{"q":"test"}',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $msg = $result['messages'][0];
        $this->assertSame('assistant', $msg['role']);
        $this->assertCount(2, $msg['content']); // text + tool_use
        $this->assertSame('text', $msg['content'][0]['type']);
        $this->assertSame('Calling tool.', $msg['content'][0]['text']);
        $this->assertSame('tool_use', $msg['content'][1]['type']);
        $this->assertSame('tc_1', $msg['content'][1]['id']);
        $this->assertSame('search', $msg['content'][1]['name']);
        $this->assertSame(['q' => 'test'], $msg['content'][1]['input']);
    }

    public function testOpenAIToAnthropicToolResultMessage(): void
    {
        $result = $this->converter->openAIToAnthropic([
            'messages' => [
                ['role' => 'tool', 'tool_call_id' => 'tc_1', 'content' => 'Result data'],
            ],
        ]);

        $msg = $result['messages'][0];
        $this->assertSame('user', $msg['role']);
        $this->assertSame('tool_result', $msg['content'][0]['type']);
        $this->assertSame('tc_1', $msg['content'][0]['tool_use_id']);
        $this->assertSame('Result data', $msg['content'][0]['content']);
    }

    public function testOpenAIToAnthropicToolSchemaConversion(): void
    {
        $result = $this->converter->openAIToAnthropic([
            'messages' => [],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'calc',
                        'description' => 'Calculate',
                        'parameters' => ['type' => 'object', 'properties' => ['x' => ['type' => 'number']]],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $result['tools']);
        $this->assertSame('calc', $result['tools'][0]['name']);
        $this->assertSame('Calculate', $result['tools'][0]['description']);
        $this->assertArrayHasKey('properties', $result['tools'][0]['input_schema']);
    }

    // --- Roundtrip ---

    public function testRoundtripAnthropicToOpenAIAndBack(): void
    {
        $original = [
            'model' => 'claude-sonnet-4-20250514',
            'system' => 'Be helpful.',
            'messages' => [
                ['role' => 'user', 'content' => 'What is 2+2?'],
                ['role' => 'assistant', 'content' => '4'],
            ],
            'max_tokens' => 256,
            'temperature' => 0.5,
            'stream' => true,
        ];

        $openai = $this->converter->anthropicToOpenAI($original);
        $back = $this->converter->openAIToAnthropic($openai);

        $this->assertSame($original['model'], $back['model']);
        $this->assertSame($original['system'], $back['system']);
        $this->assertSame($original['max_tokens'], $back['max_tokens']);
        $this->assertSame($original['temperature'], $back['temperature']);
        $this->assertSame($original['stream'], $back['stream']);

        // Messages should match (excluding system which was pulled out)
        $this->assertCount(2, $back['messages']);
        $this->assertSame('user', $back['messages'][0]['role']);
        $this->assertSame('What is 2+2?', $back['messages'][0]['content']);
        $this->assertSame('assistant', $back['messages'][1]['role']);
        $this->assertSame('4', $back['messages'][1]['content']);
    }
}
