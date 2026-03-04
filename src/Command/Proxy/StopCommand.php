<?php

declare(strict_types=1);

namespace CcSwitch\Command\Proxy;

use CcSwitch\App;
use CcSwitch\Proxy\ProxyServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stop the proxy server by sending SIGTERM to the PID in the PID file.
 */
class StopCommand extends Command
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
            ->setName('proxy:stop')
            ->setDescription('Stop the proxy server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!ProxyServer::isRunning($this->app->getBaseDir())) {
            $output->writeln('<comment>Proxy server is not running.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Stopping proxy server...</info>');

        if (ProxyServer::stop($this->app->getBaseDir())) {
            $output->writeln('<info>Proxy server stopped.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>Failed to stop proxy server.</error>');
        return Command::FAILURE;
    }
}
