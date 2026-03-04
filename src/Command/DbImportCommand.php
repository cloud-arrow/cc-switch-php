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
 * Import database from a CC Switch SQL dump file.
 */
class DbImportCommand extends Command
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
            ->setName('db:import')
            ->setDescription('Import database from a CC Switch SQL dump file')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the SQL dump file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');

        if (!file_exists($filePath)) {
            $output->writeln(sprintf('<error>File not found: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $output->writeln('<comment>A safety backup will be created before importing.</comment>');

        $service = new BackupService($this->app->getBaseDir());

        try {
            $service->importSqlFromFile($filePath);
            $output->writeln(sprintf('<info>Database imported from: %s</info>', $filePath));
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>Import failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
