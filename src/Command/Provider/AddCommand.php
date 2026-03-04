<?php

declare(strict_types=1);

namespace CcSwitch\Command\Provider;

use CcSwitch\App;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Model\Provider;
use CcSwitch\Service\ProviderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AddCommand extends Command
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('provider:add')
            ->setDescription('Add a provider from a preset or custom configuration')
            ->addArgument('app', InputArgument::REQUIRED, 'Application type (claude, codex, gemini, opencode, openclaw)')
            ->addOption('preset', 'p', InputOption::VALUE_REQUIRED, 'Preset index number (use provider:list --presets to see available)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Custom provider name')
            ->addOption('api-key', 'k', InputOption::VALUE_REQUIRED, 'API key')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL override')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'JSON settings_config string');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appName = $input->getArgument('app');
        $appType = AppType::tryFrom($appName);
        if ($appType === null) {
            $output->writeln("<error>Unknown app type: {$appName}</error>");
            return Command::FAILURE;
        }

        $service = new ProviderService(new ProviderRepository($this->app->getMedoo()));
        $presetIndex = $input->getOption('preset');

        if ($presetIndex !== null) {
            return $this->addFromPreset($service, $appType, (int) $presetIndex, $input, $output);
        }

        return $this->addCustom($service, $appType, $input, $output);
    }

    private function addFromPreset(
        ProviderService $service,
        AppType $appType,
        int $index,
        InputInterface $input,
        OutputInterface $output
    ): int {
        $presets = ProviderService::loadPresets($appType);
        if (!isset($presets[$index])) {
            $output->writeln("<error>Invalid preset index: {$index}</error>");
            $output->writeln('Use <info>provider:list --presets</info> to see available presets.');
            return Command::FAILURE;
        }

        $preset = $presets[$index];
        $overrides = [];

        $apiKey = $input->getOption('api-key');
        if ($apiKey !== null) {
            $overrides['apiKey'] = $apiKey;
        }

        $baseUrl = $input->getOption('base-url');
        if ($baseUrl !== null) {
            $overrides['baseUrl'] = $baseUrl;
            $overrides['baseURL'] = $baseUrl;
        }

        $customName = $input->getOption('name');
        if ($customName !== null) {
            $preset['name'] = $customName;
        }

        $provider = $service->addFromPreset($appType, $preset, $overrides);

        $output->writeln("<info>Provider added successfully.</info>");
        $output->writeln("  Name: {$provider->name}");
        $output->writeln("  ID:   {$provider->id}");

        return Command::SUCCESS;
    }

    private function addCustom(
        ProviderService $service,
        AppType $appType,
        InputInterface $input,
        OutputInterface $output
    ): int {
        $name = $input->getOption('name');
        $configJson = $input->getOption('config');

        if ($name === null || $configJson === null) {
            $output->writeln('<error>For custom providers, --name and --config are required.</error>');
            $output->writeln('Or use --preset to add from a preset template.');
            return Command::FAILURE;
        }

        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            $output->writeln('<error>Invalid JSON for --config</error>');
            return Command::FAILURE;
        }

        $provider = new Provider();
        $provider->app_type = $appType->value;
        $provider->name = $name;
        $provider->settings_config = json_encode($config, JSON_UNESCAPED_SLASHES);
        $provider->created_at = time();

        $service->add($appType, $provider);

        $output->writeln("<info>Provider added successfully.</info>");
        $output->writeln("  Name: {$provider->name}");
        $output->writeln("  ID:   {$provider->id}");

        return Command::SUCCESS;
    }
}
