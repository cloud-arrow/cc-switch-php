<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\OmoService;
use PHPUnit\Framework\TestCase;

class OmoServiceTest extends TestCase
{
    private OmoService $service;

    protected function setUp(): void
    {
        $this->service = new OmoService();
    }

    // --- stripJsonComments ---

    public function testStripLineComments(): void
    {
        $input = "{\n  // comment\n  \"key\": \"value\"\n}";
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('value', $data['key']);
    }

    public function testStripInlineComments(): void
    {
        $input = "{\n  \"key\": \"value\" // inline\n}";
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('value', $data['key']);
    }

    public function testStripBlockComments(): void
    {
        $input = "{\n  /* block\n     comment */\n  \"key\": \"value\"\n}";
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('value', $data['key']);
    }

    public function testPreservesSlashesInsideStrings(): void
    {
        $input = '{"url": "http://example.com", "path": "a//b"}';
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('http://example.com', $data['url']);
        $this->assertSame('a//b', $data['path']);
    }

    public function testPreservesBlockCommentPatternsInsideStrings(): void
    {
        $input = '{"val": "has /* not a comment */ here"}';
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('has /* not a comment */ here', $data['val']);
    }

    public function testComplexJsoncParsing(): void
    {
        $input = <<<'JSONC'
{
  // This is a comment
  "key": "value", // inline comment
  /* multi
     line */
  "key2": "val//ue",
  "key3": "/* not stripped */"
}
JSONC;
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('value', $data['key']);
        $this->assertSame('val//ue', $data['key2']);
        $this->assertSame('/* not stripped */', $data['key3']);
    }

    public function testEscapedQuotesInStrings(): void
    {
        $input = '{"key": "value with \\"escaped\\" quotes // not comment"}';
        $result = $this->service->stripJsonComments($input);
        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertSame('value with "escaped" quotes // not comment', $data['key']);
    }

    // --- Variant paths ---

    public function testGetFilePathStandard(): void
    {
        $path = $this->service->getFilePath('standard');
        $this->assertStringEndsWith('/.openclaw/oh-my-opencode.jsonc', $path);
    }

    public function testGetFilePathSlim(): void
    {
        $path = $this->service->getFilePath('slim');
        $this->assertStringEndsWith('/.openclaw/oh-my-opencode-slim.jsonc', $path);
    }

    public function testInvalidVariantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getFilePath('invalid');
    }

    // --- Export/Import round-trip with temp files ---

    public function testExportAndImportRoundTrip(): void
    {
        $tmpDir = sys_get_temp_dir() . '/omo_test_' . getmypid();
        $origHome = getenv('HOME');

        try {
            // Point HOME to temp so getOpenClawDir → $tmpDir/.openclaw
            putenv("HOME={$tmpDir}");
            $_SERVER['HOME'] = $tmpDir;

            $service = new OmoService();

            $data = [
                'agents' => ['sisyphus' => ['model' => 'claude-opus-4-6']],
                'categories' => ['code' => ['model' => 'gpt-5']],
                'otherFields' => ['$schema' => 'https://example.com/schema.json'],
            ];

            $service->exportToFile('standard', $data);
            $this->assertFileExists($service->getFilePath('standard'));

            $imported = $service->importFromFile('standard');
            $this->assertSame('claude-opus-4-6', $imported['agents']['sisyphus']['model']);
            $this->assertSame('gpt-5', $imported['categories']['code']['model']);
            $this->assertSame('https://example.com/schema.json', $imported['otherFields']['$schema']);
        } finally {
            putenv("HOME={$origHome}");
            $_SERVER['HOME'] = $origHome;
            // Clean up
            $this->recursiveDelete($tmpDir);
        }
    }

    public function testSlimExportExcludesCategories(): void
    {
        $tmpDir = sys_get_temp_dir() . '/omo_slim_test_' . getmypid();
        $origHome = getenv('HOME');

        try {
            putenv("HOME={$tmpDir}");
            $_SERVER['HOME'] = $tmpDir;

            $service = new OmoService();

            $data = [
                'agents' => ['orchestrator' => ['model' => 'k2']],
                'categories' => ['code' => ['model' => 'gpt']],
            ];

            $service->exportToFile('slim', $data);
            $imported = $service->importFromFile('slim');

            $this->assertNotNull($imported['agents']);
            $this->assertNull($imported['categories']); // Slim should not include categories
        } finally {
            putenv("HOME={$origHome}");
            $_SERVER['HOME'] = $origHome;
            $this->recursiveDelete($tmpDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
