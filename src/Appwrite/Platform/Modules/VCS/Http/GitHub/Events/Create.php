<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Events;

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployments;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;


class Create extends Action
{
    use HTTP;
    use Deployments;

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
            ->inject('gitHub')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('getProjectDB')
            ->inject('queueForBuilds')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        GitHub $github,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        Build $queueForBuilds,
        array $platform
    ) {
        $event = $request->getHeader('x-github-event', '');
        Span::add('vcs.github.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeader('x-hub-signature-256', '');
        $secretKey = System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '');

        $valid = empty($signature) ? true : $github->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add('vcs.github.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook payload signature. Please make sure the webhook secret has same value in your GitHub app and in the _APP_VCS_GITHUB_WEBHOOK_SECRET environment variable");
        }

        // TODO(hmacr): Forward event to other regions

        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $parsedPayload = $github->getEvent($event, $payload);

        match ($event) {
            $github::EVENT_INSTALLATION => $this->handleInstallationEvent($parsedPayload, $dbForPlatform, $authorization),
            $github::EVENT_PUSH => $this->handlePushEvent($parsedPayload, $githubAppId, $privateKey, $github, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform),
            $github::EVENT_PULL_REQUEST => $this->handlePullRequestEvent($parsedPayload, $privateKey, $githubAppId, $github, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform),
            default => null,
        };

        return $response->json($parsedPayload);
    }

    private function handleInstallationEvent(
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
    )
    {
        if ($parsedPayload["action"] !== "deleted") {
            return;
        }

        // TODO: Use worker for this job instead (update function/site as well)
        $providerInstallationId = $parsedPayload["installationId"];

        $installations = $dbForPlatform->find('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::limit(1000)
        ]);

        foreach ($installations as $installation) {
            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('installationInternalId', [$installation->getSequence()]),
                Query::limit(1000)
            ]));

            foreach ($repositories as $repository) {
                $authorization->skip(fn () => $dbForPlatform->deleteDocument('repositories', $repository->getId()));
            }

            $authorization->skip(fn () => $dbForPlatform->deleteDocument('installations', $installation->getId()));
        }
    }

    private function handlePushEvent(
        array $parsedPayload,
        string $githubAppId,
        string $privateKey,
        GitHub $github,
        Database $dbForPlatform,
        Authorization $authorization,
        Build $queueForBuilds,
        callable $getProjectDB,
        array $platform,
    )
    {
        $providerBranchCreated = $parsedPayload["branchCreated"] ?? false;
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

        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        // Find associated repositories
        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        // Create new deployment only on push (not committed by us) and not when branch is created or deleted
        if ($providerCommitAuthorEmail !== APP_VCS_GITHUB_EMAIL && !$providerBranchCreated && !$providerBranchDeleted) {
            $this->createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', false, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform);
        }
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        string $privateKey,
        string $githubAppId,
        GitHub $github,
        Database $dbForPlatform,
        Authorization $authorization,
        Build $queueForBuilds,
        callable $getProjectDB,
        array $platform,
    )
    {
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

            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

            $commitDetails = $github->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
            $providerCommitMessage = $commitDetails["commitMessage"] ?? '';

            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::orderDesc('$createdAt')
            ]));

            $this->createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $external, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform);
        } elseif ($action == "closed") {
            // Allowed external contributions cleanup

            $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
            $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
            $external = $parsedPayload["external"] ?? true;

            if ($external) {
                $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::orderDesc('$createdAt')
                ]));

                foreach ($repositories as $repository) {
                    $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                    if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                        $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                        $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository));
                    }
                }
            }
        }
    }
}