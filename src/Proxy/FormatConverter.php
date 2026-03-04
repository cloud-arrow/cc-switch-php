<?php

declare(strict_types=1);

namespace CcSwitch\Proxy;

/**
 * Converts between Anthropic Messages API and OpenAI Chat Completions API formats.
 */
class FormatConverter
{
    /**
     * Detect the format of a request body.
     *
     * @return string 'anthropic' or 'openai'
     */
    public function detectFormat(array $request): string
    {
        // Anthropic uses a top-level "system" parameter (string or array)
        if (array_key_exists('system', $request)) {
            return 'anthropic';
        }

        // Check messages: if first message has role=system, likely OpenAI format
        $messages = $request['messages'] ?? [];
        if (!empty($messages) && ($messages[0]['role'] ?? '') === 'system') {
            return 'openai';
        }

        // Anthropic uses "max_tokens", OpenAI uses "max_tokens" too, but
        // check for "stop_sequences" (Anthropic) vs "stop" (OpenAI)
        if (array_key_exists('stop_sequences', $request)) {
            return 'anthropic';
        }
        if (array_key_exists('stop', $request)) {
            return 'openai';
        }

        // Default to anthropic
        return 'anthropic';
    }

    /**
     * Convert an Anthropic Messages API request to OpenAI Chat Completions format.
     */
    public function anthropicToOpenAI(array $request): array
    {
        $result = [];

        // Model passthrough
        if (isset($request['model'])) {
            $result['model'] = $request['model'];
        }

        $messages = [];

        // Handle system prompt
        if (isset($request['system'])) {
            $system = $request['system'];
            if (is_string($system)) {
                $messages[] = ['role' => 'system', 'content' => $system];
            } elseif (is_array($system)) {
                foreach ($system as $msg) {
                    $text = $msg['text'] ?? null;
                    if ($text !== null) {
                        $messages[] = ['role' => 'system', 'content' => $text];
                    }
                }
            }
        }

        // Convert messages
        foreach ($request['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? null;
            $converted = $this->convertMessageToOpenAI($role, $content);
            foreach ($converted as $m) {
                $messages[] = $m;
            }
        }

        $result['messages'] = $messages;

        // Parameters
        if (isset($request['max_tokens'])) {
            $result['max_tokens'] = $request['max_tokens'];
        }
        if (isset($request['temperature'])) {
            $result['temperature'] = $request['temperature'];
        }
        if (isset($request['top_p'])) {
            $result['top_p'] = $request['top_p'];
        }
        if (isset($request['stop_sequences'])) {
            $result['stop'] = $request['stop_sequences'];
        }
        if (isset($request['stream'])) {
            $result['stream'] = $request['stream'];
        }

        // Tools
        if (isset($request['tools']) && is_array($request['tools'])) {
            $openaiTools = [];
            foreach ($request['tools'] as $tool) {
                if (($tool['type'] ?? '') === 'BatchTool') {
                    continue;
                }
                $openaiTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'] ?? '',
                        'description' => $tool['description'] ?? null,
                        'parameters' => $tool['input_schema'] ?? new \stdClass(),
                    ],
                ];
            }
            if (!empty($openaiTools)) {
                $result['tools'] = $openaiTools;
            }
        }

        if (isset($request['tool_choice'])) {
            $result['tool_choice'] = $request['tool_choice'];
        }

        return $result;
    }

    /**
     * Convert an OpenAI Chat Completions request to Anthropic Messages API format.
     */
    public function openAIToAnthropic(array $request): array
    {
        $result = [];

        if (isset($request['model'])) {
            $result['model'] = $request['model'];
        }

        $systemParts = [];
        $messages = [];

        foreach ($request['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            if ($role === 'system') {
                $content = $msg['content'] ?? '';
                if (is_string($content)) {
                    $systemParts[] = $content;
                }
                continue;
            }

            if ($role === 'tool') {
                // OpenAI tool message -> Anthropic tool_result content block
                $messages[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $msg['tool_call_id'] ?? '',
                        'content' => $msg['content'] ?? '',
                    ]],
                ];
                continue;
            }

            $converted = ['role' => $role];

            // Handle tool_calls in assistant messages
            if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $contentBlocks = [];
                $textContent = $msg['content'] ?? null;
                if (is_string($textContent) && $textContent !== '') {
                    $contentBlocks[] = ['type' => 'text', 'text' => $textContent];
                }
                foreach ($msg['tool_calls'] as $tc) {
                    $func = $tc['function'] ?? [];
                    $argsStr = $func['arguments'] ?? '{}';
                    $input = json_decode($argsStr, true);
                    if (!is_array($input)) {
                        $input = new \stdClass();
                    }
                    $contentBlocks[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'] ?? '',
                        'name' => $func['name'] ?? '',
                        'input' => $input,
                    ];
                }
                $converted['content'] = $contentBlocks;
            } else {
                $converted['content'] = $msg['content'] ?? '';
            }

            $messages[] = $converted;
        }

        if (!empty($systemParts)) {
            $result['system'] = implode("\n\n", $systemParts);
        }

        $result['messages'] = $messages;

        // Parameters
        if (isset($request['max_tokens'])) {
            $result['max_tokens'] = $request['max_tokens'];
        }
        if (isset($request['temperature'])) {
            $result['temperature'] = $request['temperature'];
        }
        if (isset($request['top_p'])) {
            $result['top_p'] = $request['top_p'];
        }
        if (isset($request['stop'])) {
            $result['stop_sequences'] = $request['stop'];
        }
        if (isset($request['stream'])) {
            $result['stream'] = $request['stream'];
        }

        // Tools
        if (isset($request['tools']) && is_array($request['tools'])) {
            $anthropicTools = [];
            foreach ($request['tools'] as $tool) {
                $func = $tool['function'] ?? [];
                $anthropicTools[] = [
                    'name' => $func['name'] ?? '',
                    'description' => $func['description'] ?? null,
                    'input_schema' => $func['parameters'] ?? new \stdClass(),
                ];
            }
            if (!empty($anthropicTools)) {
                $result['tools'] = $anthropicTools;
            }
        }

        if (isset($request['tool_choice'])) {
            $result['tool_choice'] = $request['tool_choice'];
        }

        return $result;
    }

    /**
     * Convert a single Anthropic message to OpenAI format (may produce multiple messages).
     */
    private function convertMessageToOpenAI(string $role, $content): array
    {
        $result = [];

        if ($content === null) {
            $result[] = ['role' => $role, 'content' => null];
            return $result;
        }

        if (is_string($content)) {
            $result[] = ['role' => $role, 'content' => $content];
            return $result;
        }

        if (!is_array($content)) {
            $result[] = ['role' => $role, 'content' => $content];
            return $result;
        }

        $contentParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            switch ($blockType) {
                case 'text':
                    $text = $block['text'] ?? null;
                    if ($text !== null) {
                        $contentParts[] = ['type' => 'text', 'text' => $text];
                    }
                    break;

                case 'image':
                    $source = $block['source'] ?? [];
                    $mediaType = $source['media_type'] ?? 'image/png';
                    $data = $source['data'] ?? '';
                    $contentParts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => "data:{$mediaType};base64,{$data}"],
                    ];
                    break;

                case 'tool_use':
                    $input = $block['input'] ?? new \stdClass();
                    $toolCalls[] = [
                        'id' => $block['id'] ?? '',
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'] ?? '',
                            'arguments' => json_encode($input, JSON_UNESCAPED_SLASHES),
                        ],
                    ];
                    break;

                case 'tool_result':
                    $toolUseId = $block['tool_use_id'] ?? '';
                    $contentVal = $block['content'] ?? '';
                    if (!is_string($contentVal)) {
                        $contentVal = json_encode($contentVal, JSON_UNESCAPED_SLASHES);
                    }
                    $result[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolUseId,
                        'content' => $contentVal,
                    ];
                    break;

                case 'thinking':
                    // Skip thinking blocks
                    break;
            }
        }

        if (!empty($contentParts) || !empty($toolCalls)) {
            $msg = ['role' => $role];

            if (empty($contentParts)) {
                $msg['content'] = null;
            } elseif (count($contentParts) === 1 && isset($contentParts[0]['text'])) {
                $msg['content'] = $contentParts[0]['text'];
            } else {
                $msg['content'] = $contentParts;
            }

            if (!empty($toolCalls)) {
                $msg['tool_calls'] = $toolCalls;
            }

            $result[] = $msg;
        }

        return $result;
    }
}
