<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\UniversalProviderRepository;
use Ramsey\Uuid\Uuid;

class UniversalProviderController
{
    private UniversalProviderRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new UniversalProviderRepository($this->app->getMedoo());
    }

    public function list(): array
    {
        return $this->repo->list();
    }

    public function add(array $vars, array $body): array
    {
        $data = [
            'id' => $body['id'] ?? Uuid::uuid4()->toString(),
            'name' => $body['name'] ?? '',
            'settings_config' => $body['settings_config'] ?? '{}',
            'category' => $body['category'] ?? null,
            'notes' => $body['notes'] ?? null,
            'meta' => $body['meta'] ?? '{}',
        ];
        $this->repo->insert($data);
        return ['status' => 201, 'body' => $data];
    }

    public function update(array $vars, array $body): array
    {
        $existing = $this->repo->get($vars['id']);
        if ($existing === null) {
            return ['status' => 404, 'body' => ['error' => 'Universal provider not found']];
        }
        $allowed = ['name', 'settings_config', 'category', 'notes', 'meta'];
        $data = array_intersect_key($body, array_flip($allowed));
        if (!empty($data)) {
            $this->repo->update($vars['id'], $data);
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function delete(array $vars): array
    {
        $this->repo->delete($vars['id']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
