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
use Utopia\Console;
use Utopia\Database\Database;
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

        // Origin delivers at least once and retries anything not acknowledged
        // with a 2xx in time. Processing here is synchronous and can outlast
        // that window, so claim the delivery id before doing the work - a
        // retry of a delivery already being processed must not deploy again.
        // TODO: acknowledge first and process asynchronously instead.
        try {
            $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                '$id' => 'origin-delivery-' . $deliveryId,
            ])));
        } catch (Duplicate) {
            $response->json(['events' => [], 'duplicate' => true]);
            return;
        }

        $parsedPayloads = $vcs->getEvents($event, $payload);

        foreach ($parsedPayloads as $parsedPayload) {
            match (true) {
                $event === Origin::EVENT_PUSH => $this->handlePushEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                \str_starts_with($event, Origin::EVENT_PULL_REQUEST . '.') => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                \str_starts_with($event, Origin::EVENT_INSTALLATION . '.') => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization, $getProjectDB),
                default => null,
            };
        }

        $response->json(['events' => $parsedPayloads]);
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

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'origin',
            'providerInstallationId' => $providerInstallationId,
        ]));

        // Find associated repositories
        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        // Create new deployment only on push (not committed by us) and not when branch is deleted
        if (!\in_array($providerCommitAuthorEmail, [APP_VCS_GITHUB_EMAIL, APP_VCS_ORIGIN_EMAIL], true) && !$providerBranchDeleted) {
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

            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::orderDesc('$createdAt')
            ]));

            $this->createGitDeployments($vcs, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, \strval($providerPullRequestId), $providerAffectedFiles, $external, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }

        // No cleanup on close: Origin pull requests are never external, so no
        // authorized-contributor entries accumulate on the repository.
    }
}
