<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Events;

use Appwrite\Auth\OAuth2\Gitea as OAuth2Gitea;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
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
            ->inject('vcsFactory')
            ->inject('vcsWebhookSecret')
            ->inject('installationTokens')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('getProjectDB')
            ->inject('deploymentsFactory')
            ->inject('platform')
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
        callable $getProjectDB,
        callable $deploymentsFactory,
        array $platform
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

        $parsedPayload = $vcs->getEvent($event, $payload);

        match ($event) {
            'push' => $this->handlePushEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory),
            'pull_request' => $this->handlePullRequestEvent($parsedPayload, $vcsFactory, $installationTokens, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory),
            default => null,
        };

        $response->json($parsedPayload);
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
     * Resolves the adapter for a repository's installation, refreshing its
     * OAuth2 token first. A null installation (not a Gitea installation) is
     * not an error -- the caller silently skips it. A token-refresh/adapter
     * failure, on the other hand, is pushed onto $errors so the caller can
     * surface it instead of dropping it silently -- Gitea's webhook delivery
     * gets a non-2xx response and the failure shows up in its redelivery log.
     */
    private function resolveAdapterForRepository(Document $repository, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Database $dbForPlatform, Authorization $authorization, array &$errors): ?Git
    {
        $installation = $this->resolveGiteaInstallation($repository, $dbForPlatform, $authorization);

        if ($installation === null) {
            return null;
        }

        try {
            $oauth2 = new OAuth2Gitea(
                System::getEnv('_APP_VCS_GITEA_CLIENT_ID', ''),
                System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET', ''),
                ''
            );
            $oauth2->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', ''));

            $installation = $installationTokens->refresh($installation, $dbForPlatform, $oauth2);

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

            $this->createGitDeployments($adapter, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', $providerAffectedFiles, false, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
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

                $this->createGitDeployments($adapter, $providerInstallationId, [$repository], $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $providerAffectedFiles, $external, $dbForPlatform, $authorization, $getProjectDB, $platform, $deploymentsFactory);
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
                    $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                    $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));
                }
            }
        }
    }
}
