<?php

declare(strict_types=1);

namespace CcSwitch\Command\Mcp;

use CcSwitch\App;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\McpService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List all MCP servers with their enabled apps.
 */
class ListCommand extends Command
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
            ->setName('mcp:list')
            ->setDescription('List all MCP servers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = new McpService(
            new McpRepository($this->app->getMedoo()),
            new SettingsRepository($this->app->getMedoo()),
        );

        $servers = $service->list();

        if (empty($servers)) {
            $output->writeln('<info>No MCP servers configured.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>%d MCP server(s):</info>', count($servers)));
        $output->writeln('');

        foreach ($servers as $server) {
            $apps = [];
            if ($server->enabled_claude) {
                $apps[] = 'claude';
            }
            if ($server->enabled_codex) {
                $apps[] = 'codex';
            }
            if ($server->enabled_gemini) {
                $apps[] = 'gemini';
            }
            if ($server->enabled_opencode) {
                $apps[] = 'opencode';
            }

            $appsStr = empty($apps) ? '<fg=yellow>none</>' : '<fg=green>' . implode(', ', $apps) . '</>';
            $output->writeln(sprintf(
                '  <fg=cyan>%s</> (%s) — apps: %s',
                $server->id,
                $server->name,
                $appsStr,
            ));

            if ($server->description) {
                $output->writeln(sprintf('    %s', $server->description));
            }
        }

        return Command::SUCCESS;
    }
}
