<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Events;

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
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\Origin;

class Create extends Action
{
    use HTTP;
    use Deployment;

    /**
     * Origin's active signing keys, cached for the worker's lifetime. The set
     * rotates rarely and a delivery that fails on a stale set refetches once.
     *
     * @var array<string>|null
     */
    protected static ?array $signingKeys = null;

    public static function getName()
    {
        return 'createVCSOriginEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/origin/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcsFactory')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('bus')
            ->inject('getProjectDB')
            ->inject('deploymentsFactory')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        VcsFactory $vcsFactory,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        callable $deploymentsFactory,
        array $platform
    ) {
        /** @var Origin $vcs */
        $vcs = $vcsFactory->fromProvider('origin');

        $event = $request->getHeaderLine($vcs->getEventHeaderName(), '');
        Span::add('vcs.origin.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $deliveryId = $request->getHeaderLine('webhook-id', '');
        $timestamp = $request->getHeaderLine('webhook-timestamp', '');

        // Origin signs the SHA-256 of "<webhook-id>.<webhook-timestamp>.<raw body>"
        // with its own Ed25519 key, verified against its published JWKS rather
        // than a shared secret. Stale timestamps are replays.
        $valid = false;
        if (!empty($deliveryId) && \ctype_digit($timestamp) && \abs(\time() - (int) $timestamp) <= 300) {
            $signedContent = $deliveryId . '.' . $timestamp . '.' . $payload;

            foreach ($this->signingKeys($vcs) as $publicKey) {
                if ($vcs->validateWebhookEvent($signedContent, $signature, $publicKey)) {
                    $valid = true;
                    break;
                }
            }

            // The key set may have rotated since it was cached.
            if (!$valid) {
                foreach ($this->signingKeys($vcs, refresh: true) as $publicKey) {
                    if ($vcs->validateWebhookEvent($signedContent, $signature, $publicKey)) {
                        $valid = true;
                        break;
                    }
                }
            }
        }

        Span::add('vcs.origin.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. The delivery could not be verified against Origin\'s published signing keys.');
        }

        $this->preprocessEvent($request);

        $parsedPayloads = $vcs->getEvents($event, $payload);

        // Origin delivers at least once and retries anything not acknowledged
        // with a 2xx in time. Processing here is synchronous and can outlast
        // that window, so a push or pull request claims the delivery for each
        // repository before deploying it - a retry of a delivery already being
        // processed must not deploy again.
        // TODO: acknowledge first and process asynchronously instead.
        // One push can update several branches; a branch that keeps failing
        // must not hold back the ones after it, so each is handled on its own
        // and the first failure fails the delivery.
        $failure = null;
        foreach ($parsedPayloads as $parsedPayload) {
            try {
                match (true) {
                    $event === Origin::EVENT_PUSH => $this->handlePushEvent($parsedPayload, $deliveryId, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                    \str_starts_with($event, Origin::EVENT_PULL_REQUEST . '.') => $this->handlePullRequestEvent($parsedPayload, $deliveryId, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                    \str_starts_with($event, Origin::EVENT_INSTALLATION . '.') => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
                    default => null,
                };
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }

        // Runs whatever happened above, so work elsewhere never waits on this
        // one; its failure fails the delivery as well, and this one's own
        // failure is the one reported.
        try {
            $this->postprocessEvent($request);
        } catch (\Throwable $error) {
            $failure ??= $error;
        }

        if ($failure !== null) {
            throw $failure;
        }

        $response->json(['events' => $parsedPayloads]);
    }

    /**
     * Runs once a delivery has verified, before it is handled, like GitHub's.
     */
    protected function preprocessEvent(Request $request)
    {
        return;
    }

    /**
     * Runs once this region has handled a delivery, before it is acknowledged.
     * Throwing fails the delivery, so Origin delivers it again, and each
     * repository that already deployed it has it claimed and skips it. Cloud
     * overrides it to forward the delivery to its other regions and fail the
     * delivery when one of them did not take it.
     */
    protected function postprocessEvent(Request $request): void
    {
    }

    /**
     * Origin's active signing keys, from the adapter, cached for the worker's
     * lifetime - the adapter memoizes only per instance, and a new adapter is
     * built for every delivery.
     *
     * @return array<string>
     */
    protected function signingKeys(Origin $vcs, bool $refresh = false): array
    {
        if (!$refresh && self::$signingKeys !== null) {
            return self::$signingKeys;
        }

        $keys = [];

        try {
            $keys = $vcs->getSigningKeys($refresh);
        } catch (\Throwable $e) {
            Console::warning('Failed to fetch Origin signing keys: ' . $e->getMessage());
        }

        // Never cache an empty set - a fetch hiccup would reject deliveries
        // until the worker restarts.
        if (!empty($keys)) {
            self::$signingKeys = $keys;
        }

        return self::$signingKeys ?? [];
    }

    protected function handleInstallationEvent(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
    ) {
        if ($parsedPayload['action'] !== 'deleted') {
            return;
        }

        $providerInstallationId = $parsedPayload['installationId'];

        $installationCursor = null;
        do {
            $installationQueries = [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('provider', ['origin']),
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
                Query::equal('provider', ['origin']),
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
        string $deliveryId,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $providerBranchDeleted = $parsedPayload['branchDeleted'] ?? false;
        $providerBranch = $parsedPayload['branch'] ?? '';
        $providerBranchUrl = $parsedPayload['branchUrl'] ?? '';
        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $providerRepositoryName = $parsedPayload['repositoryName'] ?? '';
        $providerInstallationId = $parsedPayload['installationId'] ?? '';
        $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';
        $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
        $providerCommitAuthorName = $parsedPayload['headCommitAuthorName'] ?? '';
        $providerCommitAuthorEmail = $parsedPayload['headCommitAuthorEmail'] ?? '';
        $providerCommitAuthorUrl = $parsedPayload['authorUrl'] ?? '';
        $providerCommitMessage = $parsedPayload['headCommitMessage'] ?? '';
        $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';

        Span::add('vcs.origin.event.repo.id', $providerRepositoryId);
        Span::add('vcs.origin.event.repo.name', $providerRepositoryName);
        Span::add('vcs.origin.event.branch', $providerBranch);
        Span::add('vcs.origin.event.installation.id', $providerInstallationId);

        // Nothing deploys from a deleted branch, or from a commit we pushed
        if ($providerBranchDeleted || \in_array($providerCommitAuthorEmail, [APP_VCS_GITHUB_EMAIL, APP_VCS_ORIGIN_EMAIL], true)) {
            return;
        }

        // Find associated repositories
        $repositories = $this->findRepositories($providerRepositoryId, [], $dbForPlatform, $authorization);

        // A delivery for a repository nothing deploys from needs no token
        if (empty($repositories)) {
            return;
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'origin',
            'providerInstallationId' => $providerInstallationId,
        ]));

        // Origin push deliveries carry no file lists, so path triggers do not apply
        $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];

        // One delivery can update several branches, so each claim names its branch
        $this->deployOnce($repositories, $deliveryId, 'push:' . $providerBranch, $providerCommitHash, $providerBranch, $dbForPlatform, $authorization, $getProjectDB, fn (Document $repository) => $this->createGitDeployments($vcs, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory));
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        string $deliveryId,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $action = $parsedPayload['action'] ?? '';

        if ($action == 'opened' || $action == 'reopened' || $action == 'synchronize') {
            $providerBranch = $parsedPayload['branch'] ?? '';
            $providerBranchUrl = $parsedPayload['branchUrl'] ?? '';
            $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
            $providerRepositoryName = $parsedPayload['repositoryName'] ?? '';
            $providerInstallationId = $parsedPayload['installationId'] ?? '';
            $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
            $providerPullRequestId = $parsedPayload['pullRequestNumber'] ?? '';
            $providerCommitHash = $parsedPayload['commitHash'] ?? '';
            $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
            $external = $parsedPayload['external'] ?? false;
            $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';
            $providerCommitAuthorUrl = $parsedPayload['authorUrl'] ?? '';

            Span::add('vcs.origin.event.repo.id', $providerRepositoryId);
            Span::add('vcs.origin.event.repo.name', $providerRepositoryName);
            Span::add('vcs.origin.event.branch', $providerBranch);
            Span::add('vcs.origin.event.installation.id', $providerInstallationId);

            // Ignore sync for non-external. We handle it in push webhook.
            // Origin has no fork model, so every pull request is non-external.
            if (!$external && $action == 'synchronize') {
                return;
            }

            $repositories = $this->findRepositories($providerRepositoryId, [Query::orderDesc('$createdAt')], $dbForPlatform, $authorization);

            // A delivery for a repository nothing deploys from needs no token
            if (empty($repositories)) {
                return;
            }

            $vcs = $vcsFactory->fromInstallation(new Document([
                'provider' => 'origin',
                'providerInstallationId' => $providerInstallationId,
            ]));

            try {
                $commitDetails = $vcs->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            } catch (\Throwable $e) {
                Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
                $commitDetails = [];
            }
            $providerCommitAuthor = $commitDetails['commitAuthor'] ?? '';
            $providerCommitMessage = $commitDetails['commitMessage'] ?? '';

            $prFiles = $vcs->getPullRequestFiles($providerRepositoryOwner, $providerRepositoryName, (int) $providerPullRequestId);
            $providerAffectedFiles = [
                ...array_column($prFiles, 'filename'),
                // Only renamed files carry a previous filename; skip missing values from other file changes.
                ...array_filter(array_column($prFiles, 'previousFilename'))
            ];

            $this->deployOnce($repositories, $deliveryId, 'pull:' . $providerPullRequestId, $providerCommitHash, $providerBranch, $dbForPlatform, $authorization, $getProjectDB, fn (Document $repository) => $this->createGitDeployments($vcs, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, \strval($providerPullRequestId), $providerAffectedFiles, $external, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory));
        }

        // No cleanup on close: Origin pull requests are never external, so no
        // authorized-contributor entries accumulate on the repository.
    }

    /**
     * Deploys each repository of this region once per delivery. Origin retries
     * a delivery it got an error for. Each repository claims the delivery once
     * and gives the claim back only when it failed without deploying, so a
     * retry deploys just the repositories that did not, and one that keeps
     * failing never redeploys the others.
     *
     * @param array<Document> $repositories
     * @param string $subject What in the delivery is deployed, such as a branch, so one delivery updating several claims each once
     * @param callable(Document): void $deploy
     */
    private function deployOnce(array $repositories, string $deliveryId, string $subject, string $commitHash, string $branch, Database $dbForPlatform, Authorization $authorization, callable $getProjectDB, callable $deploy): void
    {
        $errors = [];
        foreach ($repositories as $repository) {
            // Every region gets the delivery and they share the platform
            // database, so a region claims only the repositories it deploys -
            // a claim on another region's would make that region skip it
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
            if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                continue;
            }

            // Document ids are capped at 36 characters; a digest prefix keeps the claim within it
            $claim = 'origin-' . \substr(\hash('sha256', $deliveryId . ':' . $subject . ':' . $repository->getId()), 0, 29);
            try {
                $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document(['$id' => $claim])));
            } catch (Duplicate) {
                continue;
            }

            $attemptedAt = DateTime::now();
            try {
                $deploy($repository);
            } catch (\Throwable $error) {
                if (!$this->hasDeployed($repository, $commitHash, $branch, $attemptedAt, $dbForPlatform, $authorization, $getProjectDB)) {
                    $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $claim));
                }
                $errors[] = $error->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }

    /**
     * Whether the repository's resource got a deployment of this commit since
     * the attempt began that did not fail - a push can fail after its
     * deployment is accepted, and retrying it then would build the commit
     * twice. One that failed, such as a build that could not be queued, is
     * retried. A lookup that fails counts as no deployment, so the push is
     * retried rather than dropped.
     */
    private function hasDeployed(Document $repository, string $commitHash, string $branch, string $since, Database $dbForPlatform, Authorization $authorization, callable $getProjectDB): bool
    {
        try {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
            if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                return false;
            }

            $dbForProject = $getProjectDB($project);

            return !$authorization->skip(fn () => $dbForProject->findOne('deployments', [
                Query::equal('resourceId', [$repository->getAttribute('resourceId', '')]),
                Query::equal('resourceType', [$repository->getAttribute('resourceType') === 'function' ? 'functions' : 'sites']),
                Query::equal('providerCommitHash', [$commitHash]),
                Query::equal('providerBranch', [$branch]),
                Query::greaterThanEqual('$createdAt', $since),
                Query::notEqual('status', 'failed'),
            ]))->isEmpty();
        } catch (\Throwable $error) {
            Console::warning("Failed to look up deployments of repository '{$repository->getId()}': " . $error->getMessage());

            return false;
        }
    }
}
