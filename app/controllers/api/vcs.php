<?php

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Installations;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Comment;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Detector\Detection\Framework\Astro;
use Utopia\Detector\Detection\Framework\Flutter;
use Utopia\Detector\Detection\Framework\NextJs;
use Utopia\Detector\Detection\Framework\Nuxt;
use Utopia\Detector\Detection\Framework\Remix;
use Utopia\Detector\Detection\Framework\SvelteKit;
use Utopia\Detector\Detection\Packager\NPM;
use Utopia\Detector\Detection\Packager\PNPM;
use Utopia\Detector\Detection\Packager\Yarn;
use Utopia\Detector\Detection\Runtime\Bun;
use Utopia\Detector\Detection\Runtime\CPP;
use Utopia\Detector\Detection\Runtime\Dart;
use Utopia\Detector\Detection\Runtime\Deno;
use Utopia\Detector\Detection\Runtime\Dotnet;
use Utopia\Detector\Detection\Runtime\Java;
use Utopia\Detector\Detection\Runtime\Node;
use Utopia\Detector\Detection\Runtime\PHP;
use Utopia\Detector\Detection\Runtime\Python;
use Utopia\Detector\Detection\Runtime\Ruby;
use Utopia\Detector\Detection\Runtime\Swift;
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detector\Packager;
use Utopia\Detector\Detector\Runtime;
use Utopia\Detector\Detector\Strategy;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Host;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

use function Swoole\Coroutine\batch;

$createGitDeployments = function (GitHub $github, string $providerInstallationId, array $repositories, string $providerBranch, string $providerBranchUrl, string $providerRepositoryName, string $providerRepositoryUrl, string $providerRepositoryOwner, string $providerCommitHash, string $providerCommitAuthor, string $providerCommitAuthorUrl, string $providerCommitMessage, string $providerCommitUrl, string $providerPullRequestId, bool $external, Database $dbForPlatform, Build $queueForBuilds, callable $getProjectDB, Request $request) {
    $errors = [];
    foreach ($repositories as $repository) {
        try {
            $resourceType = $repository->getAttribute('resourceType');

            if ($resourceType !== "function" && $resourceType !== "site") {
                continue;
            }

            $projectId = $repository->getAttribute('projectId');
            $project = Authorization::skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
            $dbForProject = $getProjectDB($project);

            $resourceCollection = $resourceType === "function" ? 'functions' : 'sites';
            $resourceId = $repository->getAttribute('resourceId');
            $resource = Authorization::skip(fn () => $dbForProject->getDocument($resourceCollection, $resourceId));
            $resourceInternalId = $resource->getInternalId();

            $deploymentId = ID::unique();
            $repositoryId = $repository->getId();
            $repositoryInternalId = $repository->getInternalId();
            $providerRepositoryId = $repository->getAttribute('providerRepositoryId');
            $installationId = $repository->getAttribute('installationId');
            $installationInternalId = $repository->getAttribute('installationInternalId');
            $productionBranch = $resource->getAttribute('providerBranch');
            $activate = false;

            if ($providerBranch == $productionBranch && $external === false) {
                $activate = true;
            }

            $owner = $github->getOwnerName($providerInstallationId) ?? '';
            try {
                $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                if (empty($repositoryName)) {
                    throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                }
            } catch (RepositoryNotFound $e) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }

            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }

            $isAuthorized = !$external;

            if (!$isAuthorized && !empty($providerPullRequestId)) {
                if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
                    $isAuthorized = true;
                }
            }

            $commentStatus = $isAuthorized ? 'waiting' : 'failed';

            $authorizeUrl = $request->getProtocol() . '://' . $request->getHostname() . "/console/git/authorize-contributor?projectId={$projectId}&installationId={$installationId}&repositoryId={$repositoryId}&providerPullRequestId={$providerPullRequestId}";

            $action = $isAuthorized ? ['type' => 'logs'] : ['type' => 'authorize', 'url' => $authorizeUrl];

            $latestCommentId = '';

            if (!empty($providerPullRequestId) && $resource->getAttribute('providerSilentMode', false) === false) {
                $latestComment = Authorization::skip(fn () => $dbForPlatform->findOne('vcsComments', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::equal('providerPullRequestId', [$providerPullRequestId]),
                    Query::orderDesc('$createdAt'),
                ]));

                if (!$latestComment->isEmpty()) {
                    $latestCommentId = $latestComment->getAttribute('providerCommentId', '');

                    $comment = new Comment();
                    $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                    $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');

                    $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                } else {
                    $comment = new Comment();
                    $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');
                    $latestCommentId = \strval($github->createComment($owner, $repositoryName, $providerPullRequestId, $comment->generateComment()));

                    if (!empty($latestCommentId)) {
                        $teamId = $project->getAttribute('teamId', '');

                        $latestComment = Authorization::skip(fn () => $dbForPlatform->createDocument('vcsComments', new Document([
                            '$id' => ID::unique(),
                            '$permissions' => [
                                Permission::read(Role::team(ID::custom($teamId))),
                                Permission::update(Role::team(ID::custom($teamId), 'owner')),
                                Permission::update(Role::team(ID::custom($teamId), 'developer')),
                                Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                                Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                            ],
                            'installationInternalId' => $installationInternalId,
                            'installationId' => $installationId,
                            'projectInternalId' => $project->getInternalId(),
                            'projectId' => $project->getId(),
                            'providerRepositoryId' => $providerRepositoryId,
                            'providerBranch' => $providerBranch,
                            'providerPullRequestId' => $providerPullRequestId,
                            'providerCommentId' => $latestCommentId
                        ])));
                    }
                }
            } elseif (!empty($providerBranch)) {
                $latestComments = Authorization::skip(fn () => $dbForPlatform->find('vcsComments', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::equal('providerBranch', [$providerBranch]),
                    Query::orderDesc('$createdAt'),
                ]));

                foreach ($latestComments as $comment) {
                    $latestCommentId = $comment->getAttribute('providerCommentId', '');
                    $comment = new Comment();
                    $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                    $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');

                    $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                }
            }

            if (!$isAuthorized) {
                $resourceName = $resource->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$resourceName} ({$projectName})";
                $message = 'Authorization required for external contributor.';

                $providerRepositoryId = $repository->getAttribute('providerRepositoryId');
                try {
                    $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                    if (empty($repositoryName)) {
                        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                    }
                } catch (RepositoryNotFound $e) {
                    throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                }
                $owner = $github->getOwnerName($providerInstallationId);
                $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'failure', $message, $authorizeUrl, $name);
                continue;
            }

            if ($external) {
                $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $providerPullRequestId);
                $providerRepositoryName = $pullRequestResponse['head']['repo']['owner']['login'];
                $providerRepositoryOwner = $pullRequestResponse['head']['repo']['name'];
            }

            $commands = [];
            if (!empty($resource->getAttribute('installCommand', ''))) {
                $commands[] = $resource->getAttribute('installCommand', '');
            }
            if (!empty($resource->getAttribute('buildCommand', ''))) {
                $commands[] = $resource->getAttribute('buildCommand', '');
            }
            if (!empty($resource->getAttribute('commands', ''))) {
                $commands[] = $resource->getAttribute('commands', '');
            }

            $deployment = Authorization::skip(fn () => $dbForProject->createDocument('deployments', new Document([
                '$id' => $deploymentId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'resourceId' => $resourceId,
                'resourceInternalId' => $resourceInternalId,
                'resourceType' => $resourceCollection,
                'entrypoint' => $resource->getAttribute('entrypoint', ''),
                'buildCommands' => \implode(' && ', $commands),
                'buildOutput' => $resource->getAttribute('outputDirectory', ''),
                'adapter' => $resource->getAttribute('adapter', ''),
                'fallbackFile' => $resource->getAttribute('fallbackFile', ''),
                'type' => 'vcs',
                'installationId' => $installationId,
                'installationInternalId' => $installationInternalId,
                'providerRepositoryId' => $providerRepositoryId,
                'repositoryId' => $repositoryId,
                'repositoryInternalId' => $repositoryInternalId,
                'providerBranchUrl' => $providerBranchUrl,
                'providerRepositoryName' => $providerRepositoryName,
                'providerRepositoryOwner' => $providerRepositoryOwner,
                'providerRepositoryUrl' => $providerRepositoryUrl,
                'providerCommitHash' => $providerCommitHash,
                'providerCommitAuthorUrl' => $providerCommitAuthorUrl,
                'providerCommitAuthor' => $providerCommitAuthor,
                'providerCommitMessage' => mb_strimwidth($providerCommitMessage, 0, 255, '...'),
                'providerCommitUrl' => $providerCommitUrl,
                'providerCommentId' => \strval($latestCommentId),
                'providerBranch' => $providerBranch,
                'activate' => $activate,
            ])));

            $resource = $resource
                ->setAttribute('latestDeploymentId', $deployment->getId())
                ->setAttribute('latestDeploymentInternalId', $deployment->getInternalId())
                ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
                ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
            Authorization::skip(fn () => $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), $resource));

            if ($resource->getCollection() === 'sites') {
                $projectId = $project->getId();

                // Deployment preview
                $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
                $domain = ID::unique() . "." . $sitesDomain;
                $ruleId = md5($domain);
                Authorization::skip(
                    fn () => $dbForPlatform->createDocument('rules', new Document([
                        '$id' => $ruleId,
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getInternalId(),
                        'domain' => $domain,
                        'type' => 'deployment',
                        'trigger' => 'deployment',
                        'deploymentId' => $deployment->getId(),
                        'deploymentInternalId' => $deployment->getInternalId(),
                        'deploymentResourceType' => 'site',
                        'deploymentResourceId' => $resourceId,
                        'deploymentResourceInternalId' => $resourceInternalId,
                        'deploymentVcsProviderBranch' => $providerBranch,
                        'status' => 'verified',
                        'certificateId' => '',
                        'search' => implode(' ', [$ruleId, $domain]),
                        'owner' => 'Appwrite',
                        'region' => $project->getAttribute('region')
                    ]))
                );

                // VCS branch preview
                if (!empty($providerBranch)) {
                    $domain = "branch-{$providerBranch}-{$resource->getId()}-{$project->getId()}.{$sitesDomain}";
                    $ruleId = md5($domain);
                    try {
                        Authorization::skip(
                            fn () => $dbForPlatform->createDocument('rules', new Document([
                                '$id' => $ruleId,
                                'projectId' => $project->getId(),
                                'projectInternalId' => $project->getInternalId(),
                                'domain' => $domain,
                                'type' => 'deployment',
                                'trigger' => 'deployment',
                                'deploymentId' => $deployment->getId(),
                                'deploymentInternalId' => $deployment->getInternalId(),
                                'deploymentResourceType' => 'site',
                                'deploymentResourceId' => $resourceId,
                                'deploymentResourceInternalId' => $resourceInternalId,
                                'deploymentVcsProviderBranch' => $providerBranch,
                                'status' => 'verified',
                                'certificateId' => '',
                                'search' => implode(' ', [$ruleId, $domain]),
                                'owner' => 'Appwrite',
                                'region' => $project->getAttribute('region')
                            ]))
                        );
                    } catch (Duplicate $err) {
                        // Ignore, rule already exists; will be updated by builds worker
                    }
                }

                // VCS commit preview
                if (!empty($providerCommitHash)) {
                    $domain = "commit-{$providerCommitHash}-{$resource->getId()}-{$project->getId()}.{$sitesDomain}";
                    $ruleId = md5($domain);
                    try {
                        Authorization::skip(
                            fn () => $dbForPlatform->createDocument('rules', new Document([
                                '$id' => $ruleId,
                                'projectId' => $project->getId(),
                                'projectInternalId' => $project->getInternalId(),
                                'domain' => $domain,
                                'type' => 'deployment',
                                'trigger' => 'deployment',
                                'deploymentId' => $deployment->getId(),
                                'deploymentInternalId' => $deployment->getInternalId(),
                                'deploymentResourceType' => 'site',
                                'deploymentResourceId' => $resourceId,
                                'deploymentResourceInternalId' => $resourceInternalId,
                                'deploymentVcsProviderBranch' => $providerBranch,
                                'status' => 'verified',
                                'certificateId' => '',
                                'search' => implode(' ', [$ruleId, $domain]),
                                'owner' => 'Appwrite',
                                'region' => $project->getAttribute('region')
                            ]))
                        );
                    } catch (Duplicate $err) {
                        // Ignore, rule already exists; will be updated by builds worker
                    }
                }
            }

            if (!empty($providerCommitHash) && $resource->getAttribute('providerSilentMode', false) === false) {
                $resourceName = $resource->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$resourceName} ({$projectName})";
                $message = 'Starting...';

                $providerRepositoryId = $repository->getAttribute('providerRepositoryId');
                try {
                    $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                    if (empty($repositoryName)) {
                        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                    }
                } catch (RepositoryNotFound $e) {
                    throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                }
                $owner = $github->getOwnerName($providerInstallationId);

                $providerTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/$resourceCollection/$resourceType-$resourceId";
                $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'pending', $message, $providerTargetUrl, $name);
            }

            $queueForBuilds
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($resource)
                ->setDeployment($deployment)
                ->setProject($project); // set the project because it won't be set for git deployments

            $queueForBuilds->trigger(); // must trigger here so that we create a build for each function/site

            //TODO: Add event?
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $queueForBuilds->reset(); // prevent shutdown hook from triggering again

    if (!empty($errors)) {
        throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
    }
};

App::get('/v1/vcs/github/authorize')
    ->desc('Create GitHub app installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'installations',
        name: 'createGitHubInstallation',
        description: '/docs/references/vcs/create-github-installation.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_MOVED_PERMANENTLY,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::HTML,
        type: MethodType::WEBAUTH,
        hide: true,
    ))
    ->param('success', '', fn ($clients) => new Host($clients), 'URL to redirect back to console after a successful installation attempt.', true, ['clients'])
    ->param('failure', '', fn ($clients) => new Host($clients), 'URL to redirect back to console after a failed installation attempt.', true, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function (string $success, string $failure, Request $request, Response $response, Document $project) {
        $state = \json_encode([
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
        ]);

        $appName = System::getEnv('_APP_VCS_GITHUB_APP_NAME');

        if (empty($appName)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'GitHub App name is not configured. Please configure VCS (Version Control System) variables in .env file.');
        }

        $url = "https://github.com/apps/$appName/installations/new?" . \http_build_query([
            'state' => $state,
            'redirect_uri' => $request->getProtocol() . '://' . $request->getHostname() . "/v1/vcs/github/callback"
        ]);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    });

App::get('/v1/vcs/github/callback')
    ->desc('Get installation and authorization from GitHub app')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->param('installation_id', '', new Text(256, 0), 'GitHub installation ID', true)
    ->param('setup_action', '', new Text(256, 0), 'GitHub setup action type', true)
    ->param('state', '', new Text(2048), 'GitHub state. Contains info sent when starting authorization flow.', true)
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->inject('gitHub')
    ->inject('user')
    ->inject('project')
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $providerInstallationId, string $setupAction, string $state, string $code, GitHub $github, Document $user, Document $project, Request $request, Response $response, Database $dbForPlatform) {
        if (empty($state)) {
            $error = 'Installation requests from organisation members for the Appwrite GitHub App are currently unsupported. To proceed with the installation, login to the Appwrite Console and install the GitHub App.';
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
        }

        $state = \json_decode($state, true);
        $projectId = $state['projectId'] ?? '';

        $defaultState = [
            'success' => $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/settings/git-installations",
            'failure' => $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/settings/git-installations",
        ];

        $state = \array_merge($defaultState, $state ?? []);

        $redirectSuccess = $state['success'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : ':';
                return $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
            }

            throw new Exception(Exception::PROJECT_NOT_FOUND, $error);
        }

        // Create / Update installation
        if (!empty($providerInstallationId)) {
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($providerInstallationId) ?? '';

            $projectInternalId = $project->getInternalId();

            $installation = $dbForPlatform->findOne('installations', [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('projectInternalId', [$projectInternalId])
            ]);

            $personal = false;
            $refreshToken = null;
            $accessToken = null;
            $accessTokenExpiry = null;

            if (!empty($code)) {
                $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

                $accessToken = $oauth2->getAccessToken($code) ?? '';
                $refreshToken = $oauth2->getRefreshToken($code) ?? '';
                $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));

                $personalSlug = $oauth2->getUserSlug($accessToken) ?? '';
                $personal = $personalSlug === $owner;
            }

            if ($installation->isEmpty()) {
                $teamId = $project->getAttribute('teamId', '');

                $installation = new Document([
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::team(ID::custom($teamId))),
                        Permission::update(Role::team(ID::custom($teamId), 'owner')),
                        Permission::update(Role::team(ID::custom($teamId), 'developer')),
                        Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                        Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                    ],
                    'providerInstallationId' => $providerInstallationId,
                    'projectId' => $projectId,
                    'projectInternalId' => $projectInternalId,
                    'provider' => 'github',
                    'organization' => $owner,
                    'personal' => $personal,
                    'personalRefreshToken' => $refreshToken,
                    'personalAccessToken' => $accessToken,
                    'personalAccessTokenExpiry' => $accessTokenExpiry,
                ]);

                $installation = $dbForPlatform->createDocument('installations', $installation);
            } else {
                $installation = $installation
                    ->setAttribute('organization', $owner)
                    ->setAttribute('personal', $personal)
                    ->setAttribute('personalRefreshToken', $refreshToken)
                    ->setAttribute('personalAccessToken', $accessToken)
                    ->setAttribute('personalAccessTokenExpiry', $accessTokenExpiry);
                $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), $installation);
            }
        } else {
            $error = 'Installation of the Appwrite GitHub App on organization accounts is restricted to organization owners. As a member of the organization, you do not have the necessary permissions to install this GitHub App. Please contact the organization owner to create the installation from the Appwrite console.';

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : ':';
                return $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
            }

            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/contents')
    ->desc('Get files and directories of a VCS repository')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'getRepositoryContents',
        description: '/docs/references/vcs/get-repository-contents.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_VCS_CONTENT_LIST,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->param('providerRootDirectory', '', new Text(256, 0), 'Path to get contents of nested directory', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, string $providerRepositoryId, string $providerRootDirectory, GitHub $github, Response $response, Document $project, Database $dbForPlatform) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId);
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $contents = $github->listRepositoryContents($owner, $repositoryName, $providerRootDirectory);

        $vcsContents = [];
        foreach ($contents as $content) {
            $isDirectory = false;
            if ($content['type'] === GitHub::CONTENTS_DIRECTORY) {
                $isDirectory = true;
            }

            $vcsContents[] = new Document([
                'isDirectory' => $isDirectory,
                'name' => $content['name'] ?? '',
                'size' => $content['size'] ?? 0
            ]);
        }

        $response->dynamic(new Document([
            'contents' => $vcsContents
        ]), Response::MODEL_VCS_CONTENT_LIST);
    });

App::post('/v1/vcs/github/installations/:installationId/detections')
    ->alias('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/detection')
    ->desc('Create repository detection')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'createRepositoryDetection',
        description: '/docs/references/vcs/create-repository-detection.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DETECTION_RUNTIME,
            ),
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DETECTION_FRAMEWORK,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->param('type', '', new WhiteList(['runtime', 'framework']), 'Detector type. Must be one of the following: runtime, framework')
    ->param('providerRootDirectory', '', new Text(256, 0), 'Path to Root Directory', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, string $providerRepositoryId, string $type, string $providerRootDirectory, GitHub $github, Response $response, Database $dbForPlatform) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId);
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $files = $github->listRepositoryContents($owner, $repositoryName, $providerRootDirectory);
        $files = \array_column($files, 'name');
        $languages = $github->listRepositoryLanguages($owner, $repositoryName);

        $detector = new Packager($files);
        $detector
            ->addOption(new Yarn())
            ->addOption(new PNPM())
            ->addOption(new NPM());
        $detection = $detector->detect();

        $packager = !\is_null($detection) ? $detection->getName() : 'npm';

        if ($type === 'framework') {
            $output = new Document([
                'framework' => '',
                'installCommand' => '',
                'buildCommand' => '',
                'outputDirectory' => '',
            ]);

            $detector = new Framework($files, $packager);
            $detector
                ->addOption(new Flutter())
                ->addOption(new Nuxt())
                ->addOption(new Astro())
                ->addOption(new SvelteKit())
                ->addOption(new NextJs())
                ->addOption(new Remix());

            $framework = $detector->detect();

            if (!\is_null($framework)) {
                $output->setAttribute('installCommand', $framework->getInstallCommand());
                $output->setAttribute('buildCommand', $framework->getBuildCommand());
                $output->setAttribute('outputDirectory', $framework->getOutputDirectory());
                $framework = $framework->getName();
            } else {
                $framework = 'other';
                $output->setAttribute('installCommand', '');
                $output->setAttribute('buildCommand', '');
                $output->setAttribute('outputDirectory', '');
            }

            $frameworks = Config::getParam('frameworks');
            if (!\in_array($framework, \array_keys($frameworks), true)) {
                $framework = 'other';
            }
            $output->setAttribute('framework', $framework);
        } else {
            $output = new Document([
                'runtime' => '',
                'commands' => '',
                'entrypoint' => '',
            ]);

            $strategies = [
                new Strategy(Strategy::FILEMATCH),
                new Strategy(Strategy::LANGUAGES),
                new Strategy(Strategy::EXTENSION),
            ];

            foreach ($strategies as $strategy) {
                $detector = new Runtime($strategy === Strategy::LANGUAGES ? $languages : $files, $strategy, $packager);
                $detector
                    ->addOption(new Node())
                    ->addOption(new Bun())
                    ->addOption(new Deno())
                    ->addOption(new PHP())
                    ->addOption(new Python())
                    ->addOption(new Dart())
                    ->addOption(new Swift())
                    ->addOption(new Ruby())
                    ->addOption(new Java())
                    ->addOption(new CPP())
                    ->addOption(new Dotnet());

                $runtime = $detector->detect();

                if (!\is_null($runtime)) {
                    $output->setAttribute('commands', $runtime->getCommands());
                    $output->setAttribute('entrypoint', $runtime->getEntrypoint());
                    $runtime = $runtime->getName();
                    break;
                }
            }

            if (!empty($runtime)) {
                $runtimes = Config::getParam('runtimes');
                $runtimeWithVersion = '';
                foreach ($runtimes as $runtimeKey => $runtimeConfig) {
                    if ($runtimeConfig['key'] === $runtime) {
                        $runtimeWithVersion = $runtimeKey;
                    }
                }

                if (empty($runtimeWithVersion)) {
                    throw new Exception(Exception::FUNCTION_RUNTIME_NOT_DETECTED);
                }

                $output->setAttribute('runtime', $runtimeWithVersion);
            } else {
                throw new Exception(Exception::FUNCTION_RUNTIME_NOT_DETECTED);
            }
        }
        $response->dynamic($output, $type === 'framework' ? Response::MODEL_DETECTION_FRAMEWORK : Response::MODEL_DETECTION_RUNTIME);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories')
    ->desc('List repositories')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'listRepositories',
        description: '/docs/references/vcs/list-repositories.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST,
            ),
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('type', '', new WhiteList(['runtime', 'framework']), 'Detector type. Must be one of the following: runtime, framework')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, string $type, string $search, GitHub $github, Response $response, Database $dbForPlatform) {
        if (empty($search)) {
            $search = "";
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $page = 1;
        $perPage = 4;

        $owner = $github->getOwnerName($providerInstallationId);
        $repos = $github->searchRepositories($owner, $page, $perPage, $search);

        $repos = \array_map(function ($repo) use ($installation) {
            $repo['id'] = \strval($repo['id'] ?? '');
            $repo['pushedAt'] = $repo['pushed_at'] ?? null;
            $repo['provider'] = $installation->getAttribute('provider', '') ?? '';
            $repo['organization'] = $installation->getAttribute('organization', '') ?? '';
            return $repo;
        }, $repos);

        $repos = batch(\array_map(function ($repo) use ($type, $github) {
            return function () use ($repo, $type, $github) {
                $files = $github->listRepositoryContents($repo['organization'], $repo['name'], '');
                $files = \array_column($files, 'name');

                $detector = new Packager($files);
                $detector
                    ->addOption(new Yarn())
                    ->addOption(new PNPM())
                    ->addOption(new NPM());
                $detection = $detector->detect();

                $packager = !\is_null($detection) ? $detection->getName() : 'npm';

                if ($type === 'framework') {
                    $frameworkDetector = new Framework($files, $packager);
                    $frameworkDetector
                        ->addOption(new Flutter())
                        ->addOption(new Nuxt())
                        ->addOption(new Astro())
                        ->addOption(new SvelteKit())
                        ->addOption(new NextJs())
                        ->addOption(new Remix());

                    $detectedFramework = $frameworkDetector->detect();

                    if (!\is_null($detectedFramework)) {
                        $framework = $detectedFramework->getName();
                    } else {
                        $framework = 'other';
                    }

                    $frameworks = Config::getParam('frameworks');
                    if (!\in_array($framework, \array_keys($frameworks), true)) {
                        $framework = 'other';
                    }
                    $repo['framework'] = $framework;
                } else {
                    $languages = $github->listRepositoryLanguages($repo['organization'], $repo['name']);

                    $strategies = [
                        new Strategy(Strategy::FILEMATCH),
                        new Strategy(Strategy::LANGUAGES),
                        new Strategy(Strategy::EXTENSION),
                    ];

                    foreach ($strategies as $strategy) {
                        $detector = new Runtime($strategy === Strategy::LANGUAGES ? $languages : $files, $strategy, $packager);
                        $detector
                            ->addOption(new Node())
                            ->addOption(new Bun())
                            ->addOption(new Deno())
                            ->addOption(new PHP())
                            ->addOption(new Python())
                            ->addOption(new Dart())
                            ->addOption(new Swift())
                            ->addOption(new Ruby())
                            ->addOption(new Java())
                            ->addOption(new CPP())
                            ->addOption(new Dotnet());

                        $runtime = $detector->detect();

                        if (!\is_null($runtime)) {
                            $runtime = $runtime->getName();
                            break;
                        }
                    }

                    if (!empty($runtime)) {
                        $runtimes = Config::getParam('runtimes');
                        $runtimeWithVersion = '';
                        foreach ($runtimes as $runtimeKey => $runtimeConfig) {
                            if ($runtimeConfig['key'] === $runtime) {
                                $runtimeWithVersion = $runtimeKey;
                            }
                        }

                        $repo['runtime'] = $runtimeWithVersion ?? '';
                    }
                }
                return $repo;
            };
        }, $repos));

        $repos = \array_map(function ($repo) {
            return new Document($repo);
        }, $repos);

        $response->dynamic(new Document([
            $type === 'framework' ? 'frameworkProviderRepositories' : 'runtimeProviderRepositories' => $repos,
            'total' => \count($repos),
        ]), ($type === 'framework') ? Response::MODEL_PROVIDER_REPOSITORY_FRAMEWORK_LIST : Response::MODEL_PROVIDER_REPOSITORY_RUNTIME_LIST);
    });

App::post('/v1/vcs/github/installations/:installationId/providerRepositories')
    ->desc('Create repository')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'createRepository',
        description: '/docs/references/vcs/create-repository.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER_REPOSITORY,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('name', '', new Text(256), 'Repository name (slug)')
    ->param('private', '', new Boolean(false), 'Mark repository public or private')
    ->inject('gitHub')
    ->inject('user')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, string $name, bool $private, GitHub $github, Document $user, Response $response, Document $project, Database $dbForPlatform) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('personal', false) === true) {
            $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

            $accessToken = $installation->getAttribute('personalAccessToken');
            $refreshToken = $installation->getAttribute('personalRefreshToken');
            $accessTokenExpiry = $installation->getAttribute('personalAccessTokenExpiry');

            if (empty($accessToken) || empty($refreshToken) || empty($accessTokenExpiry)) {
                $identity = $dbForPlatform->findOne('identities', [
                    Query::equal('provider', ['github']),
                    Query::equal('userInternalId', [$user->getInternalId()]),
                ]);
                if ($identity->isEmpty()) {
                    throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
                }

                $accessToken = $accessToken ?? $identity->getAttribute('providerAccessToken');
                $refreshToken = $refreshToken ?? $identity->getAttribute('providerRefreshToken');
                $accessTokenExpiry = $accessTokenExpiry ?? $identity->getAttribute('providerAccessTokenExpiry');
            }

            $isExpired = new \DateTime($accessTokenExpiry) < new \DateTime('now');
            if ($isExpired) {
                $oauth2->refreshTokens($refreshToken);

                $accessToken = $oauth2->getAccessToken('');
                $refreshToken = $oauth2->getRefreshToken('');

                $verificationId = $oauth2->getUserID($accessToken);

                if (empty($verificationId)) {
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, "Another request is currently refreshing OAuth token. Please try again.");
                }

                $installation = $installation
                    ->setAttribute('personalAccessToken', $accessToken)
                    ->setAttribute('personalRefreshToken', $refreshToken)
                    ->setAttribute('personalAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

                $dbForPlatform->updateDocument('installations', $installation->getId(), $installation);
            }

            try {
                $repository = $oauth2->createRepository($accessToken, $name, $private);
            } catch (Exception $exception) {
                throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, "GitHub failed to process the request: " . $exception->getMessage());
            }
        } else {
            $providerInstallationId = $installation->getAttribute('providerInstallationId');
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($providerInstallationId);

            try {
                $repository = $github->createRepository($owner, $name, $private);
            } catch (Exception $exception) {
                throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, "GitHub failed to process the request: " . $exception->getMessage());
            }
        }

        if (isset($repository['errors'])) {
            $message = $repository['message'] ?? 'Unknown error.';
            if (isset($repository['errors'][0])) {
                $message .= ' ' . $repository['errors'][0]['message'];
            }
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Provider Error: ' . $message);
        }

        if (isset($repository['message'])) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Provider Error: ' . $repository['message']);
        }

        $repository['id'] = \strval($repository['id']) ?? '';
        $repository['pushedAt'] = $repository['pushed_at'] ?? '';
        $repository['organization'] = $installation->getAttribute('organization', '');
        $repository['provider'] = $installation->getAttribute('provider', '');

        $response->dynamic(new Document($repository), Response::MODEL_PROVIDER_REPOSITORY);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId')
    ->desc('Get repository')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'getRepository',
        description: '/docs/references/vcs/get-repository.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER_REPOSITORY,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, string $providerRepositoryId, GitHub $github, Response $response, Document $project, Database $dbForPlatform) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId) ?? '';
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $repository = $github->getRepository($owner, $repositoryName);

        $repository['id'] = \strval($repository['id']) ?? '';
        $repository['pushedAt'] = $repository['pushed_at'] ?? '';
        $repository['organization'] = $installation->getAttribute('organization', '');
        $repository['provider'] = $installation->getAttribute('provider', '');

        $response->dynamic(new Document($repository), Response::MODEL_PROVIDER_REPOSITORY);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/branches')
    ->desc('List repository branches')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'listRepositoryBranches',
        description: '/docs/references/vcs/list-repository-branches.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_BRANCH_LIST,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, string $providerRepositoryId, GitHub $github, Response $response, Document $project, Database $dbForPlatform) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId) ?? '';
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $branches = $github->listBranches($owner, $repositoryName) ?? [];

        $response->dynamic(new Document([
            'branches' => \array_map(function ($branch) {
                return new Document(['name' => $branch]);
            }, $branches),
            'total' => \count($branches),
        ]), Response::MODEL_BRANCH_LIST);
    });

App::post('/v1/vcs/github/events')
    ->desc('Create event')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->inject('gitHub')
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForBuilds')
    ->action(
        function (GitHub $github, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Build $queueForBuilds) use ($createGitDeployments) {
            $payload = $request->getRawPayload();
            $signatureRemote = $request->getHeader('x-hub-signature-256', '');
            $signatureLocal = System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '');

            $valid = empty($signatureRemote) ? true : $github->validateWebhookEvent($payload, $signatureRemote, $signatureLocal);

            if (!$valid) {
                throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook payload signature. Please make sure the webhook secret has same value in your GitHub app and in the _APP_VCS_GITHUB_WEBHOOK_SECRET environment variable");
            }

            $event = $request->getHeader('x-github-event', '');
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
            $parsedPayload = $github->getEvent($event, $payload);

            if ($event == $github::EVENT_PUSH) {
                $providerBranchCreated = $parsedPayload["branchCreated"] ?? false;
                $providerBranch = $parsedPayload["branch"] ?? '';
                $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
                $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
                $providerInstallationId = $parsedPayload["installationId"] ?? '';
                $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
                $providerCommitHash = $parsedPayload["commitHash"] ?? '';
                $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
                $providerCommitAuthor = $parsedPayload["headCommitAuthor"] ?? '';
                $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';
                $providerCommitMessage = $parsedPayload["headCommitMessage"] ?? '';
                $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';

                $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

                //find resourceId from relevant resources table
                $repositories = Authorization::skip(fn () => $dbForPlatform->find('repositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::limit(100),
                ]));

                // create new deployment only on push and not when branch is created
                if (!$providerBranchCreated) {
                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', false, $dbForPlatform, $queueForBuilds, $getProjectDB, $request);
                }
            } elseif ($event == $github::EVENT_INSTALLATION) {
                if ($parsedPayload["action"] == "deleted") {
                    // TODO: Use worker for this job instead (update function/site as well)
                    $providerInstallationId = $parsedPayload["installationId"];

                    $installations = $dbForPlatform->find('installations', [
                        Query::equal('providerInstallationId', [$providerInstallationId]),
                        Query::limit(1000)
                    ]);

                    foreach ($installations as $installation) {
                        $repositories = Authorization::skip(fn () => $dbForPlatform->find('repositories', [
                            Query::equal('installationInternalId', [$installation->getInternalId()]),
                            Query::limit(1000)
                        ]));

                        foreach ($repositories as $repository) {
                            Authorization::skip(fn () => $dbForPlatform->deleteDocument('repositories', $repository->getId()));
                        }

                        $dbForPlatform->deleteDocument('installations', $installation->getId());
                    }
                }
            } elseif ($event == $github::EVENT_PULL_REQUEST) {
                if ($parsedPayload["action"] == "opened" || $parsedPayload["action"] == "reopened" || $parsedPayload["action"] == "synchronize") {
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

                    // Ignore sync for non-external. We handle it in push webhook
                    if (!$external && $parsedPayload["action"] == "synchronize") {
                        return $response->json($parsedPayload);
                    }

                    $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

                    $commitDetails = $github->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
                    $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
                    $providerCommitMessage = $commitDetails["commitMessage"] ?? '';

                    $repositories = Authorization::skip(fn () => $dbForPlatform->find('repositories', [
                        Query::equal('providerRepositoryId', [$providerRepositoryId]),
                        Query::orderDesc('$createdAt')
                    ]));

                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $external, $dbForPlatform, $queueForBuilds, $getProjectDB, $request);
                } elseif ($parsedPayload["action"] == "closed") {
                    // Allowed external contributions cleanup

                    $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                    $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    if ($external) {
                        $repositories = Authorization::skip(fn () => $dbForPlatform->find('repositories', [
                            Query::equal('providerRepositoryId', [$providerRepositoryId]),
                            Query::orderDesc('$createdAt')
                        ]));

                        foreach ($repositories as $repository) {
                            $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                            if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                                $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                                $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                                $repository = Authorization::skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository));
                            }
                        }
                    }
                }
            }

            $response->json($parsedPayload);
        }
    );

App::get('/v1/vcs/installations')
    ->desc('List installations')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'installations',
        name: 'listInstallations',
        description: '/docs/references/vcs/list-installations.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_INSTALLATION_LIST,
            )
        ]
    ))
    ->param('queries', [], new Installations(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Installations::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->action(function (array $queries, string $search, Response $response, Document $project, Database $dbForProject, Database $dbForPlatform) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('projectInternalId', [$project->getInternalId()]);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $installationId = $cursor->getValue();
            $cursorDocument = $dbForPlatform->getDocument('installations', $installationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Installation '{$installationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        try {
            $results = $dbForPlatform->find('installations', $queries);
            $total = $dbForPlatform->count('installations', $filterQueries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'installations' => $results,
            'total' => $total,
        ]), Response::MODEL_INSTALLATION_LIST);
    });

App::get('/v1/vcs/installations/:installationId')
    ->desc('Get installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'installations',
        name: 'getInstallation',
        description: '/docs/references/vcs/get-installation.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_INSTALLATION,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (string $installationId, Response $response, Document $project, Database $dbForPlatform) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation === false || $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $response->dynamic($installation, Response::MODEL_INSTALLATION);
    });

App::delete('/v1/vcs/installations/:installationId')
    ->desc('Delete installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'installations',
        name: 'deleteInstallation',
        description: '/docs/references/vcs/delete-installation.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('queueForDeletes')
    ->action(function (string $installationId, Response $response, Document $project, Database $dbForPlatform, Delete $queueForDeletes) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!$dbForPlatform->deleteDocument('installations', $installation->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove installation from DB');
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($installation);

        $response->noContent();
    });

App::patch('/v1/vcs/github/installations/:installationId/repositories/:repositoryId')
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
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForBuilds')
    ->action(function (string $installationId, string $repositoryId, string $providerPullRequestId, GitHub $github, Request $request, Response $response, Document $project, Database $dbForPlatform, callable $getProjectDB, Build $queueForBuilds) use ($createGitDeployments) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $repository = Authorization::skip(fn () => $dbForPlatform->getDocument('repositories', $repositoryId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
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

        $repository = Authorization::skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository));

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

        $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerCommitHash, $providerPullRequestId, true, $dbForPlatform, $queueForBuilds, $getProjectDB, $request);

        $response->noContent();
    });
