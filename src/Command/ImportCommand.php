<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Model\AppType;
use CcSwitch\Service\ProviderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends Command
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
            ->setName('import')
            ->setDescription('Import providers from a JSON file')
            ->addArgument('app', InputArgument::REQUIRED, 'Application type (claude, codex, gemini, opencode, openclaw)')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON file path to import from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appName = $input->getArgument('app');
        $appType = AppType::tryFrom($appName);
        if ($appType === null) {
            $output->writeln("<error>Unknown app type: {$appName}</error>");
            return Command::FAILURE;
        }

        $file = $input->getArgument('file');
        if (!file_exists($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return Command::FAILURE;
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $output->writeln('<error>Invalid JSON file.</error>');
            return Command::FAILURE;
        }

        $service = new ProviderService(new ProviderRepository($this->app->getMedoo()));
        $count = $service->import($appType, $data);

        $output->writeln("<info>Imported {$count} provider(s) for {$appType->value}.</info>");

        return Command::SUCCESS;
    }
}
