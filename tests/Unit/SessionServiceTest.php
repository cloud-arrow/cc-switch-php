<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Service\SessionService;
use PHPUnit\Framework\TestCase;

class SessionServiceTest extends TestCase
{
    private SessionService $service;
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-session-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome, 0755, true);
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tmpHome);

        $this->service = new SessionService();
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        $this->recursiveDelete($this->tmpHome);
    }

    public function testScanReturnsEmptyWhenNoDirs(): void
    {
        $result = $this->service->scan();
        $this->assertSame([], $result);
    }

    public function testScanClaudeSessions(): void
    {
        $sessionsDir = $this->tmpHome . '/.claude/projects/test-project/sessions';
        mkdir($sessionsDir, 0755, true);

        $sessionData = json_encode(['title' => 'Test Session', 'cwd' => '/home/user/project']);
        file_put_contents($sessionsDir . '/abc-123.jsonl', $sessionData . "\n");

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame('abc-123', $result[0]['session_id']);
        $this->assertSame('Test Session', $result[0]['title']);
        $this->assertSame('claude', $result[0]['app']);
        $this->assertSame('/home/user/project', $result[0]['project_dir']);
        $this->assertStringContainsString('claude --resume abc-123', $result[0]['resume_command']);
    }

    public function testScanCodexSessions(): void
    {
        $sessionsDir = $this->tmpHome . '/.codex/sessions';
        mkdir($sessionsDir, 0755, true);

        file_put_contents($sessionsDir . '/codex-sess-1.json', json_encode([
            'title' => 'Codex Session',
            'cwd' => '/project',
        ]));

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame('codex-sess-1', $result[0]['session_id']);
        $this->assertSame('codex', $result[0]['app']);
        $this->assertSame('Codex Session', $result[0]['title']);
    }

    public function testScanGeminiSessions(): void
    {
        $sessionsDir = $this->tmpHome . '/.gemini/sessions';
        mkdir($sessionsDir, 0755, true);

        file_put_contents($sessionsDir . '/gem-sess.json', json_encode([
            'name' => 'Gemini Session',
        ]));

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame('gem-sess', $result[0]['session_id']);
        $this->assertSame('gemini', $result[0]['app']);
        $this->assertSame('Gemini Session', $result[0]['title']);
    }

    public function testScanOpenCodeSessions(): void
    {
        $sessionsDir = $this->tmpHome . '/.config/opencode/sessions';
        mkdir($sessionsDir, 0755, true);

        file_put_contents($sessionsDir . '/oc-sess.json', json_encode([
            'title' => 'OpenCode Session',
            'project_dir' => '/oc/project',
        ]));

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame('oc-sess', $result[0]['session_id']);
        $this->assertSame('opencode', $result[0]['app']);
        $this->assertSame('/oc/project', $result[0]['project_dir']);
    }

    public function testScanSessionDirectories(): void
    {
        $sessionsDir = $this->tmpHome . '/.codex/sessions';
        mkdir($sessionsDir . '/dir-session', 0755, true);

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame('dir-session', $result[0]['session_id']);
        $this->assertSame('codex', $result[0]['app']);
    }

    public function testScanMultipleAppsSortedByLastActive(): void
    {
        // Create sessions for multiple apps
        $claudeDir = $this->tmpHome . '/.claude/projects/proj/sessions';
        mkdir($claudeDir, 0755, true);
        file_put_contents($claudeDir . '/old-session.jsonl', json_encode(['title' => 'Old']) . "\n");
        // Set older mtime
        touch($claudeDir . '/old-session.jsonl', time() - 3600);

        $codexDir = $this->tmpHome . '/.codex/sessions';
        mkdir($codexDir, 0755, true);
        file_put_contents($codexDir . '/new-session.json', json_encode(['title' => 'New']));
        touch($codexDir . '/new-session.json', time());

        $result = $this->service->scan();
        $this->assertCount(2, $result);
        // Most recent first
        $this->assertSame('new-session', $result[0]['session_id']);
        $this->assertSame('old-session', $result[1]['session_id']);
    }

    public function testScanClaudeWithLongTitle(): void
    {
        $sessionsDir = $this->tmpHome . '/.claude/projects/proj';
        mkdir($sessionsDir, 0755, true);

        $longTitle = str_repeat('x', 200);
        file_put_contents($sessionsDir . '/long-title.jsonl', json_encode(['title' => $longTitle]) . "\n");

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame(103, strlen($result[0]['title'])); // 100 chars + '...'
    }

    public function testScanSkipsDotFiles(): void
    {
        $sessionsDir = $this->tmpHome . '/.codex/sessions';
        mkdir($sessionsDir, 0755, true);
        file_put_contents($sessionsDir . '/.hidden.json', '{}');
        file_put_contents($sessionsDir . '/visible.json', '{}');

        $result = $this->service->scan();
        $this->assertCount(1, $result);
        $this->assertSame('visible', $result[0]['session_id']);
    }

    public function testGetResumeCommand(): void
    {
        $this->assertSame('claude --resume sess-1', $this->service->getResumeCommand('sess-1', 'claude'));
        $this->assertSame('codex --resume sess-1', $this->service->getResumeCommand('sess-1', 'codex'));
        $this->assertSame('gemini', $this->service->getResumeCommand('sess-1', 'gemini'));
        $this->assertSame('opencode', $this->service->getResumeCommand('sess-1', 'opencode'));
        $this->assertSame('', $this->service->getResumeCommand('sess-1', 'unknown'));
    }

    public function testScanClaudeNestedProjectDirs(): void
    {
        // Claude stores sessions inside project dirs, test with nested structure
        $dir1 = $this->tmpHome . '/.claude/projects/proj-a/sessions';
        $dir2 = $this->tmpHome . '/.claude/projects/proj-b';
        mkdir($dir1, 0755, true);
        mkdir($dir2, 0755, true);

        file_put_contents($dir1 . '/sess-a.jsonl', json_encode(['title' => 'A']) . "\n");
        file_put_contents($dir2 . '/sess-b.jsonl', json_encode(['title' => 'B']) . "\n");

        $result = $this->service->scan();
        $this->assertCount(2, $result);
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
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
