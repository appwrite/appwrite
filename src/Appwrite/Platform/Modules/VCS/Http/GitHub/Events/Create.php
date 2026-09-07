<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Platform\Modules\VCS\Http\Events\Base;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    public static function getName()
    {
        return 'createVCSGitHubEvent';
    }

    public static function getProvider(): string
    {
        return 'github';
    }

    public static function getProviderName(): string
    {
        return 'GitHub';
    }

    protected function getCommitEmail(): string
    {
        return APP_VCS_GITHUB_EMAIL;
    }

    protected function getPushEvents(): array
    {
        return [GitHub::EVENT_PUSH];
    }

    protected function getPullRequestEvents(): array
    {
        return [GitHub::EVENT_PULL_REQUEST];
    }

    /**
     * A GitHub app can be registered without a webhook secret.
     */
    protected function requiresWebhookSecret(): bool
    {
        return false;
    }

    protected function handleEvent(
        string $event,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ): void {
        if ($event === GitHub::EVENT_INSTALLATION) {
            $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB);
            return;
        }

        parent::handleEvent($event, $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
    }

    /**
     * A GitHub app acts for the installation named in the delivery, so one
     * adapter serves every repository and no stored token is involved.
     */
    protected function resolveAdapters(
        array $repositories,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        array &$errors,
    ): array {
        $providerInstallationId = $parsedPayload['installationId'] ?? '';
        Span::add('vcs.github.event.installation.id', $providerInstallationId);

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'github',
            'providerInstallationId' => $providerInstallationId,
        ]));

        return [[$vcs, $providerInstallationId, $repositories]];
    }

    protected function handleInstallationEvent(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
    ) {
        if ($parsedPayload["action"] !== "deleted") {
            return;
        }

        $providerInstallationId = $parsedPayload["installationId"];

        $installationCursor = null;
        do {
            $installationQueries = [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('provider', ['github']),
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

                $authorization->skip(fn () => $dbForPlatform->deleteDocument('installations', $installation->getId()));
            }

            $installationCursor = count($installations) === 1000 ? $installations[array_key_last($installations)] : null;
        } while ($installationCursor !== null);
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
