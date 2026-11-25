<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Event\Publisher\Review as ReviewPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Reviews;
use Appwrite\Platform\Permission;
use Appwrite\Reviews\Strength;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Action
{
    use HTTP;
    use Deployment;
    use Reviews;
    use Permission;

    public static function getName()
    {
        return 'createVCSGitHubEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/github/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcsWebhookSecret')
            ->inject('vcsFactory')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('bus')
            ->inject('getProjectDB')
            ->inject('deploymentsFactory')
            ->inject('platform')
            ->inject('publisherForReviews')
            ->inject('installationTokens')
            ->callback($this->action(...));
    }

    public function action(
        callable $vcsWebhookSecret,
        VcsFactory $vcsFactory,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        callable $deploymentsFactory,
        array $platform,
        ReviewPublisher $publisherForReviews,
        InstallationTokens $installationTokens,
    ) {
        $vcs = $vcsFactory->fromProvider('github');

        $event = $request->getHeaderLine('x-github-event', '');
        Span::add('vcs.github.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeaderLine('x-hub-signature-256', '');
        $secretKey = $vcsWebhookSecret('github');

        $valid = !empty($secretKey) && $vcs->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add('vcs.github.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. Please make sure the webhook secret has same value in your GitHub app and in the _APP_VCS_GITHUB_WEBHOOK_SECRET environment variable');
        }

        // Only a validly-signed delivery is preprocessed (on Cloud this fans the event out to
        // other regions). Running it before the signature check let an unauthenticated POST
        // trigger N cross-region forwards — an amplification vector.
        $this->preprocessEvent($request);

        $parsedPayloads = $vcs->getEvents($event, $payload);

        foreach ($parsedPayloads as $parsedPayload) {
            // Before the deployments, which may throw; queueing a review never does.
            // Without the secret no review service runs (optional on self-hosted), so nothing would consume the job.
            if (System::getEnv('_APP_REVIEWS_SECRET', '') !== '') {
                match ($event) {
                    GitHub::EVENT_PULL_REQUEST => $this->queueReviews('github', $parsedPayload, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews, $platform),
                    GitHub::EVENT_ISSUE_COMMENT, GitHub::EVENT_PULL_REQUEST_REVIEW_COMMENT => $this->queueComment('github', $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews, $platform),
                    GitHub::EVENT_INSTALLATION_REPOSITORIES => $this->createReviewRepositories($parsedPayload, $dbForPlatform, $authorization),
                    default => null,
                };
            }

            match ($event) {
                GitHub::EVENT_INSTALLATION => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
                GitHub::EVENT_PUSH => $this->handlePushEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                GitHub::EVENT_PULL_REQUEST => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                default => null,
            };
        }

        $response->json(['events' => $parsedPayloads]);
    }

    protected function preprocessEvent(Request $request)
    {
        return;
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

                // Every region gets the delivery, and the installation and repository
                // rows are shared, so each region removes only its own projects'
                // ones - or a region that runs later would find nothing to unlink
                // its resources from. A deleted project's rows go wherever this runs.
                if (!$project->isEmpty() && !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                if (!$project->isEmpty()) {
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

    /**
     * Turns code review on for repositories added to an installation, in every project that asked
     * for its new repositories to be reviewed. Repositories are only added here, never removed:
     * one that leaves the installation stops being reviewed because the app can no longer reach it.
     */
    private function createReviewRepositories(array $parsedPayload, Database $dbForPlatform, Authorization $authorization): void
    {
        $providerRepositoryIds = $parsedPayload['repositoriesAdded'] ?? [];
        if (($parsedPayload['action'] ?? '') !== 'added' || empty($providerRepositoryIds)) {
            return;
        }

        $installations = $authorization->skip(fn () => $dbForPlatform->find('installations', [
            Query::equal('providerInstallationId', [$parsedPayload['installationId'] ?? '']),
            Query::equal('provider', ['github']),
            Query::equal('reviewNewRepositories', [true]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        foreach ($installations as $installation) {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $installation->getAttribute('projectId', '')));

            // Every region gets the delivery; each turns review on for its own projects only.
            if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                continue;
            }

            foreach ($providerRepositoryIds as $providerRepositoryId) {
                // The same id as a repository turned on by hand, so one already reviewed is kept as it is.
                try {
                    $authorization->skip(fn () => $dbForPlatform->createDocument('repositories', new Document([
                        '$id' => Id::custom('reviews-' . $project->getSequence() . '-' . $providerRepositoryId),
                        '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                        'installationId' => $installation->getId(),
                        'installationInternalId' => $installation->getSequence(),
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getSequence(),
                        'providerRepositoryId' => $providerRepositoryId,
                        'resourceId' => $project->getId(),
                        'resourceInternalId' => $project->getSequence(),
                        'resourceType' => RESOURCE_TYPE_REVIEWS,
                        'providerPullRequestIds' => [],
                        'strength' => Strength::Normal->value,
                    ])));
                } catch (DuplicateException) {
                    continue;
                }
            }
        }
    }

    /**
     * Repositories with this provider repository id that are connected through the event's installation.
     *
     * @param array<Query> $queries
     * @param ?int $limit Most rows to return; null returns every match. Reviews pass null, because each
     *                    row is a separate project that asked for review and none may be skipped.
     * @return array<Document>
     */
    private function findRepositories(string $providerRepositoryId, string $providerInstallationId, array $queries, Database $dbForPlatform, Authorization $authorization, ?int $limit = 100): array
    {
        $found = [];
        $cursor = null;

        do {
            $page = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                ...$queries,
                Query::limit(100),
                ...($cursor === null ? [] : [Query::cursorAfter($cursor)]),
            ]));

            $installationIds = \array_values(\array_unique(\array_filter(\array_map(fn (Document $repository) => $repository->getAttribute('installationId', ''), $page))));
            $installations = empty($installationIds) ? [] : $authorization->skip(fn () => $dbForPlatform->find('installations', [
                Query::equal('$id', $installationIds),
                Query::equal('provider', ['github']),
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::limit(\count($installationIds)),
            ]));
            $installationIds = \array_map(fn (Document $installation) => $installation->getId(), $installations);

            foreach ($page as $repository) {
                if (\in_array($repository->getAttribute('installationId', ''), $installationIds, true)) {
                    $found[] = $repository;
                }
            }

            $cursor = \count($page) === 100 ? $page[\array_key_last($page)] : null;
        } while ($cursor !== null && ($limit === null || \count($found) < $limit));

        return $limit === null ? $found : \array_slice($found, 0, $limit);
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

    private function handlePushEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $providerBranchDeleted = $parsedPayload["branchDeleted"] ?? false;
        $providerBranch = $parsedPayload["branch"] ?? '';
        $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
        $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
        $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
        $providerInstallationId = $parsedPayload["installationId"] ?? '';
        $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
        $providerCommitHash = $parsedPayload["commitHash"] ?? '';
        $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
        $providerCommitAuthorName = $parsedPayload["headCommitAuthorName"] ?? '';
        $providerCommitAuthorEmail = $parsedPayload["headCommitAuthorEmail"] ?? '';
        $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';
        $providerCommitMessage = $parsedPayload["headCommitMessage"] ?? '';
        $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';

        Span::add('vcs.github.event.repo.id', $providerRepositoryId);
        Span::add('vcs.github.event.repo.name', $providerRepositoryName);
        Span::add('vcs.github.event.branch', $providerBranch);
        Span::add('vcs.github.event.installation.id', $providerInstallationId);

        // Find associated repositories; code review has its own path, see queueReviews()
        $repositories = $this->findRepositories($providerRepositoryId, $providerInstallationId, [Query::equal('resourceType', ['function', 'site'])], $dbForPlatform, $authorization);

        // Only the region hosting a linked project acts on the event, so every other region skips building the adapter.
        $isLinked = \array_any($repositories, function (Document $repository) use ($dbForPlatform, $authorization) {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));

            return !$project->isEmpty() && $this->isProjectInCurrentRegion($project);
        });
        if (!$isLinked) {
            return;
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'github',
            'providerInstallationId' => $providerInstallationId,
        ]));

        // Create new deployment only on push (not committed by us) and not when branch is deleted
        if ($providerCommitAuthorEmail !== APP_VCS_GITHUB_EMAIL && !$providerBranchDeleted) {
            $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];
            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $action = $parsedPayload["action"] ?? '';

        if ($action == "opened" || $action == "reopened" || $action == "synchronize") {
            $providerBranch = $parsedPayload["branch"] ?? '';
            $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
            $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
            $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
            $providerInstallationId = $parsedPayload["installationId"] ?? '';
            $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
            $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
            $providerCommitHash = $parsedPayload["commitHash"] ?? '';
            $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
            $external = $parsedPayload["external"] ?? true;
            $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';
            $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';

            Span::add('vcs.github.event.repo.id', $providerRepositoryId);
            Span::add('vcs.github.event.repo.name', $providerRepositoryName);
            Span::add('vcs.github.event.branch', $providerBranch);
            Span::add('vcs.github.event.installation.id', $providerInstallationId);

            // Ignore sync for non-external. We handle it in push webhook
            if (!$external && $parsedPayload["action"] == "synchronize") {
                return;
            }

            $repositories = $this->findRepositories($providerRepositoryId, $providerInstallationId, [Query::equal('resourceType', ['function', 'site']), Query::orderDesc('$createdAt')], $dbForPlatform, $authorization);

            // Only the region hosting a linked project acts on the event, so every other region skips the GitHub calls below.
            $isLinked = \array_any($repositories, function (Document $repository) use ($dbForPlatform, $authorization) {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));

                return !$project->isEmpty() && $this->isProjectInCurrentRegion($project);
            });
            if (!$isLinked) {
                return;
            }

            $vcs = $vcsFactory->fromInstallation(new Document([
                'provider' => 'github',
                'providerInstallationId' => $providerInstallationId,
            ]));

            try {
                $commitDetails = $vcs->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            } catch (\Throwable $e) {
                Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
                $commitDetails = [];
            }
            $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
            $providerCommitMessage = $commitDetails["commitMessage"] ?? '';

            try {
                $prFiles = $vcs->getPullRequestFiles($providerRepositoryOwner, $providerRepositoryName, $providerPullRequestId);
            } catch (\Throwable $e) {
                // Without affected files, path triggers can't filter, so resources are listed on the pull request whatever paths they watch.
                Console::warning("Failed to fetch files of pull request '{$providerPullRequestId}': " . $e->getMessage());
                $prFiles = [];
            }
            $providerAffectedFiles = [
                ...array_column($prFiles, 'filename'),
                // Only renamed files include previous_filename; skip missing values from other file changes.
                ...array_filter(array_column($prFiles, 'previous_filename'))
            ];

            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        } elseif ($action == "closed") {
            // Allowed external contributions cleanup

            $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
            $providerInstallationId = $parsedPayload["installationId"] ?? '';
            $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
            $external = $parsedPayload["external"] ?? true;

            if ($external) {
                $repositories = $this->findRepositories($providerRepositoryId, $providerInstallationId, [Query::orderDesc('$createdAt')], $dbForPlatform, $authorization);

                foreach ($repositories as $repository) {
                    $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                    if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                        $providerPullRequestIds = \array_values(\array_diff($providerPullRequestIds, [$providerPullRequestId]));
                        $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));
                    }
                }
            }
        }
    }

    protected function getReviewerHandle(Document $installation): string
    {
        return System::getEnv('_APP_VCS_GITHUB_APP_NAME', '');
    }

    /**
     * GitHub names an app's account <slug>[bot]; the suffix is ignored when comparing.
     */
    protected function getReviewerLogin(Document $installation): string
    {
        return System::getEnv('_APP_VCS_GITHUB_APP_NAME', '');
    }

    /**
     * GitHub sends the author's association with each comment.
     */
    protected function getCommentAuthorAssociation(array $parsedPayload, Document $installation, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Database $dbForPlatform): string
    {
        return \strval($parsedPayload['commentAuthorAssociation'] ?? '');
    }
}
