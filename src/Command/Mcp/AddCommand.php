<?php

declare(strict_types=1);

namespace CcSwitch\Command\Mcp;

use CcSwitch\App;
use CcSwitch\Database\Repository\McpRepository;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\McpService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Add or update an MCP server.
 */
class AddCommand extends Command
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
            ->setName('mcp:add')
            ->setDescription('Add or update an MCP server')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Server ID (auto-generated if omitted)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server display name')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Command to run (for stdio type)')
            ->addOption('args', null, InputOption::VALUE_REQUIRED, 'Comma-separated arguments')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL (for http/sse type)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Server type: stdio, http, sse', 'stdio')
            ->addOption('apps', null, InputOption::VALUE_REQUIRED, 'Comma-separated app names to enable (claude,codex,gemini,opencode)', 'claude');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');
        if (!$name) {
            $output->writeln('<error>--name is required.</error>');
            return Command::FAILURE;
        }

        $type = $input->getOption('type');
        $command = $input->getOption('command');
        $url = $input->getOption('url');

        if ($type === 'stdio' && !$command) {
            $output->writeln('<error>--command is required for stdio type.</error>');
            return Command::FAILURE;
        }

        if (($type === 'http' || $type === 'sse') && !$url) {
            $output->writeln('<error>--url is required for http/sse type.</error>');
            return Command::FAILURE;
        }

        // Build server_config
        $serverConfig = ['type' => $type];

        if ($type === 'stdio') {
            $serverConfig['command'] = $command;
            $argsStr = $input->getOption('args');
            if ($argsStr) {
                $serverConfig['args'] = array_map('trim', explode(',', $argsStr));
            }
        } else {
            $serverConfig['url'] = $url;
        }

        // Parse enabled apps
        $appsStr = $input->getOption('apps') ?? 'claude';
        $enabledApps = array_map('trim', explode(',', $appsStr));

        $id = $input->getOption('id') ?? $name;

        $data = [
            'id' => $id,
            'name' => $name,
            'server_config' => json_encode($serverConfig, JSON_UNESCAPED_SLASHES),
            'enabled_claude' => in_array('claude', $enabledApps) ? 1 : 0,
            'enabled_codex' => in_array('codex', $enabledApps) ? 1 : 0,
            'enabled_gemini' => in_array('gemini', $enabledApps) ? 1 : 0,
            'enabled_opencode' => in_array('opencode', $enabledApps) ? 1 : 0,
        ];

        $service = new McpService(
            new McpRepository($this->app->getMedoo()),
            new SettingsRepository($this->app->getMedoo()),
        );

        $server = $service->upsert($data);

        $output->writeln(sprintf(
            '<info>MCP server "%s" (%s) saved and synced to: %s</info>',
            $server->name,
            $server->id,
            $appsStr,
        ));

        return Command::SUCCESS;
    }
}
