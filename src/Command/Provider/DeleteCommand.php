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

class DeleteCommand extends Command
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
            ->setName('provider:delete')
            ->setDescription('Delete a provider')
            ->addArgument('app', InputArgument::REQUIRED, 'Application type (claude, codex, gemini, opencode, openclaw)')
            ->addArgument('id', InputArgument::REQUIRED, 'Provider ID to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appName = $input->getArgument('app');
        $appType = AppType::tryFrom($appName);
        if ($appType === null) {
            $output->writeln("<error>Unknown app type: {$appName}</error>");
            return Command::FAILURE;
        }

        $id = $input->getArgument('id');
        $service = new ProviderService(new ProviderRepository($this->app->getMedoo()));

        // Verify provider exists
        $provider = $service->get($id, $appType);
        if ($provider === null) {
            $output->writeln("<error>Provider {$id} not found for {$appType->value}.</error>");
            return Command::FAILURE;
        }

        try {
            $service->delete($id, $appType);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Provider deleted: {$provider->name} ({$id})</info>");

        return Command::SUCCESS;
    }
}
