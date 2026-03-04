<?php

declare(strict_types=1);

namespace CcSwitch\Service;

use CcSwitch\Database\Repository\SettingsRepository;

class LiveTakeoverService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepo,
    ) {
    }

    public function backup(string $appType): void
    {
        $configPath = $this->getConfigPath($appType);
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Config file not found for {$appType}: {$configPath}");
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read config file: {$configPath}");
        }

        $this->settingsRepo->set("live_backup_{$appType}", $content);
        $this->settingsRepo->set("live_backup_{$appType}_at", (string) time());
    }

    public function takeover(string $appType, string $proxyHost = '127.0.0.1', int $proxyPort = 15721): void
    {
        $configPath = $this->getConfigPath($appType);

        // Ensure config file exists before backup
        if (file_exists($configPath)) {
            $this->backup($appType);
        }

        // Modify config to point to proxy
        match ($appType) {
            'claude' => $this->takeoverClaude($configPath, $proxyHost, $proxyPort),
            'codex' => $this->takeoverCodex($configPath, $proxyHost, $proxyPort),
            'gemini' => $this->takeoverGemini($configPath, $proxyHost, $proxyPort),
            'opencode' => $this->takeoverOpenCode($configPath, $proxyHost, $proxyPort),
            'openclaw' => $this->takeoverOpenClaw($configPath, $proxyHost, $proxyPort),
            default => throw new \InvalidArgumentException("Unknown app type: {$appType}"),
        };

        $this->settingsRepo->set("live_takeover_{$appType}", '1');
    }

    public function restore(string $appType): void
    {
        $backup = $this->settingsRepo->get("live_backup_{$appType}");
        if ($backup === null) {
            throw new \RuntimeException("No backup found for {$appType}");
        }

        $configPath = $this->getConfigPath($appType);
        $dir = dirname($configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $backup);

        $this->settingsRepo->delete("live_takeover_{$appType}");
    }

    public function isActive(string $appType): bool
    {
        return $this->settingsRepo->get("live_takeover_{$appType}") === '1';
    }

    public function getBackupStatus(): array
    {
        $result = [];
        foreach (['claude', 'codex', 'gemini', 'opencode', 'openclaw'] as $app) {
            $result[$app] = [
                'active' => $this->isActive($app),
                'has_backup' => $this->settingsRepo->get("live_backup_{$app}") !== null,
                'backup_at' => $this->settingsRepo->get("live_backup_{$app}_at"),
            ];
        }
        return $result;
    }

    public function getConfigPath(string $appType): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        return match ($appType) {
            'claude' => $home . '/.claude/settings.json',
            'codex' => $home . '/.codex/config.json',
            'gemini' => $home . '/.gemini/.env',
            'opencode' => $home . '/.config/opencode/config.json',
            'openclaw' => $home . '/.openclaw/config.json',
            default => throw new \InvalidArgumentException("Unknown app type: {$appType}"),
        };
    }

    private function takeoverClaude(string $configPath, string $host, int $port): void
    {
        $config = $this->readJsonConfig($configPath);
        if (!isset($config['env'])) {
            $config['env'] = [];
        }
        $config['env']['ANTHROPIC_BASE_URL'] = "http://{$host}:{$port}";
        $this->writeJsonConfig($configPath, $config);
    }

    private function takeoverCodex(string $configPath, string $host, int $port): void
    {
        $config = $this->readJsonConfig($configPath);
        if (!isset($config['env'])) {
            $config['env'] = [];
        }
        $config['env']['OPENAI_BASE_URL'] = "http://{$host}:{$port}/v1";
        $this->writeJsonConfig($configPath, $config);
    }

    private function takeoverGemini(string $configPath, string $host, int $port): void
    {
        $lines = [];
        $found = false;

        if (file_exists($configPath)) {
            $content = file_get_contents($configPath);
            if ($content !== false && $content !== '') {
                $lines = explode("\n", $content);
                foreach ($lines as $i => $line) {
                    if (str_starts_with(trim($line), 'API_BASE_URL=')) {
                        $lines[$i] = "API_BASE_URL=http://{$host}:{$port}";
                        $found = true;
                        break;
                    }
                }
            }
        }

        if (!$found) {
            $lines[] = "API_BASE_URL=http://{$host}:{$port}";
        }

        $dir = dirname($configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($configPath, implode("\n", $lines));
    }

    private function takeoverOpenCode(string $configPath, string $host, int $port): void
    {
        $config = $this->readJsonConfig($configPath);
        if (!isset($config['provider'])) {
            $config['provider'] = [];
        }
        $config['provider']['cc-switch-proxy'] = [
            'name' => 'cc-switch-proxy',
            'baseUrl' => "http://{$host}:{$port}/v1",
            'type' => 'openai',
        ];
        $this->writeJsonConfig($configPath, $config);
    }

    private function takeoverOpenClaw(string $configPath, string $host, int $port): void
    {
        $config = $this->readJsonConfig($configPath);
        if (!isset($config['models'])) {
            $config['models'] = [];
        }
        if (!isset($config['models']['providers'])) {
            $config['models']['providers'] = [];
        }
        $config['models']['providers']['cc-switch-proxy'] = [
            'name' => 'cc-switch-proxy',
            'baseUrl' => "http://{$host}:{$port}",
        ];
        $this->writeJsonConfig($configPath, $config);
    }

    private function readJsonConfig(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function writeJsonConfig(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
