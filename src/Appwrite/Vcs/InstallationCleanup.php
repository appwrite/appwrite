<?php

namespace Appwrite\Vcs;

use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\System\System;

/**
 * Detaches everything an installation held once it is removed at the provider:
 * every function and site it fed loses its repository, and the repository and
 * installation records go with it. Scoped to a single provider, since an
 * installation id only identifies an installation on its own provider.
 */
class InstallationCleanup
{
    public function remove(
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        string $provider,
        string $providerInstallationId,
    ): void {
        $installationCursor = null;
        do {
            $installationQueries = [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('provider', [$provider]),
                Query::limit(1000),
            ];
            if ($installationCursor !== null) {
                $installationQueries[] = Query::cursorAfter($installationCursor);
            }
            $installations = $authorization->skip(fn () => $dbForPlatform->find('installations', $installationQueries));

            foreach ($installations as $installation) {
                $projectId = $installation->getAttribute('projectId', '');
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                if (!$project->isEmpty() && $this->isProjectInCurrentRegion($project)) {
                    $this->detachResources($installation, $project, $authorization, $getProjectDB);
                }

                $this->deleteRepositories($installation, $dbForPlatform, $authorization);

                $authorization->skip(fn () => $dbForPlatform->deleteDocument('installations', $installation->getId()));
            }

            $installationCursor = count($installations) === 1000 ? $installations[array_key_last($installations)] : null;
        } while ($installationCursor !== null);
    }

    private function detachResources(
        Document $installation,
        Document $project,
        Authorization $authorization,
        callable $getProjectDB,
    ): void {
        $dbForProject = $getProjectDB($project);

        foreach (['functions', 'sites'] as $collection) {
            $cursor = null;
            do {
                $queries = [
                    Query::equal('installationInternalId', [$installation->getSequence()]),
                    Query::limit(1000),
                ];
                if ($cursor !== null) {
                    $queries[] = Query::cursorAfter($cursor);
                }
                $resources = $authorization->skip(fn () => $dbForProject->find($collection, $queries));

                foreach ($resources as $resource) {
                    $authorization->skip(fn () => $dbForProject->updateDocument($collection, $resource->getId(), new Document([
                        'installationId' => '',
                        'installationInternalId' => '',
                        'providerRepositoryId' => '',
                        'providerBranch' => '',
                        'providerSilentMode' => false,
                        'providerRootDirectory' => '',
                        'repositoryId' => '',
                        'repositoryInternalId' => '',
                    ])));
                }

                $cursor = count($resources) === 1000 ? $resources[array_key_last($resources)] : null;
            } while ($cursor !== null);
        }
    }

    private function deleteRepositories(
        Document $installation,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $cursor = null;
        do {
            $queries = [
                Query::equal('installationInternalId', [$installation->getSequence()]),
                Query::limit(1000),
            ];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }
            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', $queries));

            foreach ($repositories as $repository) {
                $authorization->skip(fn () => $dbForPlatform->deleteDocument('repositories', $repository->getId()));
            }

            $cursor = count($repositories) === 1000 ? $repositories[array_key_last($repositories)] : null;
        } while ($cursor !== null);
    }

    private function isProjectInCurrentRegion(Document $project): bool
    {
        try {
            $dsn = new DSN($project->getAttribute('database'));
            $databaseName = $dsn->getHost();
        } catch (\InvalidArgumentException) {
            $databaseName = $project->getAttribute('database');
        }

        $databases = Config::getParam('pools-database', []);
        if (!\in_array($databaseName, $databases)) {
            Console::warning("Skipping project {$project->getId()}: database '{$databaseName}' is not part of region " . System::getEnv('_APP_REGION'));
            return false;
        }

        return true;
    }
}
