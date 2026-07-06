<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * A single VCS provider from the `vcs` config registry (app/config/vcs.php).
 *
 * Wraps the registry entry and the provider's environment variables so that
 * endpoints and workers never read provider-specific env vars or build
 * provider-specific URLs themselves.
 */
class Provider
{
    public const AUTH_APP = 'app';
    public const AUTH_OAUTH2 = 'oauth2';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected string $key,
        protected array $config,
    ) {
    }

    /**
     * Provider by registry key, e.g. from an adapter's `getName()`.
     */
    public static function fromKey(string $key): self
    {
        return new self($key, Config::getParam('vcs', [])[$key] ?? []);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->config['name'] ?? $this->key;
    }

    public function getAuthType(): string
    {
        return $this->config['auth'] ?? self::AUTH_APP;
    }

    /**
     * Whether all required environment variables are set.
     * The literal value 'disabled' counts as unset (used by test environments).
     */
    public function isConfigured(): bool
    {
        foreach ($this->config['required'] ?? [] as $key) {
            $value = $this->getEnv($key);
            if (empty($value) || $value === 'disabled') {
                return false;
            }
        }

        return true;
    }

    public function getEnv(string $key, string $default = ''): string
    {
        return System::getEnv($this->getEnvName($key), $default);
    }

    public function getEnvName(string $key): string
    {
        return $this->config['envPrefix'] . '_' . $key;
    }

    /**
     * API endpoint for self-hosted providers, null when the adapter default applies.
     */
    public function getEndpoint(): ?string
    {
        if (empty($this->config['endpoint'])) {
            return null;
        }

        $endpoint = $this->getEnv('ENDPOINT');

        return empty($endpoint) ? null : \rtrim($endpoint, '/');
    }

    /**
     * Base URL for user-facing links. In containerized setups the browser-facing
     * URL can differ from the API endpoint Appwrite talks to.
     */
    public function getBrowserEndpoint(): string
    {
        $endpoint = $this->getEnv('BROWSER_ENDPOINT');

        if (empty($endpoint)) {
            $endpoint = $this->config['browserEndpoint'] ?? '';
        }

        if (empty($endpoint)) {
            $endpoint = $this->getEndpoint() ?? '';
        }

        return \rtrim($endpoint, '/');
    }

    public function getRepositoryUrl(string $owner, string $repository): string
    {
        return $this->buildUrl('repository', [
            'owner' => $owner,
            'repository' => $repository,
        ]);
    }

    public function getBranchUrl(string $owner, string $repository, string $branch): string
    {
        return $this->buildUrl('branch', [
            'owner' => $owner,
            'repository' => $repository,
            'branch' => $branch,
        ]);
    }

    public function getCommitUrl(string $owner, string $repository, string $commit): string
    {
        return $this->buildUrl('commit', [
            'owner' => $owner,
            'repository' => $repository,
            'commit' => $commit,
        ]);
    }

    public function getFileUrl(string $owner, string $repository, string $reference): string
    {
        return $this->buildUrl('file', [
            'owner' => $owner,
            'repository' => $repository,
            'reference' => $reference,
        ]);
    }

    public function getEventHeader(): string
    {
        return $this->config['headers']['event'] ?? '';
    }

    public function getSignatureHeader(): string
    {
        return $this->config['headers']['signature'] ?? '';
    }

    public function getWebhookSecret(): string
    {
        return $this->getEnv('WEBHOOK_SECRET');
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->config['scopes'] ?? [];
    }

    /**
     * Whether Appwrite must create webhooks on each connected repository.
     * App-based providers (GitHub App) deliver events without per-repository setup.
     */
    public function requiresRepositoryWebhook(): bool
    {
        return $this->config['repositoryWebhook'] ?? false;
    }

    /**
     * Uninitialized VCS adapter, endpoint applied for self-hosted providers.
     */
    public function createAdapter(Cache $cache): Git
    {
        $adapter = new ($this->config['adapter'])($cache);

        $endpoint = $this->getEndpoint();
        if (!empty($endpoint) && \method_exists($adapter, 'setEndpoint')) {
            $adapter->setEndpoint($endpoint);
        }

        return $adapter;
    }

    /**
     * OAuth2 adapter for token exchange and refresh.
     *
     * @param array<string> $state
     */
    public function createOAuth2(string $callback = '', array $state = []): OAuth2
    {
        $oauth2 = new ($this->config['oauth2'])(
            $this->getEnv('CLIENT_ID'),
            $this->getEnv('CLIENT_SECRET'),
            $callback,
            $state,
            $this->getScopes(),
        );

        $endpoint = $this->getEndpoint();
        if (!empty($endpoint) && \method_exists($oauth2, 'setEndpoint')) {
            $oauth2->setEndpoint($endpoint);
        }

        return $oauth2;
    }

    /**
     * @param array<string, string> $params
     */
    protected function buildUrl(string $template, array $params): string
    {
        $url = $this->config['urls'][$template] ?? '';
        if (empty($url)) {
            return '';
        }

        $params['base'] = $this->getBrowserEndpoint();

        foreach ($params as $key => $value) {
            $url = \str_replace('{' . $key . '}', $value, $url);
        }

        return $url;
    }
}
