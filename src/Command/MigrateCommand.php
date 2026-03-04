<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Database\Migrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run or inspect database migrations.
 */
class MigrateCommand extends Command
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
            ->setName('migrate')
            ->setDescription('Run database migrations')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show migration status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->app->getPdo(), $migrationsDir);

        if ($input->getOption('status')) {
            $statuses = $migrator->status();
            $output->writeln('<info>Migration status:</info>');
            foreach ($statuses as $status) {
                $mark = $status['applied'] ? '<fg=green>Y</>' : '<fg=red>N</>';
                $batch = $status['batch'] !== null ? " [batch {$status['batch']}]" : '';
                $output->writeln("  [{$mark}] {$status['file']}{$batch}");
            }
            return Command::SUCCESS;
        }

        $count = $migrator->migrate();
        if ($count === 0) {
            $output->writeln('<info>Nothing to migrate.</info>');
        } else {
            $output->writeln("<info>Ran {$count} migration(s).</info>");
        }

        return Command::SUCCESS;
    }
}
