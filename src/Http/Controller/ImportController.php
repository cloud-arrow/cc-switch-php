<?php

declare(strict_types=1);

namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\PromptRepository;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Database\Repository\SkillRepository;
use CcSwitch\DeepLink\DeepLinkParser;
use CcSwitch\DeepLink\McpImporter;
use CcSwitch\DeepLink\PromptImporter;
use CcSwitch\DeepLink\ProviderImporter;
use CcSwitch\DeepLink\SkillImporter;

class ImportController
{
    public function __construct(private readonly App $app)
    {
    }

    public function import(array $vars, array $body): array
    {
        $url = $body['url'] ?? '';
        if ($url === '') {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: url']];
        }

        $parser = new DeepLinkParser();

        try {
            $parsed = $parser->parse($url);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }

        $medoo = $this->app->getMedoo();

        $result = match ($parsed['type']) {
            'provider' => $this->importProvider($parsed['data'], $medoo),
            'mcp' => $this->importMcp($parsed['data'], $medoo),
            'prompt' => $this->importPrompt($parsed['data'], $medoo),
            'skill' => $this->importSkill($parsed['data'], $medoo),
            default => ['status' => 400, 'body' => ['error' => "Unsupported resource type: {$parsed['type']}"]],
        };

        return $result;
    }

    private function importProvider(array $data, \Medoo\Medoo $medoo): array
    {
        $importer = new ProviderImporter(new ProviderRepository($medoo));
        $id = $importer->import($data);
        return ['status' => 201, 'body' => ['type' => 'provider', 'id' => $id]];
    }

    private function importMcp(array $data, \Medoo\Medoo $medoo): array
    {
        $importer = new McpImporter(
            new McpRepository($medoo),
            new SettingsRepository($medoo),
        );
        $result = $importer->import($data);
        return ['status' => 200, 'body' => array_merge(['type' => 'mcp'], $result)];
    }

    private function importPrompt(array $data, \Medoo\Medoo $medoo): array
    {
        $importer = new PromptImporter(new PromptRepository($medoo));
        $id = $importer->import($data);
        return ['status' => 201, 'body' => ['type' => 'prompt', 'id' => $id]];
    }

    private function importSkill(array $data, \Medoo\Medoo $medoo): array
    {
        $importer = new SkillImporter(
            new SkillRepository($medoo),
            new SettingsRepository($medoo),
        );
        $id = $importer->import($data);
        return ['status' => 201, 'body' => ['type' => 'skill', 'id' => $id]];
    }
}
