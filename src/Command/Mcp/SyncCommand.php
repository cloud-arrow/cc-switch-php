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
 * Sync MCP servers to app config files.
 */
class SyncCommand extends Command
{
    private const VALID_APPS = ['claude', 'codex', 'gemini', 'opencode'];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mcp:sync')
            ->setDescription('Sync MCP servers to app config files')
            ->addArgument('app', InputArgument::OPTIONAL, 'Specific app to sync (claude, codex, gemini, opencode). Syncs all if omitted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $input->getArgument('app');

        if ($app !== null && !in_array($app, self::VALID_APPS, true)) {
            $output->writeln(sprintf(
                '<error>Invalid app "%s". Valid apps: %s</error>',
                $app,
                implode(', ', self::VALID_APPS),
            ));
            return Command::FAILURE;
        }

        $service = new McpService(
            new McpRepository($this->app->getMedoo()),
            new SettingsRepository($this->app->getMedoo()),
        );

        if ($app !== null) {
            $service->syncToApp($app);
            $output->writeln(sprintf('<info>MCP servers synced to %s.</info>', $app));
        } else {
            $service->syncAll();
            $output->writeln('<info>MCP servers synced to all apps.</info>');
        }

        return Command::SUCCESS;
    }
}
