<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Model\AppType;
use PHPUnit\Framework\TestCase;

class AppTypeTest extends TestCase
{
    public function testSwitchModeApps(): void
    {
        $this->assertTrue(AppType::Claude->isSwitchMode());
        $this->assertTrue(AppType::Codex->isSwitchMode());
        $this->assertTrue(AppType::Gemini->isSwitchMode());
    }

    public function testAdditiveModeApps(): void
    {
        $this->assertTrue(AppType::OpenCode->isAdditiveMode());
        $this->assertTrue(AppType::OpenClaw->isAdditiveMode());
    }

    public function testSwitchModeAppsAreNotAdditive(): void
    {
        $this->assertFalse(AppType::Claude->isAdditiveMode());
        $this->assertFalse(AppType::Codex->isAdditiveMode());
        $this->assertFalse(AppType::Gemini->isAdditiveMode());
    }

    public function testAdditiveModeAppsAreNotSwitch(): void
    {
        $this->assertFalse(AppType::OpenCode->isSwitchMode());
        $this->assertFalse(AppType::OpenClaw->isSwitchMode());
    }

    public function testEnumValues(): void
    {
        $this->assertSame('claude', AppType::Claude->value);
        $this->assertSame('codex', AppType::Codex->value);
        $this->assertSame('gemini', AppType::Gemini->value);
        $this->assertSame('opencode', AppType::OpenCode->value);
        $this->assertSame('openclaw', AppType::OpenClaw->value);
    }

    public function testEnumFromString(): void
    {
        $this->assertSame(AppType::Claude, AppType::from('claude'));
        $this->assertSame(AppType::Codex, AppType::from('codex'));
        $this->assertSame(AppType::Gemini, AppType::from('gemini'));
        $this->assertSame(AppType::OpenCode, AppType::from('opencode'));
        $this->assertSame(AppType::OpenClaw, AppType::from('openclaw'));
    }

    public function testEnumFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        AppType::from('invalid');
    }

    public function testEnumCasesCount(): void
    {
        $this->assertCount(5, AppType::cases());
    }
}
