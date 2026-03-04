<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\DeepLink\DeepLinkParser;
use PHPUnit\Framework\TestCase;

class DeepLinkParserTest extends TestCase
{
    private DeepLinkParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DeepLinkParser();
    }

    // --- Provider parsing ---

    public function testParseProviderUrl(): void
    {
        $url = 'ccswitch://v1/import?resource=provider&app=claude&name=TestProvider&endpoint=https://api.example.com&apiKey=sk-test';

        $result = $this->parser->parse($url);

        $this->assertSame('provider', $result['type']);
        $this->assertSame('claude', $result['data']['app']);
        $this->assertSame('TestProvider', $result['data']['name']);
        $this->assertSame('https://api.example.com', $result['data']['endpoint']);
        $this->assertSame('sk-test', $result['data']['apiKey']);
    }

    public function testParseProviderWithAllFields(): void
    {
        $config = base64_encode('{"env":{"KEY":"val"}}');
        $url = "ccswitch://v1/import?resource=provider&app=codex&name=My+Provider&homepage=https://example.com&icon=cloud&enabled=true&config={$config}&configFormat=json";

        $result = $this->parser->parse($url);

        $this->assertSame('provider', $result['type']);
        $this->assertSame('codex', $result['data']['app']);
        $this->assertSame('My Provider', $result['data']['name']);
        $this->assertSame('https://example.com', $result['data']['homepage']);
        $this->assertSame('cloud', $result['data']['icon']);
        $this->assertTrue($result['data']['enabled']);
        $this->assertSame('{"env":{"KEY":"val"}}', $result['data']['config']);
        $this->assertSame('json', $result['data']['configFormat']);
    }

    public function testParseProviderWithUsageScript(): void
    {
        $script = base64_encode('console.log("hello")');
        $url = "ccswitch://v1/import?resource=provider&app=claude&name=Test&usageScript={$script}&usageEnabled=true&usageApiKey=sk-usage&usageBaseUrl=https://usage.example.com";

        $result = $this->parser->parse($url);

        $this->assertSame('console.log("hello")', $result['data']['usageScript']);
        $this->assertTrue($result['data']['usageEnabled']);
        $this->assertSame('sk-usage', $result['data']['usageApiKey']);
        $this->assertSame('https://usage.example.com', $result['data']['usageBaseUrl']);
    }

    // --- MCP parsing ---

    public function testParseMcpUrl(): void
    {
        $config = base64_encode('{"name":"test-server","command":"npx","args":["test"]}');
        $url = "ccswitch://v1/import?resource=mcp&apps=claude,codex&config={$config}&enabled=true";

        $result = $this->parser->parse($url);

        $this->assertSame('mcp', $result['type']);
        $this->assertSame('claude,codex', $result['data']['apps']);
        $this->assertTrue($result['data']['enabled']);
        $this->assertIsArray($result['data']['config']);
        $this->assertSame('test-server', $result['data']['config']['name']);
    }

    // --- Prompt parsing ---

    public function testParsePromptUrl(): void
    {
        $content = base64_encode('You are a coding assistant.');
        $url = "ccswitch://v1/import?resource=prompt&app=gemini&name=Coder&content={$content}&description=A+coding+prompt&enabled=false";

        $result = $this->parser->parse($url);

        $this->assertSame('prompt', $result['type']);
        $this->assertSame('gemini', $result['data']['app']);
        $this->assertSame('Coder', $result['data']['name']);
        $this->assertSame('You are a coding assistant.', $result['data']['content']);
        $this->assertSame('A coding prompt', $result['data']['description']);
        $this->assertFalse($result['data']['enabled']);
    }

    // --- Skill parsing ---

    public function testParseSkillUrl(): void
    {
        $url = 'ccswitch://v1/import?resource=skill&repo=anthropics/claude-code&directory=skills&branch=main';

        $result = $this->parser->parse($url);

        $this->assertSame('skill', $result['type']);
        $this->assertSame('anthropics/claude-code', $result['data']['repo']);
        $this->assertSame('skills', $result['data']['directory']);
        $this->assertSame('main', $result['data']['branch']);
    }

    public function testParseSkillDefaultBranch(): void
    {
        $url = 'ccswitch://v1/import?resource=skill&repo=owner/name';

        $result = $this->parser->parse($url);

        $this->assertSame('main', $result['data']['branch']);
    }

    // --- Error cases ---

    public function testInvalidScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid scheme");
        $this->parser->parse('https://v1/import?resource=provider&app=claude&name=X');
    }

    public function testUnsupportedVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported protocol version");
        $this->parser->parse('ccswitch://v2/import?resource=provider&app=claude&name=X');
    }

    public function testInvalidPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid path");
        $this->parser->parse('ccswitch://v1/export?resource=provider&app=claude&name=X');
    }

    public function testMissingResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'resource'");
        $this->parser->parse('ccswitch://v1/import?app=claude&name=X');
    }

    public function testUnsupportedResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported resource type");
        $this->parser->parse('ccswitch://v1/import?resource=backup');
    }

    public function testInvalidAppType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid app type");
        $this->parser->parse('ccswitch://v1/import?resource=provider&app=invalid&name=X');
    }

    public function testProviderMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'name'");
        $this->parser->parse('ccswitch://v1/import?resource=provider&app=claude');
    }

    public function testMcpMissingApps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'apps'");
        $config = base64_encode('{}');
        $this->parser->parse("ccswitch://v1/import?resource=mcp&config={$config}");
    }

    public function testMcpInvalidAppInList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid app in 'apps'");
        $config = base64_encode('{}');
        $this->parser->parse("ccswitch://v1/import?resource=mcp&apps=claude,badapp&config={$config}");
    }

    public function testMcpMissingConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'config'");
        $this->parser->parse('ccswitch://v1/import?resource=mcp&apps=claude');
    }

    public function testSkillInvalidRepoFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid repo format");
        $this->parser->parse('ccswitch://v1/import?resource=skill&repo=invalid-no-slash');
    }

    public function testInvalidBase64(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid base64");
        $this->parser->parse('ccswitch://v1/import?resource=mcp&apps=claude&config=!!!invalid!!!');
    }

    // --- All 5 valid app types ---

    /** @dataProvider validAppTypesProvider */
    public function testAllValidAppTypes(string $app): void
    {
        $url = "ccswitch://v1/import?resource=provider&app={$app}&name=Test";
        $result = $this->parser->parse($url);
        $this->assertSame($app, $result['data']['app']);
    }

    public static function validAppTypesProvider(): array
    {
        return [
            ['claude'],
            ['codex'],
            ['gemini'],
            ['opencode'],
            ['openclaw'],
        ];
    }
}
