<?php

declare(strict_types=1);

namespace CcSwitch\Model;

class StreamCheckConfig
{
    public int $timeout_secs = 45;
    public int $max_retries = 2;
    public int $degraded_threshold_ms = 6000;
    public string $claude_model = 'claude-haiku-4-5-20251001';
    public string $codex_model = 'gpt-5.1-codex';
    public string $gemini_model = 'gemini-3-pro-preview';
    public string $test_prompt = 'Who are you?';

    public static function fromArray(array $data): self
    {
        $config = new self();
        if (isset($data['timeout_secs'])) {
            $config->timeout_secs = (int) $data['timeout_secs'];
        }
        if (isset($data['max_retries'])) {
            $config->max_retries = (int) $data['max_retries'];
        }
        if (isset($data['degraded_threshold_ms'])) {
            $config->degraded_threshold_ms = (int) $data['degraded_threshold_ms'];
        }
        if (isset($data['claude_model'])) {
            $config->claude_model = (string) $data['claude_model'];
        }
        if (isset($data['codex_model'])) {
            $config->codex_model = (string) $data['codex_model'];
        }
        if (isset($data['gemini_model'])) {
            $config->gemini_model = (string) $data['gemini_model'];
        }
        if (isset($data['test_prompt'])) {
            $config->test_prompt = (string) $data['test_prompt'];
        }
        return $config;
    }

    public function toArray(): array
    {
        return [
            'timeout_secs' => $this->timeout_secs,
            'max_retries' => $this->max_retries,
            'degraded_threshold_ms' => $this->degraded_threshold_ms,
            'claude_model' => $this->claude_model,
            'codex_model' => $this->codex_model,
            'gemini_model' => $this->gemini_model,
            'test_prompt' => $this->test_prompt,
        ];
    }
}
