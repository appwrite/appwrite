<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

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
        $this->registry = $registry ?? Config::getParam('vcs', []);
    }

    public function getProviders(): array
    {
        return \array_values(\array_filter(
            \array_keys($this->registry),
            fn (string $key) => $this->isConfigured($key),
        ));
    }

    public function isConfigured(string $key): bool
    {
        if (!isset($this->registry[$key])) {
            return false;
        }

        foreach ($this->registry[$key]['variables'] ?? [] as $name => $variable) {
            if (($variable['required'] ?? false) && empty($this->getEnv($key, $name))) {
                return false;
            }
        }

        return true;
    }

    public function fromProvider(string $key): Git
    {
        if (!isset($this->registry[$key])) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unsupported VCS provider: ' . $key);
        }

        $adapter = new ($this->registry[$key]['adapter'])($this->cache);

        $endpoint = $this->registry[$key]['endpoint'] ?? $this->getEnv($key, 'endpoint');
        if (!empty($endpoint) && \method_exists($adapter, 'setEndpoint')) {
            $adapter->setEndpoint(\rtrim($endpoint, '/'));
        }

        return $adapter;
    }

    public function fromInstallation(Document $installation): Git
    {
        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $provider = $installation->getAttribute('provider', '');
        if (empty($provider)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing VCS provider for installation: ' . $installation->getId());
        }

        $adapter = $this->fromProvider($provider);

        $adapter->initializeVariables(
            $installation->getAttribute('providerInstallationId', ''),
            $this->getEnv($provider, 'privateKey'),
            $this->getEnv($provider, 'appId'),
            $installation->getAttribute('personalAccessToken', ''),
            $installation->getAttribute('personalRefreshToken', ''),
        );

        return $adapter;
    }

    public function getWebhookSecret(string $key): string
    {
        return $this->getEnv($key, 'webhookSecret');
    }

    public function oauth2FromProvider(string $key): OAuth2&EnvOAuth2
    {
        $class = $this->registry[$key]['oauth2'] ?? null;

        if ($class === null || !\is_a($class, EnvOAuth2::class, true)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unsupported VCS provider: ' . $key);
        }

        return $class::fromEnv();
    }

    protected function getEnv(string $key, string $name): string
    {
        $variable = $this->registry[$key]['variables'][$name]['envVariable'] ?? '';

        return empty($variable) ? '' : System::getEnv($variable, '');
    }
}
