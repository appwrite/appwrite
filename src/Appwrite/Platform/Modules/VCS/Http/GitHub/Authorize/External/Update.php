<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Authorize\External;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

class Update extends Action
{
    use HTTP;
    use Deployment;

    private const BUILD_TYPE_DEPLOYMENT = 'deployment';

    public static function getName()
    {
        return 'updateExternalDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/vcs/github/installations/:installationId/repositories/:repositoryId')
            ->desc('Update external deployment (authorize)')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.write')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'repositories',
                name: 'updateExternalDeployments',
                description: '/docs/references/vcs/update-external-deployments.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ]
            ))
            ->param('installationId', '', new Text(256), 'Installation Id')
            ->param('repositoryId', '', new Text(256), 'VCS Repository Id')
            ->param('providerPullRequestId', '', new Text(256), 'GitHub Pull Request Id')
            ->inject('gitHub')
            ->inject('response')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('getProjectDB')
            ->inject('queueForBuilds')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $installationId,
        string $repositoryId,
        string $providerPullRequestId,
        GitHub $github,
        Response $response,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        Build $queueForBuilds,
        array $platform
    ) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $repository = $authorization->skip(fn () => $dbForPlatform->findOne('repositories', [
            Query::equal('$id', [$repositoryId]),
            Query::equal('projectInternalId', [$project->getSequence()])
        ]));

        if ($repository->isEmpty()) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        // Initialize project database
        $dbForProject = $getProjectDB();

        // Add lock mechanism to prevent race conditions during authorization
        $lockId = "vcs-auth-{$repositoryId}-{$providerPullRequestId}";
        $lockAcquired = false;
        $retries = 0;
        
        while ($retries < 10) {
            try {
                $dbForProject->createDocument('vcsCommentLocks', new Document([
                    '$id' => $lockId
                ]));
                $lockAcquired = true;
                break;
            } catch (\Throwable $err) {
                if ($retries >= 9) {
                    Console::warning("Error creating authorization lock for PR #{$providerPullRequestId}: " . $err->getMessage());
                }
                \sleep(1);
                $retries++;
            }
        }

        if (!$lockAcquired) {
            throw new Exception(Exception::GENERAL_UNKNOWN, 'Could not acquire authorization lock, please try again');
        }

        try {

        if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
            throw new Exception(Exception::PROVIDER_CONTRIBUTION_CONFLICT);
        }

        $providerPullRequestIds = \array_unique(\array_merge($repository->getAttribute('providerPullRequestIds', []), [$providerPullRequestId]));

        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document(['providerPullRequestIds' => $providerPullRequestIds])));

        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $repositories = [$repository];
        $providerRepositoryId = $repository->getAttribute('providerRepositoryId');

        try {
            $providerRepositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($providerRepositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $owner = $github->getOwnerName($providerInstallationId);
        $pullRequestResponse = $github->getPullRequest($owner, $providerRepositoryName, $providerPullRequestId);

        $providerRepositoryUrl = $pullRequestResponse['head']['repo']['html_url'] ?? '';
        $providerRepositoryOwner = $pullRequestResponse['head']['repo']['owner']['login'] ?? '';
        $providerBranch = \explode(':', $pullRequestResponse['head']['label'])[1] ?? '';
        $providerBranchUrl = "$providerRepositoryUrl/tree/$providerBranch";
        $providerCommitHash = $pullRequestResponse['head']['sha'] ?? '';

        $commitDetails = $github->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
        $providerCommitMessage = $commitDetails["commitMessage"] ?? '';
        $providerCommitUrl = $commitDetails["commitUrl"] ?? '';
        $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
        $providerCommitAuthorUrl = $commitDetails["commitAuthorUrl"] ?? '';

        // Check if there's already a waiting deployment for this PR to avoid duplicates
        $existingDeployments = $authorization->skip(fn () => $dbForProject->find('deployments', [
            Query::equal('providerPullRequestId', [$providerPullRequestId]),
            Query::equal('status', ['waiting']),
            Query::limit(1)
        ]));

        if (!$existingDeployments->isEmpty()) {
            // Re-trigger the existing deployment instead of creating a new one
            $existingDeployment = $existingDeployments[0];
            $resourceId = $existingDeployment->getAttribute('resourceId');
            $resourceType = $existingDeployment->getAttribute('resourceType');
            $resourceCollection = $resourceType === "functions" ? 'functions' : 'sites';
            $resource = $authorization->skip(fn () => $dbForProject->getDocument($resourceCollection, $resourceId));
            
            if (!$resource->isEmpty()) {
                Console::info("Re-triggering existing deployment '{$existingDeployment->getId()}' for authorized PR #{$providerPullRequestId}");
                
                $queueName = System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME);
                $queueForBuilds
                    ->setQueue($queueName)
                    ->setType(self::BUILD_TYPE_DEPLOYMENT)
                    ->setResource($resource)
                    ->setDeployment($existingDeployment)
                    ->setProject($project);
                
                $queueForBuilds->trigger();
                $queueForBuilds->reset(); // prevent shutdown hook from triggering again
                $response->noContent();
                return;
            }
        }

        // If no existing waiting deployment, create a new one
        $this->createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, true, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform);

        } finally {
            // Always release the lock
            $authorization->skip(fn () => $dbForProject->deleteDocument('vcsCommentLocks', $lockId));
        }

        $response->noContent();
    }
}
