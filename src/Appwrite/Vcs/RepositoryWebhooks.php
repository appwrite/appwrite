<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

class RepositoryWebhooks
{
    public function __construct(
        protected Factory $vcsFactory,
    ) {
    }

    public function ensure(
        Git $adapter,
        Document $installation,
        Database $dbForPlatform,
        string $providerRepositoryId,
        string $owner,
        string $repositoryName,
    ): void {
        $scopes = $adapter->getSupportedWebhookScopes();

        if (\in_array(Git::WEBHOOK_SCOPE_INSTALLATION, $scopes, true)) {
            return;
        }

        if (!\in_array(Git::WEBHOOK_SCOPE_REPOSITORY, $scopes, true)) {
            return;
        }

        // The caller persists the current repository connection before this check.
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

        $endpoint = \rtrim($endpoint, '/');
        if (\str_ends_with($endpoint, '/v1')) {
            $endpoint = \substr($endpoint, 0, -3);
        }

        return $endpoint . '/v1/vcs/' . $provider . '/events';
    }
}
