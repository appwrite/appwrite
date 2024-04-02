<?php

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Installations;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Comment;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Detector\Adapter\Bun;
use Utopia\Detector\Adapter\CPP;
use Utopia\Detector\Adapter\Dart;
use Utopia\Detector\Adapter\Deno;
use Utopia\Detector\Adapter\Dotnet;
use Utopia\Detector\Adapter\Java;
use Utopia\Detector\Adapter\JavaScript;
use Utopia\Detector\Adapter\PHP;
use Utopia\Detector\Adapter\Python;
use Utopia\Detector\Adapter\Ruby;
use Utopia\Detector\Adapter\Swift;
use Utopia\Detector\Detector;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Host;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

use function Swoole\Coroutine\batch;

$createGitDeployments = function (GitHub $github, string $providerInstallationId, array $repositories, string $providerBranch, string $providerBranchUrl, string $providerRepositoryName, string $providerRepositoryUrl, string $providerRepositoryOwner, string $providerCommitHash, string $providerCommitAuthor, string $providerCommitAuthorUrl, string $providerCommitMessage, string $providerCommitUrl, string $providerPullRequestId, bool $external, Database $dbForConsole, Build $queueForBuilds, callable $getProjectDB, Request $request) {
    $errors = [];
    foreach ($repositories as $resource) {
        try {
            $resourceType = $resource->getAttribute('resourceType');

            if ($resourceType !== "function") {
                continue;
            }

            $projectId = $resource->getAttribute('projectId');
            $project = Authorization::skip(fn () => $dbForConsole->getDocument('projects', $projectId));
            $dbForProject = $getProjectDB($project);

            $functionId = $resource->getAttribute('resourceId');
            $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));
            $functionInternalId = $function->getInternalId();

            $deploymentId = ID::unique();
            $repositoryId = $resource->getId();
            $repositoryInternalId = $resource->getInternalId();
            $providerRepositoryId = $resource->getAttribute('providerRepositoryId');
            $installationId = $resource->getAttribute('installationId');
            $installationInternalId = $resource->getAttribute('installationInternalId');
            $productionBranch = $function->getAttribute('providerBranch');
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
                if (\in_array($providerPullRequestId, $resource->getAttribute('providerPullRequestIds', []))) {
                    $isAuthorized = true;
                }
            }

            $commentStatus = $isAuthorized ? 'waiting' : 'failed';

            $authorizeUrl = $request->getProtocol() . '://' . $request->getHostname() . "/git/authorize-contributor?projectId={$projectId}&installationId={$installationId}&repositoryId={$repositoryId}&providerPullRequestId={$providerPullRequestId}";

            $action = $isAuthorized ? ['type' => 'logs'] : ['type' => 'authorize', 'url' => $authorizeUrl];

            $latestCommentId = '';

            if (!empty($providerPullRequestId) && $function->getAttribute('providerSilentMode', false) === false) {
                $latestComment = Authorization::skip(fn () => $dbForConsole->findOne('vcsComments', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::equal('providerPullRequestId', [$providerPullRequestId]),
                    Query::orderDesc('$createdAt'),
                ]));

                if ($latestComment !== false && !$latestComment->isEmpty()) {
                    $latestCommentId = $latestComment->getAttribute('providerCommentId', '');
                    $comment = new Comment();
                    $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                    $comment->addBuild($project, $function, $commentStatus, $deploymentId, $action);

                    $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                } else {
                    $comment = new Comment();
                    $comment->addBuild($project, $function, $commentStatus, $deploymentId, $action);
                    $latestCommentId = \strval($github->createComment($owner, $repositoryName, $providerPullRequestId, $comment->generateComment()));

                    if (!empty($latestCommentId)) {
                        $teamId = $project->getAttribute('teamId', '');

                        $latestComment = Authorization::skip(fn () => $dbForConsole->createDocument('vcsComments', new Document([
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
                $latestComments = Authorization::skip(fn () => $dbForConsole->find('vcsComments', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::equal('providerBranch', [$providerBranch]),
                    Query::orderDesc('$createdAt'),
                ]));

                foreach ($latestComments as $comment) {
                    $latestCommentId = $comment->getAttribute('providerCommentId', '');
                    $comment = new Comment();
                    $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                    $comment->addBuild($project, $function, $commentStatus, $deploymentId, $action);

                    $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                }
            }

            if (!$isAuthorized) {
                $functionName = $function->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$functionName} ({$projectName})";
                $message = 'Authorization required for external contributor.';

                $providerRepositoryId = $resource->getAttribute('providerRepositoryId');
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

            $deployment = $dbForProject->createDocument('deployments', new Document([
                '$id' => $deploymentId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'resourceId' => $functionId,
                'resourceInternalId' => $functionInternalId,
                'resourceType' => 'functions',
                'entrypoint' => $function->getAttribute('entrypoint'),
                'commands' => $function->getAttribute('commands'),
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
                'providerCommitMessage' => $providerCommitMessage,
                'providerCommitUrl' => $providerCommitUrl,
                'providerCommentId' => \strval($latestCommentId),
                'providerBranch' => $providerBranch,
                'search' => implode(' ', [$deploymentId, $function->getAttribute('entrypoint')]),
                'activate' => $activate,
            ]));

            if (!empty($providerCommitHash) && $function->getAttribute('providerSilentMode', false) === false) {
                $functionName = $function->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$functionName} ({$projectName})";
                $message = 'Starting...';

                $providerRepositoryId = $resource->getAttribute('providerRepositoryId');
                try {
                    $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                    if (empty($repositoryName)) {
                        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                    }
                } catch (RepositoryNotFound $e) {
                    throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                }
                $owner = $github->getOwnerName($providerInstallationId);

                $providerTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/functions/function-$functionId";
                $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'pending', $message, $providerTargetUrl, $name);
            }

            $queueForBuilds
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($function)
                ->setDeployment($deployment)
                ->setProject($project); // set the project because it won't be set for git deployments

            $queueForBuilds->trigger(); // must trigger here so that we create a build for each function

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
    ->desc('Install GitHub App')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk.namespace', 'vcs')
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'createGitHubInstallation')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('sdk.hide', true)
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
    ->desc('Capture installation and authorization from GitHub App')
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
    ->inject('dbForConsole')
    ->action(function (string $providerInstallationId, string $setupAction, string $state, string $code, GitHub $github, Document $user, Document $project, Request $request, Response $response, Database $dbForConsole) {
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

        $project = $dbForConsole->getDocument('projects', $projectId);

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

        $personalSlug = '';

        // OAuth Authroization
        if (!empty($code)) {
            $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");
            $accessToken = $oauth2->getAccessToken($code) ?? '';
            $refreshToken = $oauth2->getRefreshToken($code) ?? '';
            $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code) ?? '';
            $personalSlug = $oauth2->getUserSlug($accessToken) ?? '';
            $email = $oauth2->getUserEmail($accessToken);
            $oauth2ID = $oauth2->getUserID($accessToken);

            // Makes sure this email is not already used in another identity
            $identity = $dbForConsole->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if ($identity !== false && !$identity->isEmpty()) {
                if ($identity->getAttribute('userInternalId', '') !== $user->getInternalId()) {
                    throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
                }
            }

            if ($identity !== false && !$identity->isEmpty()) {
                $identity = $identity
                    ->setAttribute('providerAccessToken', $accessToken)
                    ->setAttribute('providerRefreshToken', $refreshToken)
                    ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry));

                $dbForConsole->updateDocument('identities', $identity->getId(), $identity);
            } else {
                $identity = $dbForConsole->createDocument('identities', new Document([
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'userInternalId' => $user->getInternalId(),
                    'userId' => $user->getId(),
                    'provider' => 'github',
                    'providerUid' => $oauth2ID,
                    'providerEmail' => $email,
                    'providerAccessToken' => $accessToken,
                    'providerRefreshToken' => $refreshToken,
                    'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry),
                ]));
            }
        }

        // Create / Update installation
        if (!empty($providerInstallationId)) {
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($providerInstallationId) ?? '';

            $projectInternalId = $project->getInternalId();

            $installation = $dbForConsole->findOne('installations', [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('projectInternalId', [$projectInternalId])
            ]);

            if ($installation === false || $installation->isEmpty()) {
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
                    'personal' => $personalSlug === $owner
                ]);

                $installation = $dbForConsole->createDocument('installations', $installation);
            } else {
                $installation = $installation
                    ->setAttribute('organization', $owner)
                    ->setAttribute('personal', $personalSlug === $owner);
                $installation = $dbForConsole->updateDocument('installations', $installation->getId(), $installation);
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

App::post('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/detection')
    ->desc('Detect runtime settings from source code')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'createRepositoryDetection')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DETECTION)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->param('providerRootDirectory', '', new Text(256, 0), 'Path to Root Directory', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $providerRepositoryId, string $providerRootDirectory, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

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
        $languages = $github->listRepositoryLanguages($owner, $repositoryName);

        $detectorFactory = new Detector($files, $languages);

        $detectorFactory
            ->addDetector(new JavaScript())
            ->addDetector(new Bun())
            ->addDetector(new PHP())
            ->addDetector(new Python())
            ->addDetector(new Dart())
            ->addDetector(new Swift())
            ->addDetector(new Ruby())
            ->addDetector(new Java())
            ->addDetector(new CPP())
            ->addDetector(new Deno())
            ->addDetector(new Dotnet());

        $runtime = $detectorFactory->detect();

        $runtimes = Config::getParam('runtimes');
        $runtimeDetail = \array_reverse(\array_filter(\array_keys($runtimes), function ($key) use ($runtime, $runtimes) {
            return $runtimes[$key]['key'] === $runtime;
        }))[0] ?? '';

        $detection = [];
        $detection['runtime'] = $runtimeDetail;

        $response->dynamic(new Document($detection), Response::MODEL_DETECTION);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories')
    ->desc('List Repositories')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'listRepositories')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER_REPOSITORY_LIST)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $search, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        if (empty($search)) {
            $search = "";
        }

        $installation = $dbForConsole->getDocument('installations', $installationId);

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

        $repos = batch(\array_map(function ($repo) use ($github) {
            return function () use ($repo, $github) {
                try {
                    $files = $github->listRepositoryContents($repo['organization'], $repo['name'], '');
                    $languages = $github->listRepositoryLanguages($repo['organization'], $repo['name']);

                    $detectorFactory = new Detector($files, $languages);

                    $detectorFactory
                        ->addDetector(new JavaScript())
                        ->addDetector(new Bun())
                        ->addDetector(new PHP())
                        ->addDetector(new Python())
                        ->addDetector(new Dart())
                        ->addDetector(new Swift())
                        ->addDetector(new Ruby())
                        ->addDetector(new Java())
                        ->addDetector(new CPP())
                        ->addDetector(new Deno())
                        ->addDetector(new Dotnet());

                    $runtime = $detectorFactory->detect();

                    $runtimes = Config::getParam('runtimes');
                    $runtimeDetail = \array_reverse(\array_filter(\array_keys($runtimes), function ($key) use ($runtime, $runtimes) {
                        return $runtimes[$key]['key'] === $runtime;
                    }))[0] ?? '';

                    $repo['runtime'] = $runtimeDetail;
                } catch (Throwable $error) {
                    $repo['runtime'] = "";
                    Console::warning("Runtime not detected for " . $repo['organization'] . "/" . $repo['name']);
                }
                return $repo;
            };
        }, $repos));

        $repos = \array_map(function ($repo) {
            return new Document($repo);
        }, $repos);

        $response->dynamic(new Document([
            'providerRepositories' => $repos,
            'total' => \count($repos),
        ]), Response::MODEL_PROVIDER_REPOSITORY_LIST);
    });

App::post('/v1/vcs/github/installations/:installationId/providerRepositories')
    ->desc('Create repository')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'createRepository')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER_REPOSITORY)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('name', '', new Text(256), 'Repository name (slug)')
    ->param('private', '', new Boolean(false), 'Mark repository public or private')
    ->inject('gitHub')
    ->inject('user')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $name, bool $private, GitHub $github, Document $user, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('personal', false) === true) {
            $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

            $identity = $dbForConsole->findOne('identities', [
                Query::equal('provider', ['github']),
                Query::equal('userInternalId', [$user->getInternalId()]),
            ]);
            if ($identity === false || $identity->isEmpty()) {
                throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
            }

            $accessToken = $identity->getAttribute('providerAccessToken');
            $refreshToken = $identity->getAttribute('providerRefreshToken');
            $accessTokenExpiry = $identity->getAttribute('providerAccessTokenExpiry');

            $isExpired = new \DateTime($accessTokenExpiry) < new \DateTime('now');
            if ($isExpired) {
                $oauth2->refreshTokens($refreshToken);

                $accessToken = $oauth2->getAccessToken('');
                $refreshToken = $oauth2->getRefreshToken('');

                $verificationId = $oauth2->getUserID($accessToken);

                if (empty($verificationId)) {
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, "Another request is currently refreshing OAuth token. Please try again.");
                }

                $identity = $identity
                    ->setAttribute('providerAccessToken', $accessToken)
                    ->setAttribute('providerRefreshToken', $refreshToken)
                    ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

                $dbForConsole->updateDocument('identities', $identity->getId(), $identity);
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
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'getRepository')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER_REPOSITORY)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $providerRepositoryId, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

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
    ->desc('List Repository Branches')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'listRepositoryBranches')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BRANCH_LIST)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $providerRepositoryId, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

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
    ->desc('Create Event')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->inject('gitHub')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('getProjectDB')
    ->inject('queueForBuilds')
    ->action(
        function (GitHub $github, Request $request, Response $response, Database $dbForConsole, callable $getProjectDB, Build $queueForBuilds) use ($createGitDeployments) {
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

                //find functionId from functions table
                $repositories = Authorization::skip(fn () => $dbForConsole->find('repositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::limit(100),
                ]));

                // create new deployment only on push and not when branch is created
                if (!$providerBranchCreated) {
                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', false, $dbForConsole, $queueForBuilds, $getProjectDB, $request);
                }
            } elseif ($event == $github::EVENT_INSTALLATION) {
                if ($parsedPayload["action"] == "deleted") {
                    // TODO: Use worker for this job instead (update function as well)
                    $providerInstallationId = $parsedPayload["installationId"];

                    $installations = $dbForConsole->find('installations', [
                        Query::equal('providerInstallationId', [$providerInstallationId]),
                        Query::limit(1000)
                    ]);

                    foreach ($installations as $installation) {
                        $repositories = Authorization::skip(fn () => $dbForConsole->find('repositories', [
                            Query::equal('installationInternalId', [$installation->getInternalId()]),
                            Query::limit(1000)
                        ]));

                        foreach ($repositories as $repository) {
                            Authorization::skip(fn () => $dbForConsole->deleteDocument('repositories', $repository->getId()));
                        }

                        $dbForConsole->deleteDocument('installations', $installation->getId());
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

                    $repositories = Authorization::skip(fn () => $dbForConsole->find('repositories', [
                        Query::equal('providerRepositoryId', [$providerRepositoryId]),
                        Query::orderDesc('$createdAt')
                    ]));

                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $external, $dbForConsole, $queueForBuilds, $getProjectDB, $request);
                } elseif ($parsedPayload["action"] == "closed") {
                    // Allowed external contributions cleanup

                    $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                    $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    if ($external) {
                        $repositories = Authorization::skip(fn () => $dbForConsole->find('repositories', [
                            Query::equal('providerRepositoryId', [$providerRepositoryId]),
                            Query::orderDesc('$createdAt')
                        ]));

                        foreach ($repositories as $repository) {
                            $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                            if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                                $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                                $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                                $repository = Authorization::skip(fn () => $dbForConsole->updateDocument('repositories', $repository->getId(), $repository));
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
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'listInstallations')
    ->label('sdk.description', '/docs/references/vcs/list-installations.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INSTALLATION_LIST)
    ->param('queries', [], new Installations(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Installations::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (array $queries, string $search, Response $response, Document $project, Database $dbForProject, Database $dbForConsole) {
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
            $installationId = $cursor->getValue();
            $cursorDocument = $dbForConsole->getDocument('installations', $installationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Installation '{$installationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForConsole->find('installations', $queries);
        $total = $dbForConsole->count('installations', $filterQueries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'installations' => $results,
            'total' => $total,
        ]), Response::MODEL_INSTALLATION_LIST);
    });

App::get('/v1/vcs/installations/:installationId')
    ->desc('Get installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.read')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'getInstallation')
    ->label('sdk.description', '/docs/references/vcs/get-installation.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INSTALLATION)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

        if ($installation === false || $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $response->dynamic($installation, Response::MODEL_INSTALLATION);
    });

App::delete('/v1/vcs/installations/:installationId')
    ->desc('Delete Installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'deleteInstallation')
    ->label('sdk.description', '/docs/references/vcs/delete-installation.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('queueForDeletes')
    ->action(function (string $installationId, Response $response, Document $project, Database $dbForConsole, Delete $queueForDeletes) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!$dbForConsole->deleteDocument('installations', $installation->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove installation from DB');
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($installation);

        $response->noContent();
    });

App::patch('/v1/vcs/github/installations/:installationId/repositories/:repositoryId')
    ->desc('Authorize external deployment')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.method', 'updateExternalDeployments')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('repositoryId', '', new Text(256), 'VCS Repository Id')
    ->param('providerPullRequestId', '', new Text(256), 'GitHub Pull Request Id')
    ->inject('gitHub')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('getProjectDB')
    ->inject('queueForBuilds')
    ->action(function (string $installationId, string $repositoryId, string $providerPullRequestId, GitHub $github, Request $request, Response $response, Document $project, Database $dbForConsole, callable $getProjectDB, Build $queueForBuilds) use ($createGitDeployments) {
        $installation = $dbForConsole->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $repository = Authorization::skip(fn () => $dbForConsole->getDocument('repositories', $repositoryId, [
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

        $repository = Authorization::skip(fn () => $dbForConsole->updateDocument('repositories', $repository->getId(), $repository));

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

        $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerCommitHash, $providerPullRequestId, true, $dbForConsole, $queueForBuilds, $getProjectDB, $request);

        $response->noContent();
    });
