<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\AppType;

/**
 * Factory to create the appropriate config writer for a given app type.
 */
class WriterFactory
{
    /**
     * Create a writer for the given application type.
     */
    public static function create(AppType $appType): WriterInterface
    {
        return match ($appType) {
            AppType::Claude => new ClaudeWriter(),
            AppType::Codex => new CodexWriter(),
            AppType::Gemini => new GeminiWriter(),
            AppType::OpenCode => new OpenCodeWriter(),
            AppType::OpenClaw => new OpenClawWriter(),
        };
    }
}
