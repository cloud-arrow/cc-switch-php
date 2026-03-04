<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Integration;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\ProviderRepository;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class ProviderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private ProviderRepository $repo;

    protected function setUp(): void
    {
        // Create a temp file for SQLite (Medoo needs a file path)
        $dbPath = tempnam(sys_get_temp_dir(), 'cc-switch-test-') . '.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->repo = new ProviderRepository($this->medoo);
    }

    private function insertProvider(string $id, string $app, string $name, int $isCurrent = 0, int $sortIndex = 0): void
    {
        $this->repo->insert([
            'id' => $id,
            'app_type' => $app,
            'name' => $name,
            'settings_config' => '{}',
            'is_current' => $isCurrent,
            'sort_index' => $sortIndex,
            'meta' => '{}',
        ]);
    }

    public function testInsertAndGet(): void
    {
        $this->insertProvider('p1', 'claude', 'Test Provider');

        $row = $this->repo->get('p1', 'claude');

        $this->assertNotNull($row);
        $this->assertSame('p1', $row['id']);
        $this->assertSame('claude', $row['app_type']);
        $this->assertSame('Test Provider', $row['name']);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->get('nonexistent', 'claude'));
    }

    public function testList(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider A', 0, 2);
        $this->insertProvider('p2', 'claude', 'Provider B', 0, 1);
        $this->insertProvider('p3', 'codex', 'Codex Provider', 0, 0);

        $claudeProviders = $this->repo->list('claude');
        $this->assertCount(2, $claudeProviders);
        // Should be sorted by sort_index ASC
        $this->assertSame('Provider B', $claudeProviders[0]['name']);
        $this->assertSame('Provider A', $claudeProviders[1]['name']);

        $codexProviders = $this->repo->list('codex');
        $this->assertCount(1, $codexProviders);
    }

    public function testListReturnsEmptyForNoProviders(): void
    {
        $this->assertSame([], $this->repo->list('claude'));
    }

    public function testUpdate(): void
    {
        $this->insertProvider('p1', 'claude', 'Old Name');

        $this->repo->update('p1', 'claude', ['name' => 'New Name', 'notes' => 'Updated']);

        $row = $this->repo->get('p1', 'claude');
        $this->assertSame('New Name', $row['name']);
        $this->assertSame('Updated', $row['notes']);
    }

    public function testDelete(): void
    {
        $this->insertProvider('p1', 'claude', 'To Delete');

        $this->repo->delete('p1', 'claude');

        $this->assertNull($this->repo->get('p1', 'claude'));
    }

    public function testGetCurrentReturnsActiveProvider(): void
    {
        $this->insertProvider('p1', 'claude', 'Not Current', 0);
        $this->insertProvider('p2', 'claude', 'Current', 1);

        $current = $this->repo->getCurrent('claude');
        $this->assertNotNull($current);
        $this->assertSame('p2', $current['id']);
    }

    public function testGetCurrentReturnsNullWhenNone(): void
    {
        $this->insertProvider('p1', 'claude', 'Not Current', 0);

        $this->assertNull($this->repo->getCurrent('claude'));
    }

    public function testClearCurrent(): void
    {
        $this->insertProvider('p1', 'claude', 'Current', 1);

        $this->repo->clearCurrent('claude');

        $this->assertNull($this->repo->getCurrent('claude'));
    }

    public function testSetCurrent(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1', 0);

        $this->repo->setCurrent('p1', 'claude');

        $current = $this->repo->getCurrent('claude');
        $this->assertSame('p1', $current['id']);
    }

    public function testSwitchToAtomically(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1', 1);
        $this->insertProvider('p2', 'claude', 'Provider 2', 0);

        $this->repo->switchTo('p2', 'claude');

        $p1 = $this->repo->get('p1', 'claude');
        $p2 = $this->repo->get('p2', 'claude');

        $this->assertSame(0, (int) $p1['is_current']);
        $this->assertSame(1, (int) $p2['is_current']);
    }

    public function testSwitchToOnlyAffectsTargetApp(): void
    {
        $this->insertProvider('p1', 'claude', 'Claude Current', 1);
        $this->insertProvider('p2', 'codex', 'Codex Current', 1);

        $this->insertProvider('p3', 'claude', 'New Claude', 0);
        $this->repo->switchTo('p3', 'claude');

        // Codex should be unaffected
        $codexCurrent = $this->repo->getCurrent('codex');
        $this->assertSame('p2', $codexCurrent['id']);
    }

    public function testGetByFailoverQueue(): void
    {
        $this->insertProvider('p1', 'claude', 'Provider 1');
        $this->insertProvider('p2', 'claude', 'Provider 2');

        // Manually mark p2 as in failover queue
        $this->medoo->update('providers', ['in_failover_queue' => 1], [
            'id' => 'p2',
            'app_type' => 'claude',
        ]);

        $queue = $this->repo->getByFailoverQueue('claude');
        $this->assertCount(1, $queue);
        $this->assertSame('p2', $queue[0]['id']);
    }
}
