<?php

declare(strict_types=1);

namespace CcSwitch\DeepLink;

use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\SkillRepository;
use CcSwitch\Service\SkillService;

/**
 * Import parsed skill deep link data.
 *
 * Triggers installation of a skill from a GitHub repository.
 */
class SkillImporter
{
    private SkillService $skillService;

    public function __construct(
        private readonly SkillRepository $repo,
        private readonly SettingsRepository $settingsRepo,
    ) {
        $this->skillService = new SkillService($this->repo, $this->settingsRepo);
    }

    /**
     * Import a skill from parsed deep link data.
     *
     * @param array{repo: string, directory?: string|null, branch?: string} $data
     * @return string The installed skill ID
     */
    public function import(array $data): string
    {
        $repoParts = explode('/', $data['repo']);
        $owner = $repoParts[0];
        $repoName = $repoParts[1];
        $directory = $data['directory'] ?? $repoName;
        $branch = $data['branch'] ?? 'main';

        $skill = $this->skillService->install([
            'repo_owner' => $owner,
            'repo_name' => $repoName,
            'directory' => $directory,
            'repo_branch' => $branch,
        ]);

        return $skill->id;
    }
}
