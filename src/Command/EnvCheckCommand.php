<?php

declare(strict_types=1);

namespace CcSwitch\Command;

use CcSwitch\App;
use CcSwitch\Service\EnvCheckerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check for environment variable conflicts that may interfere with the proxy.
 */
class EnvCheckCommand extends Command
{
    /** @phpstan-ignore property.onlyWritten */
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('env:check')
            ->setDescription('Check for environment variable conflicts with AI provider settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = new EnvCheckerService();
        $conflicts = $service->check();

        if (empty($conflicts)) {
            $output->writeln('<info>No environment variable conflicts detected.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<fg=yellow>Found %d potential conflict(s):</>',
            count($conflicts),
        ));
        $output->writeln('');

        // Group by source type
        $systemConflicts = [];
        $fileConflicts = [];

        foreach ($conflicts as $conflict) {
            if ($conflict['source_type'] === 'system') {
                $systemConflicts[] = $conflict;
            } else {
                $fileConflicts[] = $conflict;
            }
        }

        if (!empty($systemConflicts)) {
            $output->writeln('<fg=cyan>System Environment:</>');
            foreach ($systemConflicts as $c) {
                $maskedValue = $this->maskValue($c['value']);
                $output->writeln(sprintf(
                    '  <fg=yellow>%s</> = %s',
                    $c['var_name'],
                    $maskedValue,
                ));
            }
            $output->writeln('');
        }

        if (!empty($fileConflicts)) {
            $output->writeln('<fg=cyan>Shell Configuration Files:</>');
            foreach ($fileConflicts as $c) {
                $maskedValue = $this->maskValue($c['value']);
                $output->writeln(sprintf(
                    '  <fg=yellow>%s</> = %s  <fg=gray>(%s)</>',
                    $c['var_name'],
                    $maskedValue,
                    $c['source_path'],
                ));
            }
            $output->writeln('');
        }

        $output->writeln('<comment>These variables may override CC Switch proxy settings.</comment>');
        $output->writeln('<comment>Consider removing or commenting them out if using the proxy.</comment>');

        return Command::SUCCESS;
    }

    /**
     * Mask sensitive values, showing only first 4 and last 4 characters.
     */
    private function maskValue(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, -4);
    }
}
