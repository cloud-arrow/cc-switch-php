<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Service\PromptService;

class PromptController
{
    private PromptService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new PromptService(
            new PromptRepository($this->app->getMedoo()),
        );
    }

    public function list(array $vars): array
    {
        $prompts = $this->service->list($vars['app']);
        return array_map(fn($p) => get_object_vars($p), $prompts);
    }

    public function add(array $vars, array $body): array
    {
        if (empty($body['name']) || empty($body['content'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required fields: name, content']];
        }

        $body['app_type'] = $vars['app'];
        $prompt = $this->service->add($body);
        return ['status' => 201, 'body' => get_object_vars($prompt)];
    }

    public function update(array $vars, array $body): array
    {
        $existing = $this->service->get($vars['id'], $vars['app']);
        if ($existing === null) {
            return ['status' => 404, 'body' => ['error' => 'Prompt not found']];
        }

        $allowed = ['name', 'content', 'description', 'enabled'];
        $data = array_intersect_key($body, array_flip($allowed));
        if (!empty($data)) {
            $this->service->update($vars['id'], $vars['app'], $data);
        }
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function delete(array $vars): array
    {
        $this->service->delete($vars['id'], $vars['app']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
