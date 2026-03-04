<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compact the SQLite database (PRAGMA optimize + VACUUM).
 */
class CompactCommand extends Command
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
            ->setName('db:compact')
            ->setDescription('Compact the SQLite database to reclaim space');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = $this->app->getBaseDir() . '/cc-switch.db';

        if (!file_exists($dbPath)) {
            $output->writeln('<error>Database file not found.</error>');
            return Command::FAILURE;
        }

        $sizeBefore = filesize($dbPath);

        $pdo = $this->app->getPdo();
        $pdo->exec('PRAGMA optimize');
        $pdo->exec('VACUUM');

        clearstatcache(true, $dbPath);
        $sizeAfter = filesize($dbPath);

        $formatSize = fn(int|false $s): string => $s !== false
            ? number_format($s / 1024, 1) . ' KB'
            : 'unknown';

        $output->writeln(sprintf(
            '<info>Database compacted:</info> %s → %s',
            $formatSize($sizeBefore),
            $formatSize($sizeAfter),
        ));

        return Command::SUCCESS;
    }
}
