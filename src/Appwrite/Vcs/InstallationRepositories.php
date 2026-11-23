<?php

namespace Appwrite\Vcs;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

readonly class InstallationRepositories
{
    /**
     * Owner and name of a repository linked through the installation.
     *
     * @return array{0: string, 1: string}
     */
    public function resolve(Git $adapter, Document $installation, string $providerRepositoryId): array
    {
        try {
            $owner = $adapter->getOwnerName($installation->getAttribute('providerInstallationId', ''), (int) $providerRepositoryId);
            $repositoryName = $adapter->getRepositoryName($providerRepositoryId);

            // Installation-wide webhooks are matched by repository id, so the repository must belong to this installation
            if (\in_array(Git::WEBHOOK_SCOPE_INSTALLATION, $adapter->getSupportedWebhookScopes(), true)) {
                $repository = $adapter->getRepository($owner, $repositoryName);
                if (\strval($repository['id'] ?? '') !== $providerRepositoryId) {
                    throw new RepositoryNotFound('Repository not found.');
                }
                if (!$adapter->hasAccessToAllRepositories()) {
                    $adapter->getInstallationRepository($repositoryName);
                }
            }
        } catch (RepositoryNotFound) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        return [$owner, $repositoryName];
    }
}
