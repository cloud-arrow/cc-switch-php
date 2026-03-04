<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

class ThinkingBudgetRectifier
{
    public const MAX_THINKING_BUDGET = 32000;
    public const MAX_TOKENS_VALUE = 64000;
    public const MIN_MAX_TOKENS_FOR_BUDGET = 32001;

    /**
     * Check if the error message indicates a thinking budget constraint error.
     *
     * Triggers when error contains: "budget_tokens" AND "thinking" AND ">= 1024"
     */
    public function shouldRectify(string $errorMessage): bool
    {
        $lower = strtolower($errorMessage);

        $hasBudgetTokens = str_contains($lower, 'budget_tokens') || str_contains($lower, 'budget tokens');
        $hasThinking = str_contains($lower, 'thinking');
        $has1024 = str_contains($lower, 'greater than or equal to 1024')
            || str_contains($lower, '>= 1024')
            || (str_contains($lower, '1024') && str_contains($lower, 'input should be'));

        return $hasBudgetTokens && $hasThinking && $has1024;
    }

    /**
     * Rectify the request body to fix thinking budget constraints.
     *
     * @param array &$body Request body (modified in place)
     * @return array{applied: bool, before: array, after: array}
     */
    public function rectify(array &$body): array
    {
        $before = $this->snapshot($body);

        // Skip adaptive type
        if (($before['thinking_type'] ?? null) === 'adaptive') {
            return [
                'applied' => false,
                'before' => $before,
                'after' => $before,
            ];
        }

        // Ensure thinking object exists
        if (!isset($body['thinking']) || !is_array($body['thinking'])) {
            $body['thinking'] = [];
        }

        $body['thinking']['type'] = 'enabled';
        $body['thinking']['budget_tokens'] = self::MAX_THINKING_BUDGET;

        // Ensure max_tokens is sufficient
        $maxTokens = $body['max_tokens'] ?? null;
        if ($maxTokens === null || $maxTokens < self::MIN_MAX_TOKENS_FOR_BUDGET) {
            $body['max_tokens'] = self::MAX_TOKENS_VALUE;
        }

        $after = $this->snapshot($body);

        return [
            'applied' => $before !== $after,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function snapshot(array $body): array
    {
        return [
            'max_tokens' => $body['max_tokens'] ?? null,
            'thinking_type' => $body['thinking']['type'] ?? null,
            'thinking_budget_tokens' => $body['thinking']['budget_tokens'] ?? null,
        ];
    }
}
