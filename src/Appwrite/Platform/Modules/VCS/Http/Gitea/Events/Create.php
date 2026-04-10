<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Events;

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\VcsFactory;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\VCS\Adapter\Git\Gitea;

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
            ->desc('Create Gitea event')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->inject('cache')
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
        Cache $cache,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        Build $queueForBuilds,
        array $platform
    ) {
        $event = $request->getHeader('x-gitea-event', '');
        Span::add('vcs.gitea.event.name', $event);

        $payload = $request->getRawPayload();
        $signature = $request->getHeader('x-gitea-signature', '');
        $secretKey = VcsFactory::getWebhookSecret('gitea');

        $gitea = VcsFactory::getAdapter('gitea', $cache);

        $valid = empty($secretKey) ? true : $gitea->validateWebhookEvent($payload, $signature, $secretKey);
        Span::add('vcs.gitea.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature.');
        }

        $parsedPayload = $gitea->getEvent($event, $payload);

        match ($event) {
            'push' => $this->handlePushEvent($parsedPayload, $gitea, $cache, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform),
            'pull_request' => $this->handlePullRequestEvent($parsedPayload, $gitea, $cache, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform),
            default => null,
        };

        $response->json($parsedPayload);
    }

    private function handlePushEvent(
        array $parsedPayload,
        Gitea $gitea,
        Cache $cache,
        Database $dbForPlatform,
        Authorization $authorization,
        Build $queueForBuilds,
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

        // Find the installation by looking up the repository's organization and provider
        $installation = $this->findInstallation($providerRepositoryOwner, $dbForPlatform, $authorization);
        if ($installation === null) {
            return;
        }

        $vcs = VcsFactory::getInitializedAdapter('gitea', $installation, $cache);

        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
            Query::equal('providerRepositoryId', [$providerRepositoryId]),
            Query::limit(100),
        ]));

        if ($providerCommitAuthorEmail !== APP_VCS_COMMIT_EMAIL && !$providerBranchDeleted) {
            $this->createGitDeployments($vcs, '', $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', false, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform);
        }
    }

    private function handlePullRequestEvent(
        array $parsedPayload,
        Gitea $gitea,
        Cache $cache,
        Database $dbForPlatform,
        Authorization $authorization,
        Build $queueForBuilds,
        callable $getProjectDB,
        array $platform,
    ) {
        $action = $parsedPayload['action'] ?? '';

        if ($action === 'opened' || $action === 'reopened' || $action === 'synchronize' || $action === 'synchronized') {
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

            // Ignore sync for non-external
            if (!$external && ($action === 'synchronize' || $action === 'synchronized')) {
                return;
            }

            $installation = $this->findInstallation($providerRepositoryOwner, $dbForPlatform, $authorization);
            if ($installation === null) {
                return;
            }

            $vcs = VcsFactory::getInitializedAdapter('gitea', $installation, $cache);

            $commitDetails = $vcs->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
            $providerCommitAuthor = $commitDetails['commitAuthor'] ?? '';
            $providerCommitMessage = $commitDetails['commitMessage'] ?? '';

            $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::orderDesc('$createdAt')
            ]));

            $this->createGitDeployments($vcs, '', $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $external, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform);
        } elseif ($action === 'closed') {
            $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
            $providerPullRequestId = $parsedPayload['pullRequestNumber'] ?? '';
            $external = $parsedPayload['external'] ?? true;

            if ($external) {
                $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::orderDesc('$createdAt')
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

    /**
     * Find an installation by organization name for Gitea provider.
     */
    private function findInstallation(string $organization, Database $dbForPlatform, Authorization $authorization): ?Document
    {
        $installation = $authorization->skip(fn () => $dbForPlatform->findOne('installations', [
            Query::equal('organization', [$organization]),
            Query::equal('provider', ['gitea']),
        ]));

        if ($installation->isEmpty()) {
            return null;
        }

        return $installation;
    }
}
