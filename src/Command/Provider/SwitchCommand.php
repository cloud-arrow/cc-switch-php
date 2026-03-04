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
use Symfony\Component\Console\Output\OutputInterface;

class SwitchCommand extends Command
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
            ->setName('provider:switch')
            ->setDescription('Switch to a different provider (switch-mode apps only)')
            ->addArgument('app', InputArgument::REQUIRED, 'Application type (claude, codex, gemini)')
            ->addArgument('id', InputArgument::REQUIRED, 'Provider ID to switch to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appName = $input->getArgument('app');
        $appType = AppType::tryFrom($appName);
        if ($appType === null) {
            $output->writeln("<error>Unknown app type: {$appName}</error>");
            return Command::FAILURE;
        }

        if ($appType->isAdditiveMode()) {
            $output->writeln("<error>{$appType->value} uses additive mode and does not support switching.</error>");
            $output->writeln('All providers are active simultaneously for this app.');
            return Command::FAILURE;
        }

        $id = $input->getArgument('id');
        $service = new ProviderService(new ProviderRepository($this->app->getMedoo()));

        try {
            $service->switchTo($id, $appType);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $provider = $service->get($id, $appType);
        $name = $provider ? $provider->name : $id;
        $output->writeln("<info>Switched {$appType->value} to: {$name}</info>");

        return Command::SUCCESS;
    }
}
