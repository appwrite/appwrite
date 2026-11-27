<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Events;

use Appwrite\Event\Publisher\Review as ReviewPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Reviews;
use Appwrite\Reviews\Provider;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Appwrite\Vcs\RepositoryPath;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Gitea;

class Create extends Action
{
    use HTTP;
    use Deployment;
    use Reviews;

    public static function getName()
    {
        return 'createVCSGiteaEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vcs/gitea/events')
            ->desc('Create event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('vcsFactory')
            ->inject('vcsWebhookSecret')
            ->inject('installationTokens')
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
        VcsFactory $vcsFactory,
        callable $vcsWebhookSecret,
        InstallationTokens $installationTokens,
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
        $vcs = $vcsFactory->fromProvider('gitea');

        $event = $request->getHeaderLine($vcs->getEventHeaderName(), '');
        Span::add('vcs.gitea.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $secretKey = $vcsWebhookSecret('gitea');

        $valid = !empty($secretKey) && $vcs->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add('vcs.gitea.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. Please make sure the webhook secret has same value in your Gitea repository settings and in the _APP_VCS_GITEA_WEBHOOK_SECRET environment variable');
        }

        $parsedPayloads = $vcs->getEvents($event, $payload);

        foreach ($parsedPayloads as $parsedPayload) {
            // Before the deployments, which may throw; queueing a review never does.
            // Without the secret no review service runs (optional on self-hosted), so nothing would consume the job.
            if (System::getEnv('_APP_REVIEWS_SECRET', '') !== '') {
                match ($event) {
                    'pull_request' => $this->queueReviews('gitea', $parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews, $platform),
                    'issue_comment' => $this->queueComment('gitea', $this->describeComment($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization), $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $publisherForReviews, $platform),
                    default => null,
                };
            }

            match ($event) {
                'push' => $this->handlePushEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                'pull_request' => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory),
                default => null,
            };
        }

        $response->json(['events' => $parsedPayloads]);
    }

    private function resolveGiteaInstallation(Document $repository, Database $dbForPlatform, Authorization $authorization): ?Document
    {
        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $repository->getAttribute('installationId', '')));

        if ($installation->isEmpty() || $installation->getAttribute('provider', 'github') !== 'gitea') {
            return null;
        }

        return $installation;
    }

    /**
     * A refresh/adapter failure is pushed onto $errors instead of swallowed, so the
     * caller can surface a non-2xx response and Gitea logs it as a failed delivery.
     */
    private function resolveAdapterForRepository(Document $repository, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Database $dbForPlatform, Authorization $authorization, array &$errors): ?Git
    {
        $installation = $this->resolveGiteaInstallation($repository, $dbForPlatform, $authorization);

        if ($installation === null) {
            return null;
        }

        try {
            $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);

            return $vcsFactory->fromInstallation($installation);
        } catch (\Throwable $error) {
            $message = "Failed to resolve Gitea adapter for installation '{$installation->getId()}': " . $error->getMessage();
            Console::warning($message);
            Span::add("vcs.gitea.event.installation.{$installation->getId()}.error", $message);
            $errors[] = $message;
            return null;
        }
    }

    private function handlePushEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
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
        $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';
        $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
        $providerCommitAuthorName = $parsedPayload['headCommitAuthorName'] ?? '';
        $providerCommitAuthorEmail = $parsedPayload['headCommitAuthorEmail'] ?? '';
        $providerCommitAuthorUrl = $parsedPayload['authorUrl'] ?? '';
        $providerCommitMessage = $parsedPayload['headCommitMessage'] ?? '';
        $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';

        Span::add('vcs.gitea.event.repo.id', $providerRepositoryId);
        Span::add('vcs.gitea.event.repo.name', $providerRepositoryName);
        Span::add('vcs.gitea.event.branch', $providerBranch);

        if ($providerCommitAuthorEmail === APP_VCS_GITEA_EMAIL || $providerBranchDeleted) {
            return;
        }

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];

        $errors = [];
        foreach ($repositories as $repository) {
            $adapter = $this->resolveAdapterForRepository($repository, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $errors);

            if ($adapter === null) {
                continue;
            }

            $providerInstallationId = $repository->getAttribute('installationId', '');

            $this->createGitDeployments($adapter, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        Bus $bus,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $action = $parsedPayload['action'] ?? '';

        if ($action === 'opened' || $action === 'reopened' || $action === 'synchronize') {
            $providerBranch = $parsedPayload['branch'] ?? '';
            $providerBranchUrl = $parsedPayload['branchUrl'] ?? '';
            $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
            $providerRepositoryName = $parsedPayload['repositoryName'] ?? '';
            $providerRepositoryUrl = $parsedPayload['repositoryUrl'] ?? '';
            $providerPullRequestId = $parsedPayload['pullRequestNumber'] ?? '';
            $providerCommitHash = $parsedPayload['commitHash'] ?? '';
            $providerRepositoryOwner = $parsedPayload['owner'] ?? '';
            $external = $parsedPayload['external'] ?? true;
            $providerCommitUrl = $parsedPayload['headCommitUrl'] ?? '';
            $providerCommitAuthorUrl = $parsedPayload['authorUrl'] ?? '';

            Span::add('vcs.gitea.event.repo.id', $providerRepositoryId);
            Span::add('vcs.gitea.event.repo.name', $providerRepositoryName);
            Span::add('vcs.gitea.event.branch', $providerBranch);

            // Ignore sync for non-external. We handle it in the push webhook.
            if (!$external && $action === 'synchronize') {
                return;
            }

            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::orderDesc('$createdAt'),
                Query::limit(100),
            ]));

            $errors = [];
            foreach ($repositories as $repository) {
                $adapter = $this->resolveAdapterForRepository($repository, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $errors);

                if ($adapter === null) {
                    continue;
                }

                $providerInstallationId = $repository->getAttribute('installationId', '');

                try {
                    $commitDetails = $adapter->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
                } catch (\Throwable $e) {
                    Console::warning("Failed to fetch commit '{$providerCommitHash}': " . $e->getMessage());
                    $commitDetails = [];
                }
                $providerCommitAuthor = $commitDetails['commitAuthor'] ?? '';
                $providerCommitMessage = $commitDetails['commitMessage'] ?? '';

                $prFiles = $adapter->getPullRequestFiles($providerRepositoryOwner, $providerRepositoryName, $providerPullRequestId);
                $providerAffectedFiles = [
                    ...array_column($prFiles, 'filename'),
                    ...array_filter(array_column($prFiles, 'previous_filename')),
                ];

                $this->createGitDeployments($adapter, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $bus, $getProjectDB, $platform, $deploymentsFactory);
            }

            if (!empty($errors)) {
                throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
            }
        } elseif ($action === 'closed') {
            $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
            $providerPullRequestId = $parsedPayload['pullRequestNumber'] ?? '';
            $external = $parsedPayload['external'] ?? true;

            if (!$external) {
                return;
            }

            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::orderDesc('$createdAt'),
                Query::limit(100),
            ]));

            foreach ($repositories as $repository) {
                if ($this->resolveGiteaInstallation($repository, $dbForPlatform, $authorization) === null) {
                    continue;
                }

                $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                    $providerPullRequestIds = \array_values(\array_diff($providerPullRequestIds, [$providerPullRequestId]));
                    $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));
                }
            }
        }
    }

    /**
     * Mentions name the account that connected the installation, which the reviewer posts as.
     */
    protected function getReviewerHandle(Document $installation): string
    {
        return $installation->getAttribute('organization', '');
    }

    /**
     * The reviewer posts as the account that connected the installation. Its own comments are
     * told apart by their markers and by its id, see getCommentAuthorAssociation().
     */
    protected function getReviewerLogin(Document $installation): string
    {
        return $installation->getAttribute('organization', '');
    }

    /**
     * Gitea sends no association with a comment, so the author's permission on the repository is read: its owner and admins count as owners, writers as collaborators. The account the reviewer posts as is never trusted, so it does
     * not answer itself; a failed read leaves the author untrusted.
     */
    protected function getCommentAuthorAssociation(array $parsedPayload, Document $installation, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Database $dbForPlatform): string
    {
        $authorId = \strval($parsedPayload['commentAuthorId'] ?? '');
        if (($parsedPayload['commentAuthorType'] ?? '') !== 'User' || $authorId === '' || $authorId === $installation->getAttribute('providerInstallationId', '')) {
            return '';
        }

        try {
            $installation = $dbForPlatform->getAuthorization()->skip(fn () => $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory));
            $vcs = $vcsFactory->fromInstallation($installation);
            if (!$vcs instanceof Gitea) {
                return '';
            }

            $permission = $vcs->getCollaboratorPermission(\strval($parsedPayload['owner'] ?? ''), \strval($parsedPayload['repositoryName'] ?? ''), \strval($parsedPayload['commentAuthor'] ?? ''));
        } catch (\Throwable $error) {
            Console::warning("Failed to read the permission of comment author '{$authorId}': " . $error->getMessage());

            return '';
        }

        return $this->getAssociationFromPermission($permission);
    }

    /**
     * A comment with its thread, when it is a review comment: Gitea sends review thread replies
     * like conversation comments, so the pull request's review comments are read to tell. Only
     * comments on repositories some project reviews are looked up, through the first
     * installation that reaches the repository.
     */
    private function describeComment(array $parsedPayload, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Database $dbForPlatform, Authorization $authorization): array
    {
        $commentId = \strval($parsedPayload['commentId'] ?? '');
        $pullNumber = (int) ($parsedPayload['pullRequestNumber'] ?? 0);
        // The reviewer's own comments, which queue nothing, are not worth the reads.
        if ($commentId === '' || $pullNumber <= 0 || \str_contains(\strval($parsedPayload['commentBody'] ?? ''), '<!-- ' . self::REVIEWER_MARKER_NAMESPACE . ':')) {
            return $parsedPayload;
        }

        $repositories = $this->findReviewRepositories('gitea', \strval($parsedPayload['repositoryId'] ?? ''), '', $dbForPlatform, $authorization);
        if (empty($repositories)) {
            return $parsedPayload;
        }

        try {
            $installation = $authorization->skip(fn () => $installationTokens->refreshForInstallation($repositories[0]['installation'], $dbForPlatform, $vcsFactory));
            $vcs = $vcsFactory->fromInstallation($installation);
            if (!$vcs instanceof Gitea) {
                return $parsedPayload;
            }

            // The delivery's names are not trusted with this credential: the webhook secret is
            // instance-wide, so the repository is the one connected under the id it matched.
            [$owner, $repositoryName] = RepositoryPath::read($vcs, Provider::Gitea, \strval($installation->getAttribute('providerInstallationId', '')), \strval($repositories[0]['repository']->getAttribute('providerRepositoryId', '')));
            $rootId = $vcs->getReviewCommentThread($owner, $repositoryName, $pullNumber, $commentId);
        } catch (\Throwable $error) {
            Console::warning("Failed to look up the thread of comment '{$commentId}': " . $error->getMessage());

            return $parsedPayload;
        }

        if ($rootId === '') {
            return $parsedPayload;
        }

        return [
            ...$parsedPayload,
            'commentKind' => 'review',
            'commentThreadId' => $rootId,
            'commentReplyToId' => $rootId === $commentId ? '' : $rootId,
        ];
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
