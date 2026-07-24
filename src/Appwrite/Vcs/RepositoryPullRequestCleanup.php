<?php

namespace Appwrite\Vcs;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Untracks a closed/merged pull request from every repository connected to
 * it, scoped to a single provider (a GitHub and a GitLab repository can
 * share the same numeric providerRepositoryId, since each is scoped to its
 * own server).
 */
class RepositoryPullRequestCleanup
{
    public function remove(
        Database $dbForPlatform,
        Authorization $authorization,
        string $provider,
        string $providerRepositoryId,
        int|string $providerPullRequestId,
    ): void {
        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::orderDesc('$createdAt'),
            Query::limit(100),
        ]));

        foreach ($repositories as $repository) {
            $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $repository->getAttribute('installationId', '')));

            if ($installation->isEmpty() || $installation->getAttribute('provider', 'github') !== $provider) {
                continue;
            }

            $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

            if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));
            }
        }
    }
}
