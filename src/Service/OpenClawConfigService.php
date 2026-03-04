<?php

declare(strict_types=1);

namespace CcSwitch\Service;

class OpenClawConfigService
{
    private OmoService $omoService;

    public function __construct()
    {
        $this->omoService = new OmoService();
    }

    private function getConfigPath(): string
    {
        return (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) . '/.openclaw/config.json';
    }

    private function readConfig(): array
    {
        $path = $this->getConfigPath();
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        $json = $this->omoService->stripJsonComments($content);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function writeConfig(array $data): void
    {
        $path = $this->getConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function getDefaultModel(): array
    {
        $config = $this->readConfig();
        return $config['agents']['defaults']['model'] ?? [];
    }

    public function setDefaultModel(array $model): void
    {
        $config = $this->readConfig();
        if (!isset($config['agents'])) {
            $config['agents'] = [];
        }
        if (!isset($config['agents']['defaults'])) {
            $config['agents']['defaults'] = [];
        }
        $config['agents']['defaults']['model'] = $model;
        $this->writeConfig($config);
    }

    public function getModelCatalog(): array
    {
        $config = $this->readConfig();
        return $config['modelCatalog'] ?? [];
    }

    public function setModelCatalog(array $catalog): void
    {
        $config = $this->readConfig();
        $config['modelCatalog'] = $catalog;
        $this->writeConfig($config);
    }

    public function getAgentsDefaults(): array
    {
        $config = $this->readConfig();
        return $config['agents']['defaults'] ?? [];
    }

    public function setAgentsDefaults(array $defaults): void
    {
        $config = $this->readConfig();
        if (!isset($config['agents'])) {
            $config['agents'] = [];
        }
        $config['agents']['defaults'] = $defaults;
        $this->writeConfig($config);
    }
}
