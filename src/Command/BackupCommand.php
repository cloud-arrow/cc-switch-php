<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database backup management: create, list, and restore backups.
 */
class BackupCommand extends Command
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
            ->setName('backup')
            ->setDescription('Manage database backups')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List existing backups')
            ->addOption('restore', 'r', InputOption::VALUE_REQUIRED, 'Restore from a backup file')
            ->addOption('cleanup', null, InputOption::VALUE_OPTIONAL, 'Remove old backups, keeping N most recent (default: 10)', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = new BackupService($this->app->getBaseDir());

        // List backups
        if ($input->getOption('list')) {
            return $this->listBackups($service, $output);
        }

        // Restore from backup
        $restoreFile = $input->getOption('restore');
        if ($restoreFile !== null) {
            return $this->restoreBackup($service, $restoreFile, $output);
        }

        // Cleanup old backups (only if explicitly passed)
        if ($input->getParameterOption('--cleanup') !== false) {
            $retain = (int) $input->getOption('cleanup');
            return $this->cleanupBackups($service, $retain, $output);
        }

        // Default: create a new backup
        return $this->createBackup($service, $output);
    }

    private function createBackup(BackupService $service, OutputInterface $output): int
    {
        try {
            $path = $service->run();
            $output->writeln(sprintf('<info>Backup created:</info> %s', $path));
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>Backup failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function listBackups(BackupService $service, OutputInterface $output): int
    {
        $backups = $service->list();

        if (empty($backups)) {
            $output->writeln('<comment>No backups found.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>%d backup(s):</info>', count($backups)));
        $output->writeln('');

        foreach ($backups as $backup) {
            $sizeKb = round($backup['size_bytes'] / 1024, 1);
            $output->writeln(sprintf(
                '  <fg=cyan>%s</>  %s KB  %s',
                $backup['filename'],
                number_format($sizeKb, 1),
                $backup['created_at'],
            ));
        }

        return Command::SUCCESS;
    }

    private function restoreBackup(BackupService $service, string $file, OutputInterface $output): int
    {
        try {
            $service->restore($file);
            $output->writeln(sprintf('<info>Database restored from:</info> %s', $file));
            $output->writeln('<comment>A safety backup was created before restoring.</comment>');
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>Restore failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function cleanupBackups(BackupService $service, int $retain, OutputInterface $output): int
    {
        $beforeCount = count($service->list());
        $service->cleanup(max(1, $retain));
        $afterCount = count($service->list());
        $removed = $beforeCount - $afterCount;

        if ($removed > 0) {
            $output->writeln(sprintf('<info>Removed %d old backup(s), keeping %d.</info>', $removed, $afterCount));
        } else {
            $output->writeln(sprintf('<comment>No backups to remove (have %d, keeping %d).</comment>', $beforeCount, max(1, $retain)));
        }

        return Command::SUCCESS;
    }
}
