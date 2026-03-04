<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Service\PromptService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class PromptServiceTest extends TestCase
{
    private PromptService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc-switch-prompt-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpDir, 0755, true);

        $dbPath = $this->tmpDir . '/test.db';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $repo = new PromptRepository($medoo);
        $this->service = new PromptService($repo);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    public function testListReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->list('claude'));
    }

    public function testAddAndList(): void
    {
        $prompt = $this->service->add([
            'app_type' => 'claude',
            'name' => 'Test Prompt',
            'content' => 'You are a helpful assistant.',
            'description' => 'A test prompt',
        ]);

        $this->assertNotEmpty($prompt->id);
        $this->assertSame('claude', $prompt->app_type);
        $this->assertSame('Test Prompt', $prompt->name);

        $list = $this->service->list('claude');
        $this->assertCount(1, $list);
        $this->assertSame('Test Prompt', $list[0]->name);
    }

    public function testGet(): void
    {
        $prompt = $this->service->add([
            'app_type' => 'codex',
            'name' => 'Codex Prompt',
            'content' => 'content here',
        ]);

        $result = $this->service->get($prompt->id, 'codex');
        $this->assertNotNull($result);
        $this->assertSame('Codex Prompt', $result->name);

        // Wrong app type returns null
        $this->assertNull($this->service->get($prompt->id, 'claude'));
    }

    public function testUpdate(): void
    {
        $prompt = $this->service->add([
            'app_type' => 'claude',
            'name' => 'Original',
            'content' => 'original content',
        ]);

        $this->service->update($prompt->id, 'claude', [
            'name' => 'Updated',
            'content' => 'updated content',
        ]);

        $result = $this->service->get($prompt->id, 'claude');
        $this->assertSame('Updated', $result->name);
        $this->assertSame('updated content', $result->content);
    }

    public function testDelete(): void
    {
        $prompt = $this->service->add([
            'app_type' => 'claude',
            'name' => 'Delete Me',
            'content' => 'content',
        ]);

        $this->assertNotNull($this->service->get($prompt->id, 'claude'));

        $this->service->delete($prompt->id, 'claude');
        $this->assertNull($this->service->get($prompt->id, 'claude'));
    }

    public function testListFiltersByApp(): void
    {
        $this->service->add(['app_type' => 'claude', 'name' => 'Claude Prompt', 'content' => 'c']);
        $this->service->add(['app_type' => 'codex', 'name' => 'Codex Prompt', 'content' => 'c']);

        $claude = $this->service->list('claude');
        $codex = $this->service->list('codex');
        $this->assertCount(1, $claude);
        $this->assertCount(1, $codex);
        $this->assertSame('Claude Prompt', $claude[0]->name);
        $this->assertSame('Codex Prompt', $codex[0]->name);
    }

    public function testAddWithCustomId(): void
    {
        $prompt = $this->service->add([
            'id' => 'custom-id',
            'app_type' => 'claude',
            'name' => 'Custom ID Prompt',
            'content' => 'content',
        ]);

        $this->assertSame('custom-id', $prompt->id);
    }

    public function testAddSetsTimestamps(): void
    {
        $prompt = $this->service->add([
            'app_type' => 'claude',
            'name' => 'Timestamped',
            'content' => 'content',
        ]);

        $this->assertNotNull($prompt->created_at);
        $this->assertNotNull($prompt->updated_at);
        $this->assertSame($prompt->created_at, $prompt->updated_at);
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
