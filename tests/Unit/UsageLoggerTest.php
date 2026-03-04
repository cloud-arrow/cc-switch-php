<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Repository\RequestLogRepository;
use CcSwitch\Proxy\UsageLogger;
use PHPUnit\Framework\TestCase;

class UsageLoggerTest extends TestCase
{
    private UsageLogger $logger;

    protected function setUp(): void
    {
        $logRepo = $this->createMock(RequestLogRepository::class);
        $this->logger = new UsageLogger($logRepo);
    }

    public function testParseAnthropicUsageWithData(): void
    {
        $body = [
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cache_read_input_tokens' => 20,
                'cache_creation_input_tokens' => 10,
            ],
        ];

        $result = $this->logger->parseAnthropicUsage($body);

        $this->assertSame(100, $result['input_tokens']);
        $this->assertSame(50, $result['output_tokens']);
        $this->assertSame(20, $result['cache_read_tokens']);
        $this->assertSame(10, $result['cache_creation_tokens']);
    }

    public function testParseAnthropicUsageEmpty(): void
    {
        $result = $this->logger->parseAnthropicUsage([]);

        $this->assertSame(0, $result['input_tokens']);
        $this->assertSame(0, $result['output_tokens']);
        $this->assertSame(0, $result['cache_read_tokens']);
        $this->assertSame(0, $result['cache_creation_tokens']);
    }

    public function testParseOpenAIUsageWithData(): void
    {
        $body = [
            'usage' => [
                'prompt_tokens' => 200,
                'completion_tokens' => 100,
            ],
        ];

        $result = $this->logger->parseOpenAIUsage($body);

        $this->assertSame(200, $result['input_tokens']);
        $this->assertSame(100, $result['output_tokens']);
        $this->assertSame(0, $result['cache_read_tokens']);
        $this->assertSame(0, $result['cache_creation_tokens']);
    }

    public function testParseOpenAIUsageEmpty(): void
    {
        $result = $this->logger->parseOpenAIUsage([]);

        $this->assertSame(0, $result['input_tokens']);
        $this->assertSame(0, $result['output_tokens']);
    }

    public function testParseClaudeStreamUsage(): void
    {
        $events = [
            [
                'type' => 'message_start',
                'message' => [
                    'usage' => [
                        'input_tokens' => 500,
                        'cache_read_input_tokens' => 100,
                        'cache_creation_input_tokens' => 50,
                    ],
                ],
            ],
            [
                'type' => 'content_block_delta',
                'delta' => ['text' => 'Hello'],
            ],
            [
                'type' => 'message_delta',
                'usage' => [
                    'output_tokens' => 200,
                ],
            ],
        ];

        $result = $this->logger->parseClaudeStreamUsage($events);

        $this->assertSame(500, $result['input_tokens']);
        $this->assertSame(200, $result['output_tokens']);
        $this->assertSame(100, $result['cache_read_tokens']);
        $this->assertSame(50, $result['cache_creation_tokens']);
    }

    public function testParseClaudeStreamUsageOpenRouterFallback(): void
    {
        $events = [
            [
                'type' => 'message_delta',
                'usage' => [
                    'input_tokens' => 300,
                    'output_tokens' => 150,
                ],
            ],
        ];

        $result = $this->logger->parseClaudeStreamUsage($events);

        $this->assertSame(300, $result['input_tokens']);
        $this->assertSame(150, $result['output_tokens']);
    }

    public function testParseClaudeStreamUsageEmpty(): void
    {
        $result = $this->logger->parseClaudeStreamUsage([]);

        $this->assertSame(0, $result['input_tokens']);
        $this->assertSame(0, $result['output_tokens']);
        $this->assertSame(0, $result['cache_read_tokens']);
        $this->assertSame(0, $result['cache_creation_tokens']);
    }

    public function testParseOpenAIStreamUsage(): void
    {
        $events = [
            ['choices' => [['delta' => ['content' => 'Hi']]]],
            ['choices' => [['delta' => ['content' => ' there']]]],
            [
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                ],
            ],
        ];

        $result = $this->logger->parseOpenAIStreamUsage($events);

        $this->assertSame(100, $result['input_tokens']);
        $this->assertSame(50, $result['output_tokens']);
    }

    public function testParseOpenAIStreamUsageNoUsage(): void
    {
        $events = [
            ['choices' => [['delta' => ['content' => 'Hi']]]],
        ];

        $result = $this->logger->parseOpenAIStreamUsage($events);

        $this->assertSame(0, $result['input_tokens']);
        $this->assertSame(0, $result['output_tokens']);
    }

    public function testParseOpenAIStreamUsageEmpty(): void
    {
        $result = $this->logger->parseOpenAIStreamUsage([]);

        $this->assertSame(0, $result['input_tokens']);
    }

    public function testLogCallsInsert(): void
    {
        $logRepo = $this->createMock(RequestLogRepository::class);
        $logRepo->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (array $data) {
                return $data['provider_id'] === 'test-provider'
                    && $data['app_type'] === 'claude'
                    && $data['model'] === 'claude-sonnet-4-20250514'
                    && $data['input_tokens'] === 1000
                    && $data['output_tokens'] === 500;
            }));

        $logger = new UsageLogger($logRepo);
        $logger->log([
            'provider_id' => 'test-provider',
            'app_type' => 'claude',
            'model' => 'claude-sonnet-4-20250514',
            'input_tokens' => 1000,
            'output_tokens' => 500,
        ]);
    }

    public function testLogWithCostMultiplier(): void
    {
        $logRepo = $this->createMock(RequestLogRepository::class);
        $logRepo->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (array $data) {
                return $data['cost_multiplier'] === '2';
            }));

        $logger = new UsageLogger($logRepo);
        $logger->log([
            'provider_id' => 'test',
            'app_type' => 'claude',
            'model' => 'test-model',
            'cost_multiplier' => '2.0',
        ]);
    }

    public function testLogWithStreamingFlag(): void
    {
        $logRepo = $this->createMock(RequestLogRepository::class);
        $logRepo->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (array $data) {
                return $data['is_streaming'] === 1;
            }));

        $logger = new UsageLogger($logRepo);
        $logger->log([
            'provider_id' => 'test',
            'app_type' => 'claude',
            'model' => 'test-model',
            'is_streaming' => true,
        ]);
    }
}
