<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\SkillRepoRepository;
use CcSwitch\Database\Repository\SkillRepository;
use CcSwitch\Service\SkillRepoService;
use CcSwitch\Service\SkillService;

class SkillController
{
    private SkillService $service;
    private SkillRepoService $repoService;

    public function __construct(private readonly App $app)
    {
        $medoo = $this->app->getMedoo();
        $skillRepo = new SkillRepository($medoo);
        $this->service = new SkillService(
            $skillRepo,
            new SettingsRepository($medoo),
        );
        $this->repoService = new SkillRepoService(
            new SkillRepoRepository($medoo),
            $skillRepo,
        );
    }

    public function list(): array
    {
        $skills = $this->service->list();
        return array_map(fn($s) => get_object_vars($s), $skills);
    }

    public function install(array $vars, array $body): array
    {
        $required = ['repo_owner', 'repo_name', 'directory'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return ['status' => 400, 'body' => ['error' => "Missing required field: {$field}"]];
            }
        }

        $skill = $this->service->install($body);
        return ['status' => 201, 'body' => get_object_vars($skill)];
    }

    public function delete(array $vars): array
    {
        $this->service->delete($vars['id']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function sync(): array
    {
        $this->service->syncToApps();
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function listRepos(): array
    {
        return $this->repoService->listRepos();
    }

    public function addRepo(array $vars, array $body): array
    {
        if (empty($body['owner']) || empty($body['name'])) {
            return ['status' => 400, 'body' => ['error' => 'owner and name required']];
        }
        try {
            $this->repoService->addRepo($body['owner'], $body['name'], $body['branch'] ?? 'main');
            return ['status' => 201, 'body' => ['ok' => true]];
        } catch (\RuntimeException $e) {
            return ['status' => 409, 'body' => ['error' => $e->getMessage()]];
        }
    }

    public function removeRepo(array $vars): array
    {
        $this->repoService->removeRepo($vars['owner'], $vars['name']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function discoverSkills(array $vars): array
    {
        return $this->repoService->discoverSkills($vars['owner'], $vars['name']);
    }

    public function scanUnmanaged(): array
    {
        return $this->repoService->scanUnmanaged();
    }

    public function importFromApps(): array
    {
        return $this->repoService->importFromApps();
    }
}
