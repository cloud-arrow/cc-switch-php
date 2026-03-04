<?php

declare(strict_types=1);

namespace CcSwitch\Command\Sync;

use CcSwitch\App;
use CcSwitch\Database\Repository\SettingsRepository;
use CcSwitch\Service\WebDavSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Push database to WebDAV remote.
 */
class PushCommand extends Command
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
            ->setName('sync:push')
            ->setDescription('Push database to WebDAV remote')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'WebDAV base URL')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'WebDAV username')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'WebDAV password')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Sync profile name', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->resolveConfig($input);

        if ($config['baseUrl'] === '') {
            $output->writeln('<error>WebDAV URL is required. Use --url or set webdav_url in settings.</error>');
            return Command::FAILURE;
        }

        $service = new WebDavSyncService($this->app->getBaseDir());

        $output->writeln(sprintf('<info>Pushing to %s ...</info>', $config['baseUrl']));

        try {
            $service->push($config);
            $output->writeln('<info>Push completed successfully.</info>');
            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>Push failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * Resolve WebDAV config from CLI options, falling back to settings DB.
     *
     * @return array{baseUrl: string, username: string, password: string, profile: string}
     */
    private function resolveConfig(InputInterface $input): array
    {
        $settingsRepo = new SettingsRepository($this->app->getMedoo());

        return [
            'baseUrl' => $input->getOption('url') ?? $settingsRepo->get('webdav_url') ?? '',
            'username' => $input->getOption('username') ?? $settingsRepo->get('webdav_username') ?? '',
            'password' => $input->getOption('password') ?? $settingsRepo->get('webdav_password') ?? '',
            'profile' => $input->getOption('profile') ?? 'default',
        ];
    }
}
