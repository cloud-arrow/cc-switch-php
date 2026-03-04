<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SkillRepoRepository;
use CcSwitch\Database\Repository\SkillRepository;
use CcSwitch\Service\SkillRepoService;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class SkillRepoServiceTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private SkillRepoRepository $repoRepo;
    private SkillRepository $skillRepo;
    private SkillRepoService $service;
    private string $tmpHome;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'cc-switch-skillrepo-') . '.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->migrate();

        $this->medoo = new Medoo(['type' => 'sqlite', 'database' => $dbPath]);
        $this->repoRepo = new SkillRepoRepository($this->medoo);
        $this->skillRepo = new SkillRepository($this->medoo);
        $this->service = new SkillRepoService($this->repoRepo, $this->skillRepo);

        $this->tmpHome = sys_get_temp_dir() . '/cc-switch-skillrepo-' . getmypid() . '-' . hrtime(true);
        mkdir($this->tmpHome, 0755, true);

        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->tmpHome);
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

    public function testListReposReturnsDefaultRepos(): void
    {
        $repos = $this->service->listRepos();
        $this->assertIsArray($repos);
        // Migration 007 inserts 2 default repos
        $this->assertGreaterThanOrEqual(2, count($repos));

        $owners = array_column($repos, 'owner');
        $this->assertContains('anthropics', $owners);
        $this->assertContains('ComposioHQ', $owners);
    }

    public function testAddRepo(): void
    {
        $this->service->addRepo('test-owner', 'test-repo', 'main');

        $repos = $this->service->listRepos();
        $names = array_column($repos, 'name');
        $this->assertContains('test-repo', $names);
    }

    public function testAddRepoDuplicateThrows(): void
    {
        $this->service->addRepo('test-owner', 'test-repo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->service->addRepo('test-owner', 'test-repo');
    }

    public function testRemoveRepo(): void
    {
        $this->service->addRepo('test-owner', 'test-repo');
        $this->service->removeRepo('test-owner', 'test-repo');

        $repos = $this->service->listRepos();
        $keys = array_map(fn($r) => $r['owner'] . '/' . $r['name'], $repos);
        $this->assertNotContains('test-owner/test-repo', $keys);
    }

    public function testScanUnmanagedFindsLocalSkills(): void
    {
        // Create skill directories
        $claudeSkillsDir = $this->tmpHome . '/.claude/commands';
        mkdir($claudeSkillsDir, 0755, true);
        mkdir($claudeSkillsDir . '/my-skill', 0755, true);

        // Re-create service with updated HOME
        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);

        $unmanaged = $service->scanUnmanaged();
        $this->assertCount(1, $unmanaged);
        $this->assertSame('claude', $unmanaged[0]['app']);
        $this->assertSame('my-skill', $unmanaged[0]['directory']);
    }

    public function testScanUnmanagedReturnsEmptyWhenNoDirs(): void
    {
        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);
        $unmanaged = $service->scanUnmanaged();
        $this->assertSame([], $unmanaged);
    }

    public function testImportFromApps(): void
    {
        // Create skill directories
        $claudeSkillsDir = $this->tmpHome . '/.claude/commands';
        mkdir($claudeSkillsDir, 0755, true);
        mkdir($claudeSkillsDir . '/skill-a', 0755, true);
        mkdir($claudeSkillsDir . '/skill-b', 0755, true);

        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);

        $result = $service->importFromApps();
        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['scanned']);

        // Verify skills were imported to DB
        $skillA = $this->skillRepo->get('local:claude:skill-a');
        $this->assertNotNull($skillA);
        $this->assertSame('skill-a', $skillA['name']);
        $this->assertSame(1, (int) $skillA['enabled_claude']);
        $this->assertSame(0, (int) $skillA['enabled_codex']);
    }

    public function testImportFromAppsSkipsDuplicates(): void
    {
        $claudeSkillsDir = $this->tmpHome . '/.claude/commands';
        mkdir($claudeSkillsDir, 0755, true);
        mkdir($claudeSkillsDir . '/skill-a', 0755, true);

        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);

        $result1 = $service->importFromApps();
        $this->assertSame(1, $result1['imported']);

        $result2 = $service->importFromApps();
        $this->assertSame(0, $result2['imported']);
    }

    public function testImportFromMultipleApps(): void
    {
        // Create skill directories for multiple apps
        $claudeDir = $this->tmpHome . '/.claude/commands';
        $codexDir = $this->tmpHome . '/.codex/skills';
        $geminiDir = $this->tmpHome . '/.gemini/skills';
        $opencodeDir = $this->tmpHome . '/.config/opencode/skills';

        mkdir($claudeDir . '/claude-skill', 0755, true);
        mkdir($codexDir . '/codex-skill', 0755, true);
        mkdir($geminiDir . '/gemini-skill', 0755, true);
        mkdir($opencodeDir . '/opencode-skill', 0755, true);

        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);

        $result = $service->importFromApps();
        $this->assertSame(4, $result['imported']);
        $this->assertSame(4, $result['scanned']);

        // Verify correct app flags
        $claudeSkill = $this->skillRepo->get('local:claude:claude-skill');
        $this->assertSame(1, (int) $claudeSkill['enabled_claude']);
        $this->assertSame(0, (int) $claudeSkill['enabled_codex']);

        $codexSkill = $this->skillRepo->get('local:codex:codex-skill');
        $this->assertSame(0, (int) $codexSkill['enabled_claude']);
        $this->assertSame(1, (int) $codexSkill['enabled_codex']);

        $geminiSkill = $this->skillRepo->get('local:gemini:gemini-skill');
        $this->assertSame(1, (int) $geminiSkill['enabled_gemini']);

        $opcodeSkill = $this->skillRepo->get('local:opencode:opencode-skill');
        $this->assertSame(1, (int) $opcodeSkill['enabled_opencode']);
    }

    public function testScanUnmanagedSkipsFiles(): void
    {
        // Files (not directories) should be skipped
        $claudeDir = $this->tmpHome . '/.claude/commands';
        mkdir($claudeDir, 0755, true);
        file_put_contents($claudeDir . '/not-a-skill.txt', 'just a file');

        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);
        $unmanaged = $service->scanUnmanaged();
        $this->assertSame([], $unmanaged);
    }

    public function testScanUnmanagedExcludesManagedSkills(): void
    {
        // First import a skill
        $claudeDir = $this->tmpHome . '/.claude/commands';
        mkdir($claudeDir . '/managed-skill', 0755, true);
        mkdir($claudeDir . '/unmanaged-skill', 0755, true);

        $service = new SkillRepoService($this->repoRepo, $this->skillRepo);

        // Insert a managed skill record
        $this->skillRepo->insert([
            'id' => 'some-id',
            'name' => 'managed-skill',
            'description' => 'A managed skill',
            'directory' => 'managed-skill',
            'repo_owner' => 'test',
            'repo_name' => 'repo',
            'repo_branch' => 'main',
            'readme_url' => '',
            'enabled_claude' => 1,
            'enabled_codex' => 0,
            'enabled_gemini' => 0,
            'enabled_opencode' => 0,
            'installed_at' => time(),
        ]);

        $unmanaged = $service->scanUnmanaged();
        $this->assertCount(1, $unmanaged);
        $this->assertSame('unmanaged-skill', $unmanaged[0]['directory']);
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
