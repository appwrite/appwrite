<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Event\Message\Review as ReviewMessage;
use Appwrite\Event\Message\ReviewChat as ReviewChatMessage;
use Appwrite\Event\Publisher\Review as ReviewPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
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
                    GitHub::EVENT_PULL_REQUEST => $this->queueReviews($parsedPayload, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews),
                    GitHub::EVENT_ISSUE_COMMENT, GitHub::EVENT_PULL_REQUEST_REVIEW_COMMENT => $this->queueComment($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews),
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
     * Repositories with this provider repository id that are connected through an installation of this provider.
     *
     * @param array<Query> $queries
     * @return array<Document>
     */
    private function findRepositories(string $providerRepositoryId, array $queries, Database $dbForPlatform, Authorization $authorization): array
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
                Query::limit(\count($installationIds)),
            ]));
            $installationIds = \array_map(fn (Document $installation) => $installation->getId(), $installations);

            foreach ($page as $repository) {
                if (\in_array($repository->getAttribute('installationId', ''), $installationIds, true)) {
                    $found[] = $repository;
                }
            }

            $cursor = \count($page) === 100 ? $page[\array_key_last($page)] : null;
        } while ($cursor !== null && \count($found) < 100);

        return \array_slice($found, 0, 100);
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
        $repositories = $this->findRepositories($providerRepositoryId, [Query::equal('resourceType', ['function', 'site'])], $dbForPlatform, $authorization);

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

            $repositories = $this->findRepositories($providerRepositoryId, [Query::equal('resourceType', ['function', 'site']), Query::orderDesc('$createdAt')], $dbForPlatform, $authorization);

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
            $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
            $external = $parsedPayload["external"] ?? true;

            if ($external) {
                $repositories = $this->findRepositories($providerRepositoryId, [Query::orderDesc('$createdAt')], $dbForPlatform, $authorization);

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

    /**
     * Queues a review of the pull request for each project that has code review on for the
     * repository. A review that is still queued for the pull request absorbs the new head
     * instead, so a burst of pushes costs one review; the service reviews the head it finds.
     */
    private function queueReviews(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        ReviewPublisher $publisherForReviews,
    ): void {
        // Drafts are queued too: the service decides from the repository's own config.
        $trigger = match ($parsedPayload['action'] ?? '') {
            'opened', 'reopened' => 'opened',
            'synchronize' => 'synchronize',
            'ready_for_review' => 'ready_for_review',
            default => null,
        };
        if ($trigger === null) {
            return;
        }

        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $providerPullRequestId = \strval($parsedPayload['pullRequestNumber'] ?? '');
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';

        $repositories = $this->findRepositories($providerRepositoryId, [Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS])], $dbForPlatform, $authorization);

        foreach ($repositories as $repository) {
            try {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
                if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);

                // A queued review older than this was lost on the way (dead-lettered, or the
                // service never answered); coalescing into it would silence the pull request.
                $pending = $authorization->skip(fn () => $dbForProject->findOne('reviews', [
                    Query::equal('repositoryInternalId', [$repository->getSequence()]),
                    Query::equal('providerPullRequestId', [$providerPullRequestId]),
                    Query::equal('status', ['queued']),
                    Query::greaterThan('$createdAt', DateTime::addSeconds(new \DateTime(), -APP_REVIEWS_QUEUED_TTL)),
                ]));

                if (!$pending->isEmpty()) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('reviews', $pending->getId(), new Document([
                        'providerCommitHash' => $providerCommitHash,
                    ])));
                    continue;
                }

                $review = $authorization->skip(fn () => $dbForProject->createDocument('reviews', new Document([
                    '$id' => Id::unique(),
                    'repositoryId' => $repository->getId(),
                    'repositoryInternalId' => $repository->getSequence(),
                    'providerRepositoryId' => $providerRepositoryId,
                    'providerRepositoryOwner' => $parsedPayload['owner'] ?? '',
                    'providerRepositoryName' => $parsedPayload['repositoryName'] ?? '',
                    'providerPullRequestId' => $providerPullRequestId,
                    'providerCommitHash' => $providerCommitHash,
                    'trigger' => $trigger,
                    'status' => 'queued',
                ])));

                try {
                    $queued = $publisherForReviews->enqueue(new ReviewMessage(
                        reviewId: $review->getId(),
                        repository: $this->getReviewRepository($parsedPayload, $project, $repository),
                        pullNumber: (int) $providerPullRequestId,
                        headSha: $providerCommitHash,
                        trigger: ['kind' => $trigger],
                    ));

                    if ($queued === false) {
                        throw new \Exception('The broker rejected the review message.');
                    }
                } catch (\Throwable $error) {
                    // Left queued, the review would absorb every later push without running.
                    $authorization->skip(fn () => $dbForProject->updateDocument('reviews', $review->getId(), new Document([
                        'status' => 'failed',
                        'reason' => 'The review could not be queued.',
                    ])));

                    throw $error;
                }
            } catch (\Throwable $error) {
                Console::warning("Failed to queue review for repository '{$repository->getId()}': " . $error->getMessage());
            }
        }
    }

    /**
     * Queues work for a comment on a pull request, for each project that has code review on for
     * the repository: a review when the reviewer is asked to review, an answer otherwise. A reply
     * in a review thread that does not mention the reviewer is queued too; the service answers it
     * only in a thread on one of its own findings. Edits and deletions queue nothing, and each
     * comment queues at most once, however often GitHub delivers it.
     */
    private function queueComment(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        ReviewPublisher $publisherForReviews,
    ): void {
        // Never another app's comment, which keeps the reviewer from answering itself in a loop.
        if (($parsedPayload['action'] ?? '') !== 'created' || ($parsedPayload['commentAuthorType'] ?? '') === 'Bot') {
            return;
        }

        $commentId = (int) ($parsedPayload['commentId'] ?? 0);
        $commentKind = $parsedPayload['commentKind'] ?? '';
        $replyToId = (int) ($parsedPayload['commentReplyToId'] ?? 0);
        $pullNumber = (int) ($parsedPayload['pullRequestNumber'] ?? 0);
        if ($commentId <= 0 || $pullNumber <= 0) {
            return;
        }

        $request = $this->getMentionRequest($parsedPayload['commentBody'] ?? '', System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''));
        $unmentioned = $request === null;
        if ($unmentioned && ($commentKind !== 'review' || $replyToId === 0)) {
            return;
        }

        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $repositories = $this->findRepositories($providerRepositoryId, [Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS])], $dbForPlatform, $authorization);
        if (empty($repositories)) {
            return;
        }

        // "@reviewer review" asks for a review of the current head; anything else is a question.
        $pullRequest = null;
        $command = $request === null ? false : \strtok($request, " \t\r\n");
        if ($command !== false && \strtolower(\rtrim($command, '.,:;!?')) === 'review') {
            $vcs = $vcsFactory->fromInstallation(new Document([
                'provider' => 'github',
                'providerInstallationId' => $parsedPayload['installationId'] ?? '',
            ]));
            if (!$vcs instanceof GitHub) {
                return;
            }

            try {
                $pullRequest = $vcs->getPullRequest($parsedPayload['owner'] ?? '', $parsedPayload['repositoryName'] ?? '', $pullNumber);
            } catch (\Throwable $error) {
                Console::warning("Failed to read pull request #{$pullNumber} for a review request: " . $error->getMessage());
                return;
            }

            if (\strlen($pullRequest['head']['sha'] ?? '') !== 40) {
                return;
            }

            // A review is a model run the project pays for: the author, and people with write access.
            // Association labels don't say that (MEMBER is any org member, COLLABORATOR may be read-only).
            $author = $parsedPayload['commentAuthor'] ?? '';
            if (($pullRequest['user']['login'] ?? null) !== $author) {
                try {
                    $permission = $vcs->getCollaboratorPermission($parsedPayload['owner'] ?? '', $parsedPayload['repositoryName'] ?? '', $author);
                } catch (\Throwable $error) {
                    Console::warning("Failed to read {$author}'s permission for a review request: " . $error->getMessage());
                    return;
                }

                if (!\in_array($permission, ['admin', 'write'], true)) {
                    return;
                }
            }
        }

        foreach ($repositories as $repository) {
            try {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
                if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);

                if ($pullRequest !== null) {
                    $this->queueMentionReview($parsedPayload, $pullRequest, $project, $repository, $dbForProject, $authorization, $publisherForReviews);
                    continue;
                }

                // The row's id is the claim: a redelivery of the comment fails to create it again.
                try {
                    $chat = $authorization->skip(fn () => $dbForProject->createDocument('reviewChats', new Document([
                        '$id' => 'chat-' . $commentId,
                        'repositoryId' => $repository->getId(),
                        'repositoryInternalId' => $repository->getSequence(),
                        'providerPullRequestId' => (string) $pullNumber,
                        'providerCommentId' => (string) $commentId,
                        'kind' => $commentKind,
                        'author' => $parsedPayload['commentAuthor'] ?? '',
                    ])));
                } catch (DuplicateException) {
                    continue;
                }

                $comment = [
                    'id' => $commentId,
                    'kind' => $commentKind,
                    'author' => $parsedPayload['commentAuthor'] ?? '',
                    'authorAssociation' => $parsedPayload['commentAuthorAssociation'] ?? '',
                    'url' => $parsedPayload['commentUrl'] ?? '',
                ];
                // Replies in a review thread attach to its first comment.
                if ($replyToId > 0) {
                    $comment['rootCommentId'] = $replyToId;
                }

                try {
                    $queued = $publisherForReviews->enqueue(new ReviewChatMessage(
                        repository: $this->getReviewRepository($parsedPayload, $project, $repository),
                        pullNumber: $pullNumber,
                        comment: $comment,
                        unmentioned: $unmentioned,
                    ));

                    if ($queued === false) {
                        throw new \Exception('The broker rejected the chat message.');
                    }
                } catch (\Throwable $error) {
                    // Released, so a redelivery of the comment can queue it again.
                    $authorization->skip(fn () => $dbForProject->deleteDocument('reviewChats', $chat->getId()));

                    throw $error;
                }
            } catch (\Throwable $error) {
                Console::warning("Failed to queue comment for repository '{$repository->getId()}': " . $error->getMessage());
            }
        }
    }

    /**
     * Queues a review asked for in a comment. It never coalesces into a queued review: that one
     * carries an automatic trigger, which the repository's settings may skip.
     */
    private function queueMentionReview(
        array $parsedPayload,
        array $pullRequest,
        Document $project,
        Document $repository,
        Database $dbForProject,
        Authorization $authorization,
        ReviewPublisher $publisherForReviews,
    ): void {
        $commentId = (int) $parsedPayload['commentId'];
        $headSha = $pullRequest['head']['sha'] ?? '';

        try {
            $review = $authorization->skip(fn () => $dbForProject->createDocument('reviews', new Document([
                // One review per comment, however often GitHub delivers it.
                '$id' => 'mention-' . $commentId,
                'repositoryId' => $repository->getId(),
                'repositoryInternalId' => $repository->getSequence(),
                'providerRepositoryId' => $parsedPayload['repositoryId'] ?? '',
                'providerRepositoryOwner' => $parsedPayload['owner'] ?? '',
                'providerRepositoryName' => $parsedPayload['repositoryName'] ?? '',
                'providerPullRequestId' => (string) $parsedPayload['pullRequestNumber'],
                'providerCommitHash' => $headSha,
                'trigger' => 'mention',
                'providerCommentId' => (string) $commentId,
                'status' => 'queued',
            ])));
        } catch (DuplicateException) {
            // A redelivered comment queues again only if its first attempt failed to. The row lock
            // lets one of two concurrent redeliveries take it back from failed.
            $review = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $commentId): ?Document {
                $review = $dbForProject->getDocument('reviews', 'mention-' . $commentId, forUpdate: true);
                if ($review->getAttribute('status') !== 'failed') {
                    return null;
                }

                return $dbForProject->updateDocument('reviews', $review->getId(), new Document([
                    'status' => 'queued',
                    'reason' => '',
                ]));
            }));

            if ($review === null) {
                return;
            }
        }

        try {
            $queued = $publisherForReviews->enqueue(new ReviewMessage(
                reviewId: $review->getId(),
                repository: $this->getReviewRepository($parsedPayload, $project, $repository),
                pullNumber: (int) $parsedPayload['pullRequestNumber'],
                headSha: $headSha,
                trigger: ['kind' => 'mention', 'comment' => ['id' => $commentId, 'kind' => $parsedPayload['commentKind']]],
            ));

            if ($queued === false) {
                throw new \Exception('The broker rejected the review message.');
            }
        } catch (\Throwable $error) {
            $authorization->skip(fn () => $dbForProject->updateDocument('reviews', $review->getId(), new Document([
                'status' => 'failed',
                'reason' => 'The review could not be queued.',
            ])));

            throw $error;
        }
    }

    /**
     * What a comment asks the reviewer, the text after its first mention of the GitHub App, or
     * null when it does not mention it. A longer handle or an email address is not a mention.
     */
    private function getMentionRequest(string $body, string $handle): ?string
    {
        if ($handle === '') {
            return null;
        }

        $lower = \strtolower($body);
        $needle = '@' . \strtolower($handle);
        $offset = 0;

        while (($position = \strpos($lower, $needle, $offset)) !== false) {
            $end = $position + \strlen($needle);
            $before = $position > 0 ? $lower[$position - 1] : ' ';
            $after = $end < \strlen($lower) ? $lower[$end] : ' ';

            if (!\ctype_alnum($before) && !\ctype_alnum($after) && $after !== '-' && $after !== '_') {
                return \trim(\substr($body, $end));
            }

            $offset = $end;
        }

        return null;
    }

    /**
     * The repository as the review service's jobs name it.
     *
     * @return array{projectId: string, projectInternalId: string, repositoryId: string, installationId: string, providerInstallationId: string, providerRepositoryId: string, owner: string, name: string}
     */
    private function getReviewRepository(array $parsedPayload, Document $project, Document $repository): array
    {
        return [
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'repositoryId' => $repository->getId(),
            'installationId' => $repository->getAttribute('installationId', ''),
            'providerInstallationId' => $parsedPayload['installationId'] ?? '',
            'providerRepositoryId' => $parsedPayload['repositoryId'] ?? '',
            'owner' => $parsedPayload['owner'] ?? '',
            'name' => $parsedPayload['repositoryName'] ?? '',
        ];
    }
}
