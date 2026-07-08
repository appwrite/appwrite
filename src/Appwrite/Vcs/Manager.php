<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Resolves VCS providers and initialized adapters from the `vcs` config registry.
 *
 * Endpoints and workers inject this service (DI name `vcs`) and resolve the
 * adapter from an installation document instead of hardcoding a provider.
 */
class Manager
{
    /**
     * @var array<string, Provider>
     */
    protected array $providers = [];

    /**
     * @param array<string, array<string, mixed>>|null $config Registry override, defaults to the `vcs` config.
     */
    public function __construct(
        protected Cache $cache,
        ?array $config = null,
    ) {
        $config ??= Config::getParam('vcs', []);

        foreach ($config as $key => $entry) {
            if (!($entry['enabled'] ?? false)) {
                continue;
            }

            $this->providers[$key] = new Provider($key, $entry);
        }
    }

    /**
     * Providers that are enabled and fully configured through environment variables.
     *
     * @return array<string, Provider>
     */
    public function getProviders(): array
    {
        return \array_filter($this->providers, fn (Provider $provider) => $provider->isConfigured());
    }

    public function getProvider(string $key): Provider
    {
        if (!isset($this->providers[$key])) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unsupported VCS provider: ' . $key);
        }

        return $this->providers[$key];
    }

    public function getProviderForInstallation(Document $installation): Provider
    {
        return $this->getProvider($installation->getAttribute('provider', 'github'));
    }

    /**
     * Whether at least one provider is configured.
     */
    public function isEnabled(): bool
    {
        return !empty($this->getProviders());
    }

    /**
     * Uninitialized adapter for provider-level operations that need no
     * installation credentials (webhook signature validation, payload parsing).
     */
    public function createAdapter(string $key): Git
    {
        return $this->getProvider($key)->createAdapter($this->cache);
    }

    /**
     * Fully initialized adapter for an installation.
     *
     * App-based providers authenticate with the platform's app credentials;
     * OAuth2-based providers authenticate with the installation's personal
     * tokens, refreshed and persisted when expired.
     */
    public function getAdapter(Document $installation, Database $dbForPlatform): Git
    {
        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $provider = $this->getProviderForInstallation($installation);
        $adapter = $provider->createAdapter($this->cache);

        if ($provider->getAuthType() === Provider::AUTH_APP) {
            $adapter->initializeVariables(
                $installation->getAttribute('providerInstallationId', ''),
                $provider->getEnv('PRIVATE_KEY'),
                $provider->getEnv('APP_ID'),
            );

            return $adapter;
        }

        $installation = $this->refreshTokens($installation, $dbForPlatform);

        $adapter->initializeVariables(
            $installation->getAttribute('providerInstallationId', ''),
            '',
            null,
            $installation->getAttribute('personalAccessToken', ''),
            $installation->getAttribute('personalRefreshToken', ''),
        );

        return $adapter;
    }

    /**
     * Refresh an OAuth2 installation's personal tokens when expired and persist them.
     * No-op for app-based providers and unexpired tokens.
     */
    public function refreshTokens(Document $installation, Database $dbForPlatform): Document
    {
        $provider = $this->getProviderForInstallation($installation);

        if ($provider->getAuthType() !== Provider::AUTH_OAUTH2) {
            return $installation;
        }

        $expiry = $installation->getAttribute('personalAccessTokenExpiry', '');
        $refreshToken = $installation->getAttribute('personalRefreshToken', '');

        if (!$this->isExpired($expiry) || empty($refreshToken)) {
            return $installation;
        }

        $oauth2 = $provider->createOAuth2();
        try {
            $oauth2->refreshTokens($refreshToken);
        } catch (\Throwable) {
            return $this->recoverRefreshedInstallation($installation, $dbForPlatform, $provider);
        }

        $accessToken = $oauth2->getAccessToken('');
        if (empty($accessToken)) {
            return $this->recoverRefreshedInstallation($installation, $dbForPlatform, $provider);
        }

        $installation
            ->setAttribute('personalAccessToken', $accessToken)
            ->setAttribute('personalRefreshToken', $oauth2->getRefreshToken(''))
            ->setAttribute('personalAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

        $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => $installation->getAttribute('personalAccessToken'),
            'personalRefreshToken' => $installation->getAttribute('personalRefreshToken'),
            'personalAccessTokenExpiry' => $installation->getAttribute('personalAccessTokenExpiry'),
        ]));

        return $installation;
    }

    protected function isExpired(string $expiry): bool
    {
        if (empty($expiry)) {
            return false;
        }

        try {
            return new \DateTime($expiry) < new \DateTime('now');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function recoverRefreshedInstallation(Document $installation, Database $dbForPlatform, Provider $provider): Document
    {
        $fresh = $dbForPlatform->getDocument('installations', $installation->getId());

        if (
            !$fresh->isEmpty()
            && !empty($fresh->getAttribute('personalAccessToken', ''))
            && !$this->isExpired($fresh->getAttribute('personalAccessTokenExpiry', ''))
        ) {
            return $fresh;
        }

        throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. The refresh token may be expired or revoked. Please reconnect the ' . $provider->getName() . ' installation.');
    }

    /**
     * Owner (user or organization) of an installation, independent of auth type.
     * OAuth2-based adapters cannot resolve the owner from the installation id
     * alone and fall back to the stored organization or a repository lookup.
     */
    public function getOwner(Git $adapter, Document $installation, ?string $providerRepositoryId = null): string
    {
        $provider = $this->getProviderForInstallation($installation);

        if ($provider->getAuthType() === Provider::AUTH_APP) {
            return $adapter->getOwnerName($installation->getAttribute('providerInstallationId', ''));
        }

        $organization = $installation->getAttribute('organization', '');
        if (!empty($organization)) {
            return $organization;
        }

        if (!empty($providerRepositoryId)) {
            return $adapter->getOwnerName('', (int)$providerRepositoryId);
        }

        return '';
    }

    /**
     * Create the repository webhook providers without app-level event delivery
     * need for push and pull request events. No-op for app-based providers and
     * for repositories that already went through a connection (an earlier
     * `repositories` document for the same provider repository implies the
     * webhook exists).
     */
    public function ensureRepositoryWebhook(
        Document $installation,
        Database $dbForPlatform,
        string $providerRepositoryId,
    ): void {
        $provider = $this->getProviderForInstallation($installation);

        if (!$provider->requiresRepositoryWebhook()) {
            return;
        }

        $connections = $dbForPlatform->count('repositories', [
            Query::equal('installationInternalId', [$installation->getSequence()]),
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
        ], 2);

        if ($connections > 1) {
            return;
        }

        $adapter = $this->getAdapter($installation, $dbForPlatform);
        $owner = $this->getOwner($adapter, $installation, $providerRepositoryId);
        $repositoryName = $adapter->getRepositoryName($providerRepositoryId);

        $endpoint = System::getEnv('_APP_VCS_WEBHOOK_URL', '');
        if (empty($endpoint)) {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $endpoint = $protocol . '://' . System::getEnv('_APP_DOMAIN', 'localhost');
        }

        // Accept the base with or without a trailing /v1 (both are used in practice).
        $endpoint = \rtrim($endpoint, '/');
        if (\str_ends_with($endpoint, '/v1')) {
            $endpoint = \substr($endpoint, 0, -3);
        }

        $url = $endpoint . '/v1/vcs/' . $provider->getKey() . '/events';

        try {
            $adapter->createWebhook($owner, $repositoryName, $url, $provider->getWebhookSecret());
        } catch (\Throwable $error) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to create repository webhook: ' . $error->getMessage());
        }
    }
}
