<?php

declare(strict_types=1);

namespace CcSwitch\DeepLink;

use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Service\PromptService;

/**
 * Import parsed prompt deep link data into the database.
 */
class PromptImporter
{
    private PromptService $promptService;

    public function __construct(
        private readonly PromptRepository $repo,
    ) {
        $this->promptService = new PromptService($this->repo);
    }

    /**
     * Import a prompt from parsed deep link data.
     *
     * @param array{app: string, name: string, content: string, description?: string|null, enabled?: bool} $data
     * @return string The created prompt ID
     */
    public function import(array $data): string
    {
        $prompt = $this->promptService->add([
            'app_type' => $data['app'],
            'name' => $data['name'],
            'content' => $data['content'],
            'description' => $data['description'] ?? null,
            'enabled' => ($data['enabled'] ?? false) ? 1 : 0,
        ]);

        return $prompt->id;
    }
}
