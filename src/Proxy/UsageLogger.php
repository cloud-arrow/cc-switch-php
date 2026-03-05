<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

use CcSwitch\Database\Repository\ModelPricingRepository;
use CcSwitch\Database\Repository\RequestLogRepository;
use CcSwitch\Model\ModelPricing;
use Ramsey\Uuid\Uuid;

/**
 * Logs API request usage (tokens, cost) to the proxy_request_logs table.
 */
class UsageLogger
{
    public function __construct(
        private readonly RequestLogRepository $logRepo,
        private readonly ?ModelPricingRepository $pricingRepo = null,
    ) {
    }

    /**
     * Log a completed request.
     *
     * @param array{
     *   provider_id: string,
     *   app_type: string,
     *   model: string,
     *   request_model?: string,
     *   input_tokens?: int,
     *   output_tokens?: int,
     *   cache_read_tokens?: int,
     *   cache_creation_tokens?: int,
     *   latency_ms?: int,
     *   first_token_ms?: int|null,
     *   duration_ms?: int|null,
     *   status_code?: int,
     *   error_message?: string|null,
     *   session_id?: string|null,
     *   provider_type?: string|null,
     *   is_streaming?: bool,
     *   cost_multiplier?: string,
     * } $data
     */
    public function log(array $data): void
    {
        $inputTokens = $data['input_tokens'] ?? 0;
        $outputTokens = $data['output_tokens'] ?? 0;
        $cacheReadTokens = $data['cache_read_tokens'] ?? 0;
        $cacheCreationTokens = $data['cache_creation_tokens'] ?? 0;
        $costMultiplier = (float) ($data['cost_multiplier'] ?? '1.0');

        // Look up model pricing from database, fall back to defaults
        $model = $data['model'] ?? '';
        $pricing = $this->pricingRepo?->findByModelId($model);

        $inputRate = $pricing ? (float) $pricing['input_cost_per_million'] : 3.0;
        $outputRate = $pricing ? (float) $pricing['output_cost_per_million'] : 15.0;
        $cacheReadRate = $pricing ? (float) $pricing['cache_read_cost_per_million'] : 0.3;
        $cacheCreationRate = $pricing ? (float) $pricing['cache_creation_cost_per_million'] : 3.75;

        $inputCost = ($inputTokens / 1_000_000) * $inputRate * $costMultiplier;
        $outputCost = ($outputTokens / 1_000_000) * $outputRate * $costMultiplier;
        $cacheReadCost = ($cacheReadTokens / 1_000_000) * $cacheReadRate * $costMultiplier;
        $cacheCreationCost = ($cacheCreationTokens / 1_000_000) * $cacheCreationRate * $costMultiplier;
        $totalCost = $inputCost + $outputCost + $cacheReadCost + $cacheCreationCost;

        $this->logRepo->insert([
            'request_id' => Uuid::uuid4()->toString(),
            'provider_id' => $data['provider_id'] ?? '',
            'app_type' => $data['app_type'] ?? '',
            'model' => $data['model'] ?? '',
            'request_model' => $data['request_model'] ?? $data['model'] ?? '',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'input_cost_usd' => number_format($inputCost, 10, '.', ''),
            'output_cost_usd' => number_format($outputCost, 10, '.', ''),
            'cache_read_cost_usd' => number_format($cacheReadCost, 10, '.', ''),
            'cache_creation_cost_usd' => number_format($cacheCreationCost, 10, '.', ''),
            'total_cost_usd' => number_format($totalCost, 10, '.', ''),
            'latency_ms' => $data['latency_ms'] ?? 0,
            'first_token_ms' => $data['first_token_ms'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'status_code' => $data['status_code'] ?? 0,
            'error_message' => $data['error_message'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'provider_type' => $data['provider_type'] ?? null,
            'is_streaming' => ($data['is_streaming'] ?? false) ? 1 : 0,
            'cost_multiplier' => (string) $costMultiplier,
            'created_at' => time(),
        ]);
    }

    /**
     * Parse token usage from an Anthropic API response body.
     *
     * @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_creation_tokens: int}
     */
    public function parseAnthropicUsage(array $body): array
    {
        $usage = $body['usage'] ?? [];
        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
        ];
    }

    /**
     * Parse token usage from an OpenAI API response body.
     *
     * @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_creation_tokens: int}
     */
    public function parseOpenAIUsage(array $body): array
    {
        $usage = $body['usage'] ?? [];
        return [
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'cache_read_tokens' => 0,
            'cache_creation_tokens' => 0,
        ];
    }

    /**
     * Parse token usage from Claude SSE stream events.
     * Looks for message_start (input tokens) and message_delta (output tokens).
     *
     * @param array<int, array> $events Decoded SSE event data arrays
     * @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_creation_tokens: int}
     */
    public function parseClaudeStreamUsage(array $events): array
    {
        $result = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_creation_tokens' => 0,
        ];

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            if ($type === 'message_start') {
                $usage = $event['message']['usage'] ?? [];
                $result['input_tokens'] = (int) ($usage['input_tokens'] ?? 0);
                $result['cache_read_tokens'] = (int) ($usage['cache_read_input_tokens'] ?? 0);
                $result['cache_creation_tokens'] = (int) ($usage['cache_creation_input_tokens'] ?? 0);
            } elseif ($type === 'message_delta') {
                $usage = $event['usage'] ?? [];
                $result['output_tokens'] = (int) ($usage['output_tokens'] ?? 0);
                // OpenRouter fallback: input_tokens might be in message_delta
                if ($result['input_tokens'] === 0 && isset($usage['input_tokens'])) {
                    $result['input_tokens'] = (int) $usage['input_tokens'];
                }
            }
        }

        return $result;
    }

    /**
     * Parse token usage from OpenAI SSE stream events.
     * Looks for the last chunk containing usage data.
     *
     * @param array<int, array> $events Decoded SSE event data arrays
     * @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_creation_tokens: int}
     */
    public function parseOpenAIStreamUsage(array $events): array
    {
        // Search from last event backwards for usage data
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];

            // Chat Completions format: usage at top level with prompt_tokens/completion_tokens
            $usage = $event['usage'] ?? null;
            if ($usage !== null) {
                // Chat Completions uses prompt_tokens/completion_tokens
                if (isset($usage['prompt_tokens'])) {
                    return [
                        'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                        'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                        'cache_read_tokens' => 0,
                        'cache_creation_tokens' => 0,
                    ];
                }
                // Responses API uses input_tokens/output_tokens
                if (isset($usage['input_tokens'])) {
                    return [
                        'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                        'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                        'cache_read_tokens' => 0,
                        'cache_creation_tokens' => 0,
                    ];
                }
            }

            // Responses API: usage nested in response.completed event
            $responseUsage = $event['response']['usage'] ?? null;
            if ($responseUsage !== null) {
                return [
                    'input_tokens' => (int) ($responseUsage['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($responseUsage['output_tokens'] ?? 0),
                    'cache_read_tokens' => 0,
                    'cache_creation_tokens' => 0,
                ];
            }
        }

        return [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_creation_tokens' => 0,
        ];
    }
}
