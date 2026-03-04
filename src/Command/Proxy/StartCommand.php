<?php

declare(strict_types=1);

namespace CcSwitch\Command\Proxy;

use CcSwitch\App;
use CcSwitch\Proxy\ProxyServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start the proxy server.
 */
class StartCommand extends Command
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
            ->setName('proxy:start')
            ->setDescription('Start the proxy server')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Listen address', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Listen port', '15721');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        // Check if already running
        if (ProxyServer::isRunning($this->app->getBaseDir())) {
            $output->writeln('<error>Proxy server is already running.</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Starting proxy server on {$host}:{$port}...</info>");

        $server = new ProxyServer(
            $this->app->getMedoo(),
            $host,
            $port,
            $this->app->getBaseDir(),
        );

        // This call blocks until the server shuts down
        $server->start();

        return Command::SUCCESS;
    }
}
