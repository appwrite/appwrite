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
                    GitHub::EVENT_PULL_REQUEST => $this->queueReviews($parsedPayload, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews, $platform),
                    GitHub::EVENT_ISSUE_COMMENT, GitHub::EVENT_PULL_REQUEST_REVIEW_COMMENT => $this->queueComment($parsedPayload, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews, $platform),
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
     * Repositories with this provider repository id that are connected through the event's installation.
     *
     * @param array<Query> $queries
     * @return array<Document>
     */
    private function findRepositories(string $providerRepositoryId, string $providerInstallationId, array $queries, Database $dbForPlatform, Authorization $authorization): array
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

    /**
     * Queues a review of the pull request for each project that has code review on for the
     * repository, as hansi.codes does: reviews still queued for the pull request are superseded
     * by the new one, so a burst of pushes runs one review, of the head it finds when it runs.
     */
    private function queueReviews(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        ReviewPublisher $publisherForReviews,
        array $platform,
    ): void {
        // Drafts are queued too: the service decides from the repository's own config.
        $trigger = match ($parsedPayload['action'] ?? '') {
            'opened', 'reopened', 'ready_for_review' => 'opened',
            'synchronize' => 'synchronize',
            default => null,
        };
        if ($trigger === null) {
            return;
        }

        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $pullNumber = (int) ($parsedPayload['pullRequestNumber'] ?? 0);
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';

        $repositories = $this->findRepositories($providerRepositoryId, $parsedPayload['installationId'] ?? '', [Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS])], $dbForPlatform, $authorization);

        foreach ($repositories as $repository) {
            try {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
                if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                $this->queueReview(
                    $parsedPayload,
                    $project,
                    $repository,
                    $getProjectDB($project),
                    $authorization,
                    $publisherForReviews,
                    $platform,
                    $pullNumber,
                    $providerCommitHash,
                    ['kind' => $trigger],
                    Id::unique(),
                );
            } catch (\Throwable $error) {
                Console::warning("Failed to queue review for repository '{$repository->getId()}': " . $error->getMessage());
            }
        }
    }

    /**
     * Queues work for a comment on a pull request, for each project that has code review on for
     * the repository, the way hansi.codes does: a review for "@app review", an answer for any
     * other mention. A comment without a mention is queued when it may be talking to the
     * reviewer: a reply in a review thread, or a conversation comment on a pull request with
     * findings that were listed in a review body; the service answers only if it is. Edits and
     * deletions queue nothing, and each comment queues at most once, however often GitHub
     * delivers it.
     */
    private function queueComment(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        ReviewPublisher $publisherForReviews,
        array $platform,
    ): void {
        $commentId = (int) ($parsedPayload['commentId'] ?? 0);
        $commentKind = $parsedPayload['commentKind'] ?? '';
        $replyToId = (int) ($parsedPayload['commentReplyToId'] ?? 0);
        $pullNumber = (int) ($parsedPayload['pullRequestNumber'] ?? 0);
        if (($parsedPayload['action'] ?? '') !== 'created' || $commentId <= 0 || $pullNumber <= 0) {
            return;
        }

        $slug = System::getEnv('_APP_VCS_GITHUB_APP_NAME', '');
        // Every answer is a model run the project pays for: trusted humans and other apps may ask, never the reviewer itself.
        if (!$this->canTriggerFromComment($parsedPayload['commentAuthor'] ?? '', $parsedPayload['commentAuthorType'] ?? '', $parsedPayload['commentAuthorAssociation'] ?? '', $slug)) {
            return;
        }

        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $repositories = $this->findRepositories($providerRepositoryId, $parsedPayload['installationId'] ?? '', [Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS])], $dbForPlatform, $authorization);
        if (empty($repositories)) {
            return;
        }

        $intent = $this->getMentionIntent($parsedPayload['commentBody'] ?? '', $slug);
        $isReviewThread = $commentKind === 'review';

        foreach ($repositories as $repository) {
            try {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
                if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);

                if ($intent === 'review') {
                    // The service reviews whatever the head is when it runs, so no head is read here.
                    $this->queueReview(
                        $parsedPayload,
                        $project,
                        $repository,
                        $dbForProject,
                        $authorization,
                        $publisherForReviews,
                        $platform,
                        $pullNumber,
                        '',
                        ['kind' => 'mention', 'comment' => ['id' => $commentId, 'kind' => $commentKind]],
                        // One review per comment, however often GitHub delivers it.
                        'mention-' . $commentId,
                    );
                    continue;
                }

                $unmentioned = $intent === null;
                if ($unmentioned && !($isReviewThread ? $replyToId > 0 : $this->hasOpenBodyFindings($repository, $pullNumber, $dbForProject, $authorization))) {
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
                // A review thread's replies attach to its first comment, which this one is when it replies to nothing.
                if ($isReviewThread) {
                    $comment['rootCommentId'] = $replyToId > 0 ? $replyToId : $commentId;
                }

                try {
                    $queued = $publisherForReviews->enqueue(new ReviewChatMessage(
                        repository: $this->getReviewRepository($parsedPayload, $project, $repository, $platform),
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
     * Creates a review and publishes it. Reviews still queued for the pull request are
     * superseded first; one that is already running is left alone, and the service skips the new
     * one when it finds the same head reviewed.
     *
     * @param array{kind: string, comment?: array{id: int, kind: string}} $trigger
     */
    private function queueReview(
        array $parsedPayload,
        Document $project,
        Document $repository,
        Database $dbForProject,
        Authorization $authorization,
        ReviewPublisher $publisherForReviews,
        array $platform,
        int $pullNumber,
        string $headSha,
        array $trigger,
        string $reviewId,
    ): void {
        $review = new Document([
            '$id' => $reviewId,
            'repositoryId' => $repository->getId(),
            'repositoryInternalId' => $repository->getSequence(),
            'providerRepositoryId' => $parsedPayload['repositoryId'] ?? '',
            'providerRepositoryOwner' => $parsedPayload['owner'] ?? '',
            'providerRepositoryName' => $parsedPayload['repositoryName'] ?? '',
            'providerPullRequestId' => (string) $pullNumber,
            'providerCommitHash' => $headSha,
            'trigger' => $trigger['kind'],
            'providerCommentId' => isset($trigger['comment']) ? (string) $trigger['comment']['id'] : '',
            'status' => 'queued',
        ]);

        // Older queued reviews of the pull request are superseded before this one is created, as
        // hansi.codes does: two overlapping deliveries then both stay queued, and the worker skips
        // the one that resolves to an already reviewed head, instead of superseding each other.
        $authorization->skip(fn () => $dbForProject->updateDocuments('reviews', new Document([
            'status' => 'superseded',
        ]), [
            Query::equal('repositoryInternalId', [$repository->getSequence()]),
            Query::equal('providerPullRequestId', [(string) $pullNumber]),
            Query::equal('status', ['queued']),
            Query::notEqual('$id', $reviewId),
        ]));

        try {
            $review = $authorization->skip(fn () => $dbForProject->createDocument('reviews', $review));
        } catch (DuplicateException) {
            // A redelivered comment queues again only if its first attempt failed to. The row lock
            // lets one of two concurrent redeliveries take it back from failed.
            $review = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $reviewId): ?Document {
                $review = $dbForProject->getDocument('reviews', $reviewId, forUpdate: true);
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
                repository: $this->getReviewRepository($parsedPayload, $project, $repository, $platform),
                pullNumber: $pullNumber,
                headSha: $headSha,
                trigger: $trigger,
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
     * Whether a pull request has an open finding that was listed in a review body rather than
     * posted inline. Such a finding has no thread, so a conversation comment may answer it.
     */
    private function hasOpenBodyFindings(Document $repository, int $pullNumber, Database $dbForProject, Authorization $authorization): bool
    {
        $reviews = $authorization->skip(fn () => $dbForProject->find('reviews', [
            Query::equal('repositoryInternalId', [$repository->getSequence()]),
            Query::equal('providerPullRequestId', [(string) $pullNumber]),
            Query::equal('status', ['completed']),
            Query::limit(APP_LIMIT_REVIEWS_PULL),
        ]));
        if (empty($reviews)) {
            return false;
        }

        $finding = $authorization->skip(fn () => $dbForProject->findOne('reviewFindings', [
            Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $reviews)),
            Query::equal('status', ['posted']),
            Query::equal('providerCommentId', ['']),
        ]));

        return !$finding->isEmpty();
    }

    /**
     * Whether a comment may queue work. Humans need an owner, member or collaborator association;
     * other GitHub Apps may ask too (an agent answering a finding), but never the reviewer
     * itself, so it does not answer itself in a loop.
     */
    private function canTriggerFromComment(string $login, string $type, string $association, string $slug): bool
    {
        if ($type === 'Bot') {
            $name = \str_ends_with(\strtolower($login), '[bot]') ? \substr($login, 0, -\strlen('[bot]')) : $login;

            return \strtolower($name) !== \strtolower($slug);
        }

        return \in_array($association, ['OWNER', 'MEMBER', 'COLLABORATOR'], true);
    }

    /**
     * What a comment asks of the reviewer: 'review' for "@app review" or "@app full review",
     * 'chat' for any other mention, or null when it does not mention the app. Mentions in code
     * and in quoted lines do not count, and a longer handle such as "@app-dev" is not a mention.
     */
    private function getMentionIntent(string $body, string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        $text = \strtolower($this->stripQuotedText($body));
        $needle = '@' . \strtolower($slug);
        $intent = null;
        $offset = 0;

        while (($position = \strpos($text, $needle, $offset)) !== false) {
            $offset = $position + \strlen($needle);
            $next = $text[$offset] ?? '';
            if ($next !== '' && (\ctype_alnum($next) || $next === '_' || $next === '-')) {
                continue;
            }

            $intent = 'chat';
            $rest = \substr($text, $offset);
            $words = \ltrim($rest);
            if ($words === $rest) {
                continue;
            }
            if (\str_starts_with($words, 'full') && \ltrim(\substr($words, 4)) !== \substr($words, 4)) {
                $words = \ltrim(\substr($words, 4));
            }
            $after = $words[\strlen('review')] ?? '';
            if (\str_starts_with($words, 'review') && ($after === '' || !(\ctype_alnum($after) || $after === '_'))) {
                return 'review';
            }
        }

        return $intent;
    }

    /**
     * A comment without its fenced code blocks, inline code spans and quoted lines.
     */
    private function stripQuotedText(string $body): string
    {
        $text = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length;) {
            if (\substr($body, $i, 3) === '```') {
                $close = \strpos($body, '```', $i + 3);
                if ($close !== false) {
                    $i = $close + 3;
                    continue;
                }
            } elseif ($body[$i] === '`') {
                $close = \strpos($body, '`', $i + 1);
                if ($close !== false && !\str_contains(\substr($body, $i + 1, $close - $i - 1), "\n")) {
                    $i = $close + 1;
                    continue;
                }
            }
            $text .= $body[$i];
            $i++;
        }

        $lines = \array_filter(\explode("\n", $text), fn (string $line) => !\str_starts_with(\ltrim($line), '>'));

        return \implode("\n", $lines);
    }

    /**
     * The repository as the review service's jobs name it.
     *
     * @return array{projectId: string, projectInternalId: string, repositoryId: string, installationId: string, providerInstallationId: string, providerRepositoryId: string, owner: string, name: string, consoleUrl?: string}
     */
    private function getReviewRepository(array $parsedPayload, Document $project, Document $repository, array $platform): array
    {
        $reviewRepository = [
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'repositoryId' => $repository->getId(),
            'installationId' => $repository->getAttribute('installationId', ''),
            'providerInstallationId' => $parsedPayload['installationId'] ?? '',
            'providerRepositoryId' => $parsedPayload['repositoryId'] ?? '',
            'owner' => $parsedPayload['owner'] ?? '',
            'name' => $parsedPayload['repositoryName'] ?? '',
        ];

        // For links to the review, from its check run and the summary comment.
        $consoleUrl = $platform['consoleUrl'] ?? '';
        if ($consoleUrl !== '') {
            $reviewRepository['consoleUrl'] = $consoleUrl;
        }

        return $reviewRepository;
    }
}
