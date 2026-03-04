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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
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
            ->setName('export')
            ->setDescription('Export providers to JSON')
            ->addArgument('app', InputArgument::REQUIRED, 'Application type (claude, codex, gemini, opencode, openclaw)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)');
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
        $data = $service->export($appType);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $outputFile = $input->getOption('output');
        if ($outputFile) {
            file_put_contents($outputFile, $json . "\n");
            $output->writeln("<info>Exported " . count($data) . " provider(s) to {$outputFile}</info>");
        } else {
            $output->writeln($json);
        }

        return Command::SUCCESS;
    }
}
