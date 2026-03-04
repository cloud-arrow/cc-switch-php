<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\ConfigWriter\GeminiWriter;
use PHPUnit\Framework\TestCase;

class GeminiEnvTest extends TestCase
{
    public function testParseEnvBasic(): void
    {
        $content = "KEY1=value1\nKEY2=value2\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertSame(['KEY1' => 'value1', 'KEY2' => 'value2'], $result);
    }

    public function testParseEnvSkipsComments(): void
    {
        $content = "# This is a comment\nKEY=value\n# Another comment\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertSame(['KEY' => 'value'], $result);
    }

    public function testParseEnvSkipsEmptyLines(): void
    {
        $content = "\n\nKEY=value\n\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertSame(['KEY' => 'value'], $result);
    }

    public function testParseEnvSkipsInvalidKeys(): void
    {
        $content = "VALID_KEY=yes\ninvalid key=no\n123_START=ok\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertCount(2, $result);
        $this->assertSame('yes', $result['VALID_KEY']);
        $this->assertSame('ok', $result['123_START']);
    }

    public function testParseEnvSkipsLinesWithoutEquals(): void
    {
        $content = "NO_EQUALS\nKEY=value\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertSame(['KEY' => 'value'], $result);
    }

    public function testParseEnvHandlesValueWithEquals(): void
    {
        $content = "KEY=value=with=equals\n";
        $result = GeminiWriter::parseEnv($content);

        $this->assertSame(['KEY' => 'value=with=equals'], $result);
    }

    public function testSerializeEnvBasic(): void
    {
        $map = ['APPLE' => 'a', 'BANANA' => 'b'];
        $result = GeminiWriter::serializeEnv($map);

        $this->assertSame("APPLE=a\nBANANA=b", $result);
    }

    public function testSerializeEnvSorted(): void
    {
        $map = ['Z_KEY' => '1', 'A_KEY' => '2'];
        $result = GeminiWriter::serializeEnv($map);

        $this->assertSame("A_KEY=2\nZ_KEY=1", $result);
    }

    public function testSerializeEnvEmpty(): void
    {
        $this->assertSame('', GeminiWriter::serializeEnv([]));
    }

    public function testRoundtrip(): void
    {
        $original = ['GEMINI_API_KEY' => 'AIza123', 'GEMINI_MODEL' => 'gemini-pro'];
        $serialized = GeminiWriter::serializeEnv($original);
        $parsed = GeminiWriter::parseEnv($serialized);

        $this->assertSame($original, $parsed);
    }
}
