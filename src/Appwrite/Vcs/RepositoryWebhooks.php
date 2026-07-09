<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\VCS\Adapter\Git;

/**
 * Creates per-repository webhooks for self-hosted providers.
 *
 * GitHub App deliveries need no per-repository webhook, so no endpoint calls
 * this yet; it exists so a self-hosted OAuth2 provider (e.g. Gitea) can wire
 * into it without inventing its own idempotency check.
 */
class RepositoryWebhooks
{
    /**
     * Creates a webhook for $owner/$repositoryName unless a `repositories`
     * document already exists for this installation + provider repository
     * (an earlier connection already went through this path).
     */
    public function ensure(
        Git $adapter,
        Document $installation,
        Database $dbForPlatform,
        string $providerRepositoryId,
        string $owner,
        string $repositoryName,
        string $url,
        string $secret,
    ): void {
        $connections = $dbForPlatform->count('repositories', [
            Query::equal('installationInternalId', [$installation->getSequence()]),
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
        ], 2);

        if ($connections > 1) {
            return;
        }

        try {
            $adapter->createWebhook($owner, $repositoryName, $url, $secret);
        } catch (\Throwable $error) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to create repository webhook: ' . $error->getMessage());
        }
    }
}
