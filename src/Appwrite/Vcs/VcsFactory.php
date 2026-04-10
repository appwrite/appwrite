<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Database\Document;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Adapter\Git\GitHub;

class VcsFactory
{
    /**
     * Create a VCS adapter instance for the given provider.
     */
    public static function getAdapter(string $provider, Cache $cache): Git
    {
        return match ($provider) {
            'github' => new GitHub($cache),
            'gitea' => self::createGiteaBasedAdapter($provider, $cache),
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Unsupported VCS provider: {$provider}"),
        };
    }

    /**
     * Initialize a VCS adapter with credentials from the installation document and environment.
     */
    public static function initializeAdapter(Git $adapter, string $provider, Document $installation): void
    {
        $providerInstallationId = $installation->getAttribute('providerInstallationId', '');

        match ($provider) {
            'github' => $adapter->initializeVariables(
                $providerInstallationId,
                System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''),
                System::getEnv('_APP_VCS_GITHUB_APP_ID', ''),
            ),
            'gitea' => $adapter->initializeVariables(
                '',
                '',
                null,
                $installation->getAttribute('personalAccessToken', ''),
                $installation->getAttribute('personalRefreshToken', ''),
            ),
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Unsupported VCS provider: {$provider}"),
        };
    }

    /**
     * Create and initialize a VCS adapter in one call.
     */
    public static function getInitializedAdapter(string $provider, Document $installation, Cache $cache): Git
    {
        $adapter = self::getAdapter($provider, $cache);
        self::initializeAdapter($adapter, $provider, $installation);
        return $adapter;
    }

    /**
     * Get the browser-facing base URL for a VCS provider.
     * Used for constructing user-visible URLs (repo, branch, commit links).
     */
    public static function getProviderUrl(string $provider): string
    {
        return match ($provider) {
            'github' => 'https://github.com',
            'gitea' => rtrim(self::getBrowserEndpoint($provider), '/'),
            default => '',
        };
    }

    /**
     * Build a branch URL for the given provider.
     */
    public static function getBranchUrl(string $provider, string $owner, string $repo, string $branch): string
    {
        $base = self::getProviderUrl($provider);
        if (empty($base)) {
            return '';
        }

        return match ($provider) {
            'github' => "{$base}/{$owner}/{$repo}/tree/{$branch}",
            'gitea' => "{$base}/{$owner}/{$repo}/src/branch/{$branch}",
            default => '',
        };
    }

    /**
     * Build a repository URL for the given provider.
     */
    public static function getRepoUrl(string $provider, string $owner, string $repo): string
    {
        $base = self::getProviderUrl($provider);
        if (empty($base)) {
            return '';
        }

        return "{$base}/{$owner}/{$repo}";
    }

    /**
     * Build a commit URL for the given provider.
     */
    public static function getCommitUrl(string $provider, string $owner, string $repo, string $hash): string
    {
        $base = self::getProviderUrl($provider);
        if (empty($base)) {
            return '';
        }

        return match ($provider) {
            'github' => "{$base}/{$owner}/{$repo}/commit/{$hash}",
            'gitea' => "{$base}/{$owner}/{$repo}/commit/{$hash}",
            default => '',
        };
    }

    /**
     * Get the webhook secret env var for a provider.
     */
    public static function getWebhookSecret(string $provider): string
    {
        return match ($provider) {
            'github' => System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', ''),
            'gitea' => System::getEnv('_APP_VCS_GITEA_WEBHOOK_SECRET', ''),
            default => '',
        };
    }

    /**
     * Get the OAuth2 client ID env var for a provider.
     */
    public static function getClientId(string $provider): string
    {
        return match ($provider) {
            'github' => System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''),
            'gitea' => System::getEnv('_APP_VCS_GITEA_CLIENT_ID', ''),
            default => '',
        };
    }

    /**
     * Get the OAuth2 client secret env var for a provider.
     */
    public static function getClientSecret(string $provider): string
    {
        return match ($provider) {
            'github' => System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''),
            'gitea' => System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET', ''),
            default => '',
        };
    }

    /**
     * Get the endpoint URL for a self-hosted provider.
     */
    public static function getEndpoint(string $provider): string
    {
        return match ($provider) {
            'gitea' => System::getEnv('_APP_VCS_GITEA_ENDPOINT', ''),
            default => '',
        };
    }

    /**
     * Get the browser-facing endpoint URL for a self-hosted provider.
     *
     * In Docker, the internal endpoint (e.g. http://gitea:3000) differs from
     * the URL the browser can reach (e.g. http://localhost:9510). This returns
     * the browser-facing URL, falling back to the internal endpoint if not set.
     */
    public static function getBrowserEndpoint(string $provider): string
    {
        return match ($provider) {
            'gitea' => System::getEnv('_APP_VCS_GITEA_BROWSER_ENDPOINT', System::getEnv('_APP_VCS_GITEA_ENDPOINT', '')),
            default => '',
        };
    }

    /**
     * Get the owner name from a VCS adapter, handling provider differences.
     *
     * GitHub uses installationId, Gitea-based providers require repositoryId.
     */
    public static function getOwnerName(Git $adapter, string $provider, string $providerInstallationId, ?string $providerRepositoryId = null): string
    {
        return match ($provider) {
            'github' => $adapter->getOwnerName($providerInstallationId),
            'gitea' => $adapter->getOwnerName('', !empty($providerRepositoryId) ? intval($providerRepositoryId) : null),
            default => '',
        };
    }

    /**
     * Check if a provider is configured via environment variables.
     */
    public static function isProviderConfigured(string $provider): bool
    {
        return match ($provider) {
            'github' => !empty(System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''))
                && !empty(System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''))
                && !empty(System::getEnv('_APP_VCS_GITHUB_APP_ID', ''))
                && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''))
                && !empty(System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', '')),
            'gitea' => !empty(System::getEnv('_APP_VCS_GITEA_ENDPOINT', '')),
            default => false,
        };
    }

    /**
     * Get all configured VCS providers.
     *
     * @return string[]
     */
    public static function getConfiguredProviders(): array
    {
        $configured = [];
        foreach (APP_VCS_PROVIDERS as $provider) {
            if (self::isProviderConfigured($provider)) {
                $configured[] = $provider;
            }
        }
        return $configured;
    }

    /**
     * Create a webhook on a repository for non-GitHub providers.
     *
     * GitHub manages webhooks via its App — no per-repo setup needed.
     * Gitea (and similar) require explicit webhook creation on each repository.
     */
    public static function createRepositoryWebhook(Git $adapter, string $provider, string $owner, string $repositoryName): void
    {
        if ($provider === 'github') {
            return;
        }

        // Use the internal webhook URL if set (for Docker-to-Docker communication),
        // otherwise fall back to the public domain.
        $internalUrl = System::getEnv('_APP_VCS_WEBHOOK_URL', '');
        if (empty($internalUrl)) {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http://' : 'https://';
            $internalUrl = $protocol . System::getEnv('_APP_DOMAIN', 'localhost');
        }

        $webhookUrl = rtrim($internalUrl, '/') . "/v1/vcs/{$provider}/events";
        $webhookSecret = self::getWebhookSecret($provider);

        $adapter->createWebhook($owner, $repositoryName, $webhookUrl, $webhookSecret, ['push', 'pull_request']);
    }

    /**
     * Create a Gitea-based adapter with the correct endpoint.
     */
    private static function createGiteaBasedAdapter(string $provider, Cache $cache): Gitea
    {
        $adapter = new Gitea($cache);
        $endpoint = self::getEndpoint($provider);
        if (!empty($endpoint)) {
            $adapter->setEndpoint($endpoint);
        }
        return $adapter;
    }
}
