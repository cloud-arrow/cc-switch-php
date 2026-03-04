<?php

declare(strict_types=1);

namespace CcSwitch\DeepLink;

/**
 * Deep link URL parser for ccswitch:// protocol.
 *
 * Parses ccswitch://v1/import?resource={type}&... URLs into structured data.
 * Supports: provider, mcp, prompt, skill resource types.
 */
class DeepLinkParser
{
    private const VALID_APPS = ['claude', 'codex', 'gemini', 'opencode', 'openclaw'];

    /**
     * Parse a ccswitch:// URL into structured data.
     *
     * @return array{type: string, data: array<string, mixed>}
     * @throws \InvalidArgumentException on invalid URL
     */
    public function parse(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException("Invalid deep link URL");
        }

        // Validate scheme
        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'ccswitch') {
            throw new \InvalidArgumentException("Invalid scheme: expected 'ccswitch', got '{$scheme}'");
        }

        // Extract version from host
        $version = $parts['host'] ?? '';
        if ($version !== 'v1') {
            throw new \InvalidArgumentException("Unsupported protocol version: {$version}");
        }

        // Extract path
        $path = $parts['path'] ?? '';
        if ($path !== '/import') {
            throw new \InvalidArgumentException("Invalid path: expected '/import', got '{$path}'");
        }

        // Parse query parameters
        $params = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
        }

        // Extract resource type
        $resource = $params['resource'] ?? '';
        if ($resource === '') {
            throw new \InvalidArgumentException("Missing 'resource' parameter");
        }

        return match ($resource) {
            'provider' => ['type' => 'provider', 'data' => $this->parseProvider($params)],
            'mcp' => ['type' => 'mcp', 'data' => $this->parseMcp($params)],
            'prompt' => ['type' => 'prompt', 'data' => $this->parsePrompt($params)],
            'skill' => ['type' => 'skill', 'data' => $this->parseSkill($params)],
            default => throw new \InvalidArgumentException("Unsupported resource type: {$resource}"),
        };
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function parseProvider(array $params): array
    {
        $app = $params['app'] ?? '';
        if (!in_array($app, self::VALID_APPS, true)) {
            throw new \InvalidArgumentException("Invalid app type: {$app}");
        }

        $name = $params['name'] ?? '';
        if ($name === '') {
            throw new \InvalidArgumentException("Missing 'name' parameter for provider");
        }

        $data = [
            'app' => $app,
            'name' => $name,
            'homepage' => $params['homepage'] ?? null,
            'endpoint' => $params['endpoint'] ?? null,
            'apiKey' => $params['apiKey'] ?? null,
            'icon' => isset($params['icon']) ? strtolower(trim($params['icon'])) : null,
            'model' => $params['model'] ?? null,
            'notes' => $params['notes'] ?? null,
            'enabled' => isset($params['enabled']) ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN) : false,
        ];

        // Decode config if provided (base64)
        if (isset($params['config'])) {
            $data['config'] = $this->decodeBase64($params['config'], 'config');
        }
        if (isset($params['configFormat'])) {
            $data['configFormat'] = $params['configFormat'];
        }

        // Usage script fields
        if (isset($params['usageScript'])) {
            $data['usageScript'] = $this->decodeBase64($params['usageScript'], 'usageScript');
        }
        if (isset($params['usageEnabled'])) {
            $data['usageEnabled'] = filter_var($params['usageEnabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($params['usageApiKey'])) {
            $data['usageApiKey'] = $params['usageApiKey'];
        }
        if (isset($params['usageBaseUrl'])) {
            $data['usageBaseUrl'] = $params['usageBaseUrl'];
        }

        return $data;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function parseMcp(array $params): array
    {
        $apps = $params['apps'] ?? '';
        if ($apps === '') {
            throw new \InvalidArgumentException("Missing 'apps' parameter for MCP");
        }

        // Validate each app
        foreach (explode(',', $apps) as $app) {
            $app = trim($app);
            if (!in_array($app, self::VALID_APPS, true)) {
                throw new \InvalidArgumentException("Invalid app in 'apps': {$app}");
            }
        }

        $configB64 = $params['config'] ?? '';
        if ($configB64 === '') {
            throw new \InvalidArgumentException("Missing 'config' parameter for MCP");
        }

        $configJson = $this->decodeBase64($configB64, 'config');
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            throw new \InvalidArgumentException("Invalid JSON in MCP config");
        }

        return [
            'apps' => $apps,
            'config' => $config,
            'enabled' => isset($params['enabled']) ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN) : true,
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function parsePrompt(array $params): array
    {
        $app = $params['app'] ?? '';
        if (!in_array($app, self::VALID_APPS, true)) {
            throw new \InvalidArgumentException("Invalid app type for prompt: {$app}");
        }

        $name = $params['name'] ?? '';
        if ($name === '') {
            throw new \InvalidArgumentException("Missing 'name' parameter for prompt");
        }

        $contentB64 = $params['content'] ?? '';
        if ($contentB64 === '') {
            throw new \InvalidArgumentException("Missing 'content' parameter for prompt");
        }

        $content = $this->decodeBase64($contentB64, 'content');

        return [
            'app' => $app,
            'name' => $name,
            'content' => $content,
            'description' => $params['description'] ?? null,
            'enabled' => isset($params['enabled']) ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN) : false,
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function parseSkill(array $params): array
    {
        $repo = $params['repo'] ?? '';
        if ($repo === '' || substr_count($repo, '/') !== 1) {
            throw new \InvalidArgumentException("Invalid repo format: expected 'owner/name', got '{$repo}'");
        }

        return [
            'repo' => $repo,
            'directory' => $params['directory'] ?? null,
            'branch' => $params['branch'] ?? 'main',
        ];
    }

    /**
     * Decode a base64-encoded parameter.
     *
     * @throws \InvalidArgumentException on invalid base64
     */
    private function decodeBase64(string $value, string $paramName): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException("Invalid base64 in '{$paramName}' parameter");
        }
        return $decoded;
    }
}
