<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Http\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start the web UI server (and optionally the proxy server).
 */
class ServeCommand extends Command
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
            ->setName('serve')
            ->setDescription('Start the web UI server')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'Listen host', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Web UI port', '8080')
            ->addOption('with-proxy', null, InputOption::VALUE_NONE, 'Also start the proxy server as a sub-listener')
            ->addOption('proxy-port', null, InputOption::VALUE_REQUIRED, 'Proxy server port', '15721');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $withProxy = (bool) $input->getOption('with-proxy');
        $proxyPort = (int) $input->getOption('proxy-port');

        $output->writeln("<info>Starting CC Switch server on http://{$host}:{$port}</info>");
        if ($withProxy) {
            $output->writeln("<info>Proxy server will be attached on {$host}:{$proxyPort}</info>");
        }

        $server = new Server(
            $this->app,
            $port,
            $withProxy,
            $proxyPort,
            $host,
        );

        // This call blocks until the server shuts down
        $server->start();

        return Command::SUCCESS;
    }
}
