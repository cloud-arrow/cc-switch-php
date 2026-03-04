<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

class ThinkingSignatureRectifier
{
    /**
     * Check if the error message matches any of the 7 known signature-related error patterns.
     */
    public function shouldRectify(string $errorMessage): bool
    {
        $lower = strtolower($errorMessage);

        // Pattern 1: "invalid signature in thinking block"
        if (str_contains($lower, 'invalid')
            && str_contains($lower, 'signature')
            && str_contains($lower, 'thinking')
            && str_contains($lower, 'block')) {
            return true;
        }

        // Pattern 2: "must start with a thinking block"
        if (str_contains($lower, 'must start with a thinking block')) {
            return true;
        }

        // Pattern 3: expected thinking/redacted_thinking but found tool_use
        if (str_contains($lower, 'expected')
            && (str_contains($lower, 'thinking') || str_contains($lower, 'redacted_thinking'))
            && str_contains($lower, 'found')
            && str_contains($lower, 'tool_use')) {
            return true;
        }

        // Pattern 4: "signature: Field required"
        if (str_contains($lower, 'signature') && str_contains($lower, 'field required')) {
            return true;
        }

        // Pattern 5: "signature: Extra inputs are not permitted"
        if (str_contains($lower, 'signature') && str_contains($lower, 'extra inputs are not permitted')) {
            return true;
        }

        // Pattern 6: thinking/redacted_thinking blocks "cannot be modified"
        if ((str_contains($lower, 'thinking') || str_contains($lower, 'redacted_thinking'))
            && str_contains($lower, 'cannot be modified')) {
            return true;
        }

        // Pattern 7: invalid/illegal request (catch-all)
        if (str_contains($lower, '非法请求')
            || str_contains($lower, 'illegal request')
            || str_contains($lower, 'invalid request')) {
            return true;
        }

        return false;
    }

    /**
     * Rectify the request body by removing thinking/redacted_thinking blocks and signatures.
     *
     * @param array &$body Request body (modified in place)
     * @return array{applied: bool, removed_thinking_blocks: int, removed_redacted_thinking_blocks: int, removed_signature_fields: int}
     */
    public function rectify(array &$body): array
    {
        $result = [
            'applied' => false,
            'removed_thinking_blocks' => 0,
            'removed_redacted_thinking_blocks' => 0,
            'removed_signature_fields' => 0,
        ];

        if (!isset($body['messages']) || !is_array($body['messages'])) {
            return $result;
        }

        // Process each message
        foreach ($body['messages'] as &$msg) {
            if (!isset($msg['content']) || !is_array($msg['content'])) {
                continue;
            }

            $newContent = [];
            $contentModified = false;

            foreach ($msg['content'] as $block) {
                $blockType = $block['type'] ?? null;

                // Remove thinking blocks
                if ($blockType === 'thinking') {
                    $result['removed_thinking_blocks']++;
                    $contentModified = true;
                    continue;
                }

                // Remove redacted_thinking blocks
                if ($blockType === 'redacted_thinking') {
                    $result['removed_redacted_thinking_blocks']++;
                    $contentModified = true;
                    continue;
                }

                // Remove signature field from non-thinking blocks
                if (isset($block['signature'])) {
                    unset($block['signature']);
                    $result['removed_signature_fields']++;
                    $contentModified = true;
                }

                $newContent[] = $block;
            }

            if ($contentModified) {
                $result['applied'] = true;
                $msg['content'] = $newContent;
            }
        }
        unset($msg);

        // Check if top-level thinking should be removed
        if ($this->shouldRemoveTopLevelThinking($body)) {
            unset($body['thinking']);
            $result['applied'] = true;
        }

        return $result;
    }

    /**
     * Determine if top-level thinking object should be removed.
     *
     * Conditions: type="enabled" AND last assistant message first block is NOT thinking
     * AND last assistant message contains tool_use.
     */
    private function shouldRemoveTopLevelThinking(array $body): bool
    {
        // Only remove if thinking.type === "enabled"
        $thinkingType = $body['thinking']['type'] ?? null;
        if ($thinkingType !== 'enabled') {
            return false;
        }

        $messages = $body['messages'] ?? [];
        if (empty($messages)) {
            return false;
        }

        // Find last assistant message
        $lastAssistant = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'assistant') {
                $lastAssistant = $messages[$i];
                break;
            }
        }

        if ($lastAssistant === null) {
            return false;
        }

        $content = $lastAssistant['content'] ?? [];
        if (!is_array($content) || empty($content)) {
            return false;
        }

        // Check first block is NOT thinking/redacted_thinking
        $firstBlockType = $content[0]['type'] ?? null;
        if ($firstBlockType === 'thinking' || $firstBlockType === 'redacted_thinking') {
            return false;
        }

        // Check if any block is tool_use
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                return true;
            }
        }

        return false;
    }
}
