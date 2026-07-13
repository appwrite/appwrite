<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Creates per-repository webhooks for self-hosted providers.
 *
 * Callers invoke this unconditionally on every repository connection.
 * Prefers installation-scope delivery when the adapter supports it (e.g.
 * GitHub App, where events already arrive platform-wide) and only falls
 * back to creating a per-repository webhook otherwise, so no endpoint
 * needs its own per-provider branching.
 */
class RepositoryWebhooks
{
    public function __construct(
        protected Factory $vcsFactory,
    ) {
    }

    /**
     * Creates a webhook for $owner/$repositoryName unless the adapter
     * already delivers events at the installation level, or a
     * `repositories` document already exists for this installation +
     * provider repository (an earlier connection already went through this
     * path).
     *
     * Callers must persist the current repository connection's document
     * (via createDocument) before calling this — the idempotency check
     * counts existing `repositories` rows for this installation +
     * providerRepositoryId and expects to see itself already included
     * (count 1 on first connection, 2+ on any repeat).
     */
    public function ensure(
        Git $adapter,
        Document $installation,
        Database $dbForPlatform,
        string $providerRepositoryId,
        string $owner,
        string $repositoryName,
    ): void {
        $scopes = $adapter->getSupportedWebhookScopes();

        // Installation-scope delivery is preferred when available -- it
        // already covers every repository the integration has access to,
        // so a separate per-repository webhook would be redundant.
        if (\in_array(Git::WEBHOOK_SCOPE_INSTALLATION, $scopes, true)) {
            return;
        }

        if (!\in_array(Git::WEBHOOK_SCOPE_REPOSITORY, $scopes, true)) {
            return;
        }

        $connections = $dbForPlatform->count('repositories', [
            Query::equal('installationInternalId', [$installation->getSequence()]),
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
        ], 2);

        if ($connections > 1) {
            return;
        }

        $provider = $installation->getAttribute('provider', '');
        if (empty($provider)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing VCS provider for installation: ' . $installation->getId());
        }

        $url = $this->buildWebhookUrl($provider);
        $secret = $this->vcsFactory->getWebhookSecret($provider);

        try {
            $adapter->createWebhook($owner, $repositoryName, $url, $secret);
        } catch (\Throwable $error) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to create repository webhook: ' . $error->getMessage());
        }
    }

    protected function buildWebhookUrl(string $provider): string
    {
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

        return $endpoint . '/v1/vcs/' . $provider . '/events';
    }
}
