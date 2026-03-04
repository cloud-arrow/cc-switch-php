<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Database\Repository\ProviderRepository;
use CcSwitch\Service\SpeedTestService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test latency to provider API endpoints.
 */
class SpeedTestCommand extends Command
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
            ->setName('speedtest')
            ->setDescription('Test latency to provider API endpoints')
            ->addArgument('app', InputArgument::REQUIRED, 'App type (claude, codex, gemini, opencode)')
            ->addArgument('providerId', InputArgument::OPTIONAL, 'Provider ID (tests all if omitted)')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Timeout in seconds', '8');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appType = $input->getArgument('app');
        $providerId = $input->getArgument('providerId');
        $timeout = (int) $input->getOption('timeout');

        $validApps = ['claude', 'codex', 'gemini', 'opencode'];
        if (!in_array($appType, $validApps, true)) {
            $output->writeln('<error>Invalid app type. Must be one of: ' . implode(', ', $validApps) . '</error>');
            return Command::FAILURE;
        }

        $providerRepo = new ProviderRepository($this->app->getMedoo());

        // Collect endpoint URLs
        $urls = [];
        if ($providerId !== null) {
            $provider = $providerRepo->get($providerId, $appType);
            if ($provider === null) {
                $output->writeln("<error>Provider '{$providerId}' not found for app '{$appType}'.</error>");
                return Command::FAILURE;
            }
            $urls = $this->getEndpointUrls($provider);
            if (empty($urls)) {
                $output->writeln('<comment>No endpoints configured for this provider.</comment>');
                return Command::SUCCESS;
            }
        } else {
            $providers = $providerRepo->list($appType);
            if (empty($providers)) {
                $output->writeln("<comment>No providers found for app '{$appType}'.</comment>");
                return Command::SUCCESS;
            }
            foreach ($providers as $provider) {
                $providerUrls = $this->getEndpointUrls($provider);
                $urls = array_merge($urls, $providerUrls);
            }
            if (empty($urls)) {
                $output->writeln('<comment>No endpoints configured for any provider.</comment>');
                return Command::SUCCESS;
            }
        }

        $urls = array_unique($urls);

        $output->writeln(sprintf('<info>Testing %d endpoint(s) for %s...</info>', count($urls), $appType));
        $output->writeln('');

        $service = new SpeedTestService();
        $results = $service->test($urls, $timeout);

        foreach ($results as $result) {
            if ($result['error'] !== null) {
                $output->writeln(sprintf(
                    '  <fg=red>FAIL</> %s — %s',
                    $result['url'],
                    $result['error'],
                ));
            } else {
                $latency = $result['latency_ms'];
                $color = $latency < 200 ? 'green' : ($latency < 500 ? 'yellow' : 'red');
                $output->writeln(sprintf(
                    '  <fg=%s>%4dms</> %s (HTTP %d)',
                    $color,
                    $latency,
                    $result['url'],
                    $result['status'],
                ));
            }
        }

        $output->writeln('');
        return Command::SUCCESS;
    }

    /**
     * Extract testable endpoint URLs from a provider row.
     *
     * @param array<string, mixed> $provider
     * @return string[]
     */
    private function getEndpointUrls(array $provider): array
    {
        $urls = [];

        // Try to extract from settings_config
        $settingsConfig = $provider['settings_config'] ?? '';
        if ($settingsConfig !== '' && is_string($settingsConfig)) {
            $config = json_decode($settingsConfig, true);
            if (is_array($config)) {
                // Claude: env.ANTHROPIC_BASE_URL
                if (isset($config['env']['ANTHROPIC_BASE_URL']) && $config['env']['ANTHROPIC_BASE_URL'] !== '') {
                    $urls[] = rtrim($config['env']['ANTHROPIC_BASE_URL'], '/');
                }
                // Gemini: env.GOOGLE_GEMINI_BASE_URL
                if (isset($config['env']['GOOGLE_GEMINI_BASE_URL']) && $config['env']['GOOGLE_GEMINI_BASE_URL'] !== '') {
                    $urls[] = rtrim($config['env']['GOOGLE_GEMINI_BASE_URL'], '/');
                }
                // OpenCode: options.baseURL
                if (isset($config['options']['baseURL']) && $config['options']['baseURL'] !== '') {
                    $urls[] = rtrim($config['options']['baseURL'], '/');
                }
            }
        }

        // Also check provider_endpoints table
        $endpointRows = $this->app->getMedoo()->select('provider_endpoints', 'url', [
            'provider_id' => $provider['id'],
            'app_type' => $provider['app_type'],
        ]);
        if (is_array($endpointRows)) {
            foreach ($endpointRows as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        // Fallback: website_url
        if (empty($urls) && isset($provider['website_url']) && $provider['website_url'] !== '') {
            $urls[] = $provider['website_url'];
        }

        return $urls;
    }
}
