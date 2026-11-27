<?php

namespace Appwrite\Vcs;

use Appwrite\Reviews\Provider;
use Utopia\VCS\Adapter\Git;

/**
 * Where a connected repository lives on its provider: its owner and name, read from the provider
 * by the repository's id with the installation's credential, which fails for a repository the
 * installation cannot reach. A webhook delivery's names are no substitute where the webhook
 * secret is instance-wide, as on GitLab, Gitea and Bitbucket.
 */
final class RepositoryPath
{
    /**
     * Bitbucket's repository id is its full name, workspace and slug; the account's own
     * workspace is no sure owner, so the id's workspace is.
     *
     * @return array{0: string, 1: string} the owner and the name
     * @throws \Exception when the provider names no owner or no name for the repository
     */
    public static function read(Git $vcs, Provider $provider, string $providerInstallationId, string $providerRepositoryId): array
    {
        $name = $vcs->getRepositoryName($providerRepositoryId);
        $owner = $provider === Provider::Bitbucket
            ? \explode('/', \rawurldecode($providerRepositoryId), 2)[0]
            : $vcs->getOwnerName($providerInstallationId, \ctype_digit($providerRepositoryId) ? (int) $providerRepositoryId : null);

        if ($owner === '' || $name === '') {
            throw new \Exception("The provider names no owner or name for repository '{$providerRepositoryId}'.");
        }

        return [$owner, $name];
    }
}
