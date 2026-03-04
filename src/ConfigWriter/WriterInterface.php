<?php

declare(strict_types=1);

namespace CcSwitch\ConfigWriter;

use CcSwitch\Model\Provider;

/**
 * Interface for application config writers.
 *
 * Each writer knows how to apply a provider's settings_config to the
 * corresponding CLI tool's configuration files on disk.
 */
interface WriterInterface
{
    /**
     * Write a single provider's configuration to the application's config files.
     *
     * @param Provider $provider The provider whose settings_config should be written
     * @throws \RuntimeException on write failure
     */
    public function write(Provider $provider): void;

    /**
     * Remove a provider's configuration from the application's config files.
     *
     * For switch-mode apps this typically means clearing/deleting the config.
     * For additive-mode apps this removes just the provider's entry.
     *
     * @param string $providerId Provider identifier
     */
    public function remove(string $providerId): void;

    /**
     * Get the configuration directory path for this app type.
     */
    public function getConfigDir(): string;
}
