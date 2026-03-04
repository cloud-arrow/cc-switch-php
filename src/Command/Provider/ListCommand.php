<?php

declare(strict_types=1);

namespace CcSwitch\Command\Provider;

use CcSwitch\App;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Service\ProviderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
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
            ->setName('provider:list')
            ->setDescription('List providers for an application')
            ->addArgument('app', InputArgument::REQUIRED, 'Application type (claude, codex, gemini, opencode, openclaw)')
            ->addOption('presets', null, InputOption::VALUE_NONE, 'Show available presets instead of saved providers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appName = $input->getArgument('app');
        $appType = AppType::tryFrom($appName);
        if ($appType === null) {
            $output->writeln("<error>Unknown app type: {$appName}</error>");
            $output->writeln('Valid types: claude, codex, gemini, opencode, openclaw');
            return Command::FAILURE;
        }

        if ($input->getOption('presets')) {
            return $this->showPresets($output, $appType);
        }

        $service = new ProviderService(new ProviderRepository($this->app->getMedoo()));
        $providers = $service->list($appType);

        if (empty($providers)) {
            $output->writeln("<comment>No providers configured for {$appType->value}.</comment>");
            $output->writeln('Use <info>provider:add</info> to add a provider.');
            return Command::SUCCESS;
        }

        $current = $service->getCurrent($appType);
        $currentId = $current ? $current->id : null;

        $output->writeln("<info>Providers for {$appType->value}:</info>");
        $output->writeln('');

        foreach ($providers as $provider) {
            $marker = ($provider->id === $currentId) ? ' <fg=green>*</>' : '  ';
            $category = $provider->category ? " [{$provider->category}]" : '';
            $output->writeln("{$marker} <comment>{$provider->name}</comment>{$category}");
            $output->writeln("    ID: {$provider->id}");
            if ($provider->website_url) {
                $output->writeln("    URL: {$provider->website_url}");
            }
        }

        if ($currentId && $appType->isSwitchMode()) {
            $output->writeln('');
            $output->writeln('<fg=green>*</> = current active provider');
        }

        return Command::SUCCESS;
    }

    private function showPresets(OutputInterface $output, AppType $appType): int
    {
        $presets = ProviderService::loadPresets($appType);

        if (empty($presets)) {
            $output->writeln("<comment>No presets available for {$appType->value}.</comment>");
            return Command::SUCCESS;
        }

        $output->writeln("<info>Available presets for {$appType->value}:</info>");
        $output->writeln('');

        foreach ($presets as $index => $preset) {
            $name = $preset['name'] ?? 'Unknown';
            $category = isset($preset['category']) ? " [{$preset['category']}]" : '';
            $partner = !empty($preset['isPartner']) ? ' [partner]' : '';
            $official = !empty($preset['isOfficial']) ? ' [official]' : '';
            $output->writeln("  <comment>{$index}.</comment> {$name}{$category}{$official}{$partner}");
            if (!empty($preset['websiteUrl'])) {
                $output->writeln("     URL: {$preset['websiteUrl']}");
            }
        }

        return Command::SUCCESS;
    }
}
