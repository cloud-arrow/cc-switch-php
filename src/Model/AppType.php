<?php

declare(strict_types=1);

namespace CcSwitch\Model;

/**
 * Supported application types.
 */
enum AppType: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case Gemini = 'gemini';
    case OpenCode = 'opencode';
    case OpenClaw = 'openclaw';

    /**
     * Whether this app uses additive mode (config is appended, not replaced).
     */
    public function isAdditiveMode(): bool
    {
        return match ($this) {
            self::OpenCode, self::OpenClaw => true,
            default => false,
        };
    }

    /**
     * Whether this app uses switch mode (only one provider active at a time).
     */
    public function isSwitchMode(): bool
    {
        return match ($this) {
            self::Claude, self::Codex, self::Gemini => true,
            default => false,
        };
    }
}
