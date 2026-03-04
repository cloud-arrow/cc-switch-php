<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Model\Prompt;
use Ramsey\Uuid\Uuid;

/**
 * Prompt management service.
 *
 * Handles CRUD operations for prompts scoped by app type.
 */
class PromptService
{
    public function __construct(
        private readonly PromptRepository $repo,
    ) {
    }

    /**
     * @return Prompt[]
     */
    public function list(string $app): array
    {
        $rows = $this->repo->list($app);
        return array_map([Prompt::class, 'fromRow'], $rows);
    }

    public function get(string $id, string $app): ?Prompt
    {
        $row = $this->repo->get($id, $app);
        return $row ? Prompt::fromRow($row) : null;
    }

    /**
     * Add a new prompt.
     *
     * @param array{app_type: string, name: string, content: string, description?: string, enabled?: int} $data
     */
    public function add(array $data): Prompt
    {
        $now = time();

        $row = [
            'id' => $data['id'] ?? Uuid::uuid4()->toString(),
            'app_type' => $data['app_type'],
            'name' => $data['name'],
            'content' => $data['content'],
            'description' => $data['description'] ?? null,
            'enabled' => $data['enabled'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->repo->insert($row);

        return Prompt::fromRow($row);
    }

    /**
     * Update an existing prompt.
     *
     * @param array<string, mixed> $data Fields to update
     */
    public function update(string $id, string $app, array $data): void
    {
        $data['updated_at'] = time();
        $this->repo->update($id, $app, $data);
    }

    public function delete(string $id, string $app): void
    {
        $this->repo->delete($id, $app);
    }
}
