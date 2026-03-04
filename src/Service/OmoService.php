<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Util\AtomicFile;

/**
 * OMO (Oh My OpenCode) configuration service.
 *
 * Manages JSONC configuration files for OpenCode's OMO standard.
 * Supports two variants: Standard and Slim.
 */
class OmoService
{
    /** @var array<string, array{filename: string, category: string, hasCategories: bool}> */
    private const VARIANTS = [
        'standard' => [
            'filename' => 'oh-my-opencode.jsonc',
            'category' => 'omo',
            'hasCategories' => true,
        ],
        'slim' => [
            'filename' => 'oh-my-opencode-slim.jsonc',
            'category' => 'omo-slim',
            'hasCategories' => false,
        ],
    ];

    /**
     * Strip // and block comments from JSONC, respecting quoted strings.
     */
    public function stripJsonComments(string $jsonc): string
    {
        $len = strlen($jsonc);
        $result = '';
        $i = 0;
        $inString = false;
        $escape = false;

        while ($i < $len) {
            $c = $jsonc[$i];

            if ($inString) {
                $result .= $c;
                $i++;
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
            } elseif ($c === '"') {
                $inString = true;
                $result .= $c;
                $i++;
            } elseif ($c === '/' && $i + 1 < $len && $jsonc[$i + 1] === '/') {
                // Line comment — skip until newline
                $i += 2;
                while ($i < $len && $jsonc[$i] !== "\n") {
                    $i++;
                }
            } elseif ($c === '/' && $i + 1 < $len && $jsonc[$i + 1] === '*') {
                // Block comment — skip until */
                $i += 2;
                while ($i < $len) {
                    if ($jsonc[$i] === '*' && $i + 1 < $len && $jsonc[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
            } else {
                $result .= $c;
                $i++;
            }
        }

        return $result;
    }

    /**
     * Get file path for an OMO variant.
     */
    public function getFilePath(string $variant): string
    {
        $info = $this->resolveVariant($variant);
        return $this->getOpenClawDir() . '/' . $info['filename'];
    }

    /**
     * Import configuration from the JSONC file on disk.
     *
     * @return array{agents: mixed, categories: mixed, otherFields: mixed, filePath: string, lastModified: ?string}
     */
    public function importFromFile(string $variant): array
    {
        $info = $this->resolveVariant($variant);
        $path = $this->resolveLocalConfigPath($variant);

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read OMO config: {$path}");
        }

        $json = $this->stripJsonComments($content);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Failed to parse OMO config as JSON object');
        }

        $agents = $data['agents'] ?? null;
        $categories = $info['hasCategories'] ? ($data['categories'] ?? null) : null;

        // Collect other fields (everything except agents/categories)
        $otherFields = [];
        foreach ($data as $key => $value) {
            if ($key !== 'agents' && $key !== 'categories') {
                $otherFields[$key] = $value;
            }
        }

        $lastModified = null;
        if (file_exists($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                $lastModified = date('c', $mtime);
            }
        }

        return [
            'agents' => $agents,
            'categories' => $categories,
            'otherFields' => empty($otherFields) ? null : $otherFields,
            'filePath' => $path,
            'lastModified' => $lastModified,
        ];
    }

    /**
     * Export configuration data to the JSONC file.
     *
     * @param array{agents?: mixed, categories?: mixed, otherFields?: mixed} $data
     */
    public function exportToFile(string $variant, array $data): void
    {
        $info = $this->resolveVariant($variant);
        $path = $this->getFilePath($variant);

        $result = [];

        // Merge otherFields first (they go at top level)
        if (isset($data['otherFields']) && is_array($data['otherFields'])) {
            foreach ($data['otherFields'] as $k => $v) {
                $result[$k] = $v;
            }
        }

        if (isset($data['agents'])) {
            $result['agents'] = $data['agents'];
        }

        if ($info['hasCategories'] && isset($data['categories'])) {
            $result['categories'] = $data['categories'];
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        AtomicFile::writeJson($path, $result);
    }

    /**
     * Resolve the actual path to the config file, trying .jsonc then .json fallback.
     */
    private function resolveLocalConfigPath(string $variant): string
    {
        $info = $this->resolveVariant($variant);
        $base = $this->getOpenClawDir();

        $jsonc = $base . '/' . $info['filename'];
        if (file_exists($jsonc)) {
            return $jsonc;
        }

        // Try .json fallback
        $json = preg_replace('/\.jsonc$/', '.json', $jsonc);
        if ($json !== null && file_exists($json)) {
            return $json;
        }

        throw new \RuntimeException("OMO config file not found: {$jsonc}");
    }

    /**
     * @return array{filename: string, category: string, hasCategories: bool}
     */
    private function resolveVariant(string $variant): array
    {
        if (!isset(self::VARIANTS[$variant])) {
            throw new \InvalidArgumentException(
                "Invalid OMO variant: {$variant}. Must be 'standard' or 'slim'."
            );
        }
        return self::VARIANTS[$variant];
    }

    private function getOpenClawDir(): string
    {
        return (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) . '/.openclaw';
    }
}
