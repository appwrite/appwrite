<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Events;

use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

class Create extends Action
{
    use HTTP;
    use Deployment;

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
            ->inject('vcsForProvider')
            ->inject('vcsForInstallation')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('getProjectDB')
            ->inject('publisherForBuilds')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        callable $vcsForProvider,
        callable $vcsForInstallation,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        BuildPublisher $publisherForBuilds,
        array $platform
    ) {
        $vcs = $vcsForProvider('gitea');

        $event = $request->getHeaderLine($vcs->getEventHeaderName(), '');
        Span::add('vcs.gitea.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $secretKey = System::getEnv('_APP_VCS_GITEA_WEBHOOK_SECRET', '');

        // Fail closed, unlike GitHub App deliveries: those also carry a
        // signed JWT Appwrite always validates, so an empty webhook secret
        // there still leaves a real check in place. Gitea has no such
        // fallback -- an empty secret here would mean no verification at all.
        $valid = !empty($secretKey) && $vcs->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add('vcs.gitea.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. Please make sure the webhook secret has same value in your Gitea repository settings and in the _APP_VCS_GITEA_WEBHOOK_SECRET environment variable');
        }

        $parsedPayload = $vcs->getEvent($event, $payload);

        match ($event) {
            'push' => $this->handlePushEvent($parsedPayload, $vcsForInstallation, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform),
            'pull_request' => $this->handlePullRequestEvent($parsedPayload, $vcsForInstallation, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform),
            default => null,
        };

        $response->json($parsedPayload);
    }

    /**
     * Resolves an authenticated adapter for a repository connection's own
     * installation. Unlike GitHub App, where one adapter serves every
     * repository under an installation, Gitea's OAuth2 installations each
     * carry their own personal token — repositories matching the same
     * providerRepositoryId can belong to different Appwrite installations.
     */
    private function resolveAdapterForRepository(Document $repository, callable $vcsForInstallation, Database $dbForPlatform, Authorization $authorization): ?Git
    {
        $installation = $authorization->skip(fn () => $dbForPlatform->getDocument('installations', $repository->getAttribute('installationId', '')));

        if ($installation->isEmpty()) {
            return null;
        }

        // `repositories` has no provider attribute -- providerRepositoryId is
        // provider-scoped numeric IDs from independent servers, so a GitHub
        // and a Gitea repository can collide on the same ID. Filter by the
        // matched installation's own provider instead of the query.
        if ($installation->getAttribute('provider', 'github') !== 'gitea') {
            return null;
        }

        try {
            return $vcsForInstallation($installation);
        } catch (\Throwable $error) {
            Console::warning("Failed to resolve Gitea adapter for installation '{$installation->getId()}': " . $error->getMessage());
            return null;
        }
    }

    private function handlePushEvent(
        array $parsedPayload,
        callable $vcsForInstallation,
        Database $dbForPlatform,
        Authorization $authorization,
        BuildPublisher $publisherForBuilds,
        callable $getProjectDB,
        array $platform,
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

        if ($providerCommitAuthorEmail === APP_VCS_GITHUB_EMAIL || $providerBranchDeleted) {
            return;
        }

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        $providerAffectedFiles = $parsedPayload['affectedFiles'] ?? [];

        foreach ($repositories as $repository) {
            $adapter = $this->resolveAdapterForRepository($repository, $vcsForInstallation, $dbForPlatform, $authorization);

            if ($adapter === null) {
                continue;
            }

            $providerInstallationId = $repository->getAttribute('installationId', '');

            $this->createGitDeployments($adapter, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform);
        }
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        callable $vcsForInstallation,
        Database $dbForPlatform,
        Authorization $authorization,
        BuildPublisher $publisherForBuilds,
        callable $getProjectDB,
        array $platform,
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

            foreach ($repositories as $repository) {
                $adapter = $this->resolveAdapterForRepository($repository, $vcsForInstallation, $dbForPlatform, $authorization);

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

                $this->createGitDeployments($adapter, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $publisherForBuilds, $getProjectDB, $platform);
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
                $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                    $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                    $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));
                }
            }
        }
    }
}
