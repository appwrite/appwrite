<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Builds VCS adapters from the `vcs` config registry.
 *
 * Endpoints inject this service (DI name `vcsFactory`) and resolve the
 * adapter from an installation document instead of hardcoding a provider.
 * The factory only constructs adapters — it never touches the database.
 */
class Factory
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $registry = [];

    /**
     * @param array<string, array<string, mixed>>|null $registry Override, defaults to the `vcs` config.
     */
    public function __construct(
        protected Cache $cache,
        ?array $registry = null,
    ) {
        $registry ??= Config::getParam('vcs', []);

        foreach ($registry as $key => $entry) {
            if (!($entry['enabled'] ?? false)) {
                continue;
            }

            $this->registry[$key] = $entry;
        }
    }

    /**
     * Keys of providers that are enabled and fully configured.
     *
     * @return array<string>
     */
    public function getProviders(): array
    {
        return \array_values(\array_filter(
            \array_keys($this->registry),
            fn (string $key) => $this->isConfigured($key),
        ));
    }

    /**
     * Whether all required environment variables are set for a provider.
     */
    public function isConfigured(string $key): bool
    {
        if (!isset($this->registry[$key])) {
            return false;
        }

        foreach ($this->registry[$key]['requiredEnvVariables'] ?? [] as $name) {
            if (empty($this->getEnv($key, $name))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Uninitialized adapter for provider-level operations that need no
     * installation credentials (webhook signature validation, payload parsing).
     */
    public function fromProvider(string $key): Git
    {
        if (!isset($this->registry[$key])) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unsupported VCS provider: ' . $key);
        }

        $adapter = new ($this->registry[$key]['adapter'])($this->cache);

        $endpoint = $this->getEnv($key, 'ENDPOINT');
        if (!empty($endpoint) && \method_exists($adapter, 'setEndpoint')) {
            $adapter->setEndpoint(\rtrim($endpoint, '/'));
        }

        return $adapter;
    }

    /**
     * Initialized adapter for an installation. App credentials and personal
     * tokens are both always passed; each adapter uses the set its
     * authentication scheme needs and ignores the other.
     */
    public function fromInstallation(Document $installation): Git
    {
        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $provider = $installation->getAttribute('provider', 'github');
        $adapter = $this->fromProvider($provider);

        $adapter->initializeVariables(
            $installation->getAttribute('providerInstallationId', ''),
            $this->getEnv($provider, 'PRIVATE_KEY'),
            $this->getEnv($provider, 'APP_ID'),
            $installation->getAttribute('personalAccessToken', ''),
            $installation->getAttribute('personalRefreshToken', ''),
        );

        return $adapter;
    }

    protected function getEnv(string $key, string $name): string
    {
        $variable = $this->registry[$key]['envVariables'][$name] ?? '';

        return empty($variable) ? '' : System::getEnv($variable, '');
    }
}
