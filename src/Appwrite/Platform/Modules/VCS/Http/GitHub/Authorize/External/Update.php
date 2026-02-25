<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Authorize\External;

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployments;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
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
    use Deployments;

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
    )
    {
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

        if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
            throw new Exception(Exception::PROVIDER_CONTRIBUTION_CONFLICT);
        }

        $providerPullRequestIds = \array_unique(\array_merge($repository->getAttribute('providerPullRequestIds', []), [$providerPullRequestId]));
        $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);

        // TODO: Delete from array when PR is closed

        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository));

        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $repositories = [$repository];
        $providerRepositoryId = $repository->getAttribute('providerRepositoryId');

        $owner = $github->getOwnerName($providerInstallationId);
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }
        $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $providerPullRequestId);

        $providerBranch = \explode(':', $pullRequestResponse['head']['label'])[1] ?? '';
        $providerCommitHash = $pullRequestResponse['head']['sha'] ?? '';
        $providerBranchUrl = $pullRequestResponse['head']['repo']['html_url'] ?? '';
        $providerRepositoryName = $pullRequestResponse['head']['repo']['name'] ?? '';
        $providerRepositoryUrl = $pullRequestResponse['head']['repo']['html_url'] ?? '';
        $providerRepositoryOwner = $pullRequestResponse['head']['repo']['owner']['login'] ?? '';
        $providerCommitAuthor = $pullRequestResponse['head']['user']['login'] ?? '';
        $providerCommitAuthorUrl = $pullRequestResponse['head']['user']['html_url'] ?? '';
        $providerCommitMessage = $pullRequestResponse['title'] ?? '';
        $providerCommitUrl = $pullRequestResponse['html_url'] ?? '';

        $this->createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, '', '', '', '', $providerCommitHash, '', '', '', '', $providerPullRequestId, true, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $platform);

        $response->noContent();
    }
}