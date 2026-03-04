<?php

declare(strict_types=1);

namespace CcSwitch\Command\Mcp;

use CcSwitch\App;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\McpService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete an MCP server.
 */
class DeleteCommand extends Command
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
            ->setName('mcp:delete')
            ->setDescription('Delete an MCP server')
            ->addArgument('id', InputArgument::REQUIRED, 'Server ID to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        $service = new McpService(
            new McpRepository($this->app->getMedoo()),
            new SettingsRepository($this->app->getMedoo()),
        );

        $server = $service->get($id);
        if (!$server) {
            $output->writeln(sprintf('<error>MCP server "%s" not found.</error>', $id));
            return Command::FAILURE;
        }

        $service->delete($id);
        $output->writeln(sprintf('<info>MCP server "%s" deleted and removed from app configs.</info>', $id));

        return Command::SUCCESS;
    }
}
