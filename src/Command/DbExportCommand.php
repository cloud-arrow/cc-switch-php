<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Export database to a SQL dump file.
 */
class DbExportCommand extends Command
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
            ->setName('db:export')
            ->setDescription('Export database to a SQL dump file')
            ->addArgument('file', InputArgument::OPTIONAL, 'Output file path (defaults to cc-switch-export.sql)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        if ($filePath === null) {
            $filePath = getcwd() . '/cc-switch-export.sql';
        }

        $service = new BackupService($this->app->getBaseDir());

        try {
            $service->exportSqlToFile($filePath);
            $size = filesize($filePath);
            $sizeKb = $size !== false ? round($size / 1024, 1) : '?';
            $output->writeln(sprintf('<info>Database exported to: %s (%s KB)</info>', $filePath, $sizeKb));
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>Export failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
