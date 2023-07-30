<?php

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Swoole\Coroutine as Co;
use Utopia\App;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Host;
use Appwrite\Utopia\Database\Validator\Queries\Installations;
use Appwrite\Vcs\Comment;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
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
use Utopia\Validator\Boolean;

use function Swoole\Coroutine\batch;

$createGitDeployments = function (GitHub $github, string $providerInstallationId, array $repositories, string $providerBranch, string $providerCommitHash, string $providerPullRequestId, bool $external, Database $dbForConsole, callable $getProjectDB, Request $request) {
    foreach ($repositories as $resource) {
        $resourceType = $resource->getAttribute('resourceType');

        if ($resourceType === "function") {
            $projectId = $resource->getAttribute('projectId');
            $project = Authorization::skip(fn () => $dbForConsole->getDocument('projects', $projectId));
            $dbForProject = $getProjectDB($project);

            $functionId = $resource->getAttribute('resourceId');
            $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

            $deploymentId = ID::unique();
            $repositoryId = $resource->getId();
            $repositoryInternalId = $resource->getInternalId();
            $providerRepositoryId = $resource->getAttribute('providerRepositoryId');
            $installationId = $resource->getAttribute('installationId');
            $installationInternalId = $resource->getAttribute('internalInstallationId');
            $productionBranch = $function->getAttribute('providerBranch');
            $activate = false;

            if ($providerBranch == $productionBranch && $external === false) {
                $activate = true;
            }

            $latestCommentId = '';

            if (!empty($providerPullRequestId)) {
                $latestComment = Authorization::skip(fn () => $dbForConsole->findOne('vcsComments', [
                    Query::equal('installationInternalId', [$installationInternalId]),
                    Query::equal('projectInternalId', [$project->getInternalId()]),
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::equal('providerPullRequestId', [$providerPullRequestId]),
                    Query::orderDesc('$createdAt'),
                ]));

                if ($latestComment !== false && !$latestComment->isEmpty()) {
                    $latestCommentId = $latestComment->getAttribute('commentId', '');
                }
            } elseif (!empty($providerBranch)) {
                $latestComment = Authorization::skip(fn () => $dbForConsole->findOne('vcsComments', [
                    Query::equal('installationInternalId', [$installationInternalId]),
                    Query::equal('projectInternalId', [$project->getInternalId()]),
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::equal('providerBranch', [$providerBranch]),
                    Query::orderDesc('$createdAt'),
                ]));

                if ($latestComment !== false && !$latestComment->isEmpty()) {
                    $latestCommentId = $latestComment->getAttribute('commentId', '');
                }
            }

            $owner = $github->getOwnerName($providerInstallationId) ?? '';
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';

            if (empty($repositoryName)) {
                throw new Exception(Exception::REPOSITORY_NOT_FOUND);
            }

            $isAuthorized = !$external;

            if (!$isAuthorized && !empty($providerPullRequestId)) {
                if (\in_array($providerPullRequestId, $resource->getAttribute('providerPullRequestIds', []))) {
                    $isAuthorized = true;
                }
            }

            $commentStatus = $isAuthorized ? 'waiting' : 'failed';

            if (empty($latestCommentId)) {
                $comment = new Comment();
                $comment->addBuild($project, $function, $commentStatus, $deploymentId);

                if (!empty($providerPullRequestId)) {
                    $latestCommentId = \strval($github->createComment($owner, $repositoryName, $providerPullRequestId, $comment->generateComment()));
                } elseif (!empty($providerBranch)) {
                    $gitPullRequest = $github->getBranchPullRequest($owner, $repositoryName, $providerBranch);
                    $providerPullRequestId = \strval($gitPullRequest['number'] ?? '');
                    if (!empty($providerPullRequestId)) {
                        $latestCommentId = \strval($github->createComment($owner, $repositoryName, $providerPullRequestId, $comment->generateComment()));
                    }
                }

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
            } else {
                $comment = new Comment();
                $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                $comment->addBuild($project, $function, $commentStatus, $deploymentId);

                $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
            }

            if (!$isAuthorized) {
                $functionName = $function->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$functionName} ({$projectName})";
                $message = 'Authorization required for external contributor.';
                $providerTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/git/authorize-contributor?projectId={$projectId}&installationId={$installationId}&repositoryId={$repositoryId}&providerPullRequestId={$providerPullRequestId}";

                $providerRepositoryId = $resource->getAttribute('providerRepositoryId');
                $repositoryName = $github->getRepositoryName($providerRepositoryId);
                $owner = $github->getOwnerName($providerInstallationId);
                $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'failure', $message, $providerTargetUrl, $name);
                continue;
            }

            $deployment = $dbForProject->createDocument('deployments', new Document([
                '$id' => $deploymentId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'resourceId' => $functionId,
                'resourceType' => 'functions',
                'entrypoint' => $function->getAttribute('entrypoint'),
                'commands' => $function->getAttribute('commands'),
                'type' => 'vcs',
                'installationId' => $installationId,
                'installationInternalId' => $installationInternalId,
                'providerRepositoryId' => $providerRepositoryId,
                'repositoryId' => $repositoryId,
                'repositoryInternalId' => $repositoryInternalId,
                'providerCommentId' => \strval($latestCommentId),
                'providerBranch' => $providerBranch,
                'search' => implode(' ', [$deploymentId, $function->getAttribute('entrypoint')]),
                'activate' => $activate,
            ]));

            $providerTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/functions/function-$functionId";

            if (!empty($providerCommitHash) && $function->getAttribute('providerSilentMode', false) === false) {
                $functionName = $function->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$functionName} ({$projectName})";
                $message = 'Starting...';

                $providerRepositoryId = $resource->getAttribute('providerRepositoryId');
                $repositoryName = $github->getRepositoryName($providerRepositoryId);
                $owner = $github->getOwnerName($providerInstallationId);
                $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'pending', $message, $providerTargetUrl, $name);
            }

            $contribution = new Document([]);
            if ($external) {
                $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $providerPullRequestId);

                $contribution->setAttribute('ownerName', $pullRequestResponse['head']['repo']['owner']['login']);
                $contribution->setAttribute('repositoryName', $pullRequestResponse['head']['repo']['name']);
            }

            $buildEvent = new Build();
            $buildEvent
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($function)
                ->setProviderContribution($contribution)
                ->setDeployment($deployment)
                ->setProviderTargetUrl($providerTargetUrl)
                ->setProviderCommitHash($providerCommitHash)
                ->setProject($project)
                ->trigger();

            //TODO: Add event?
        }
    }
};

App::get('/v1/vcs/github/installations')
    ->desc('Install GitHub App')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'createGitHubInstallation')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('sdk.hide', true)
    ->param('redirect', '', fn ($clients) => new Host($clients), 'URL to redirect back to your Git authorization. Only console hostnames are allowed.', true, ['clients'])
    ->param('projectId', '', new UID(), 'Project ID')
    ->inject('response')
    ->action(function (string $redirect, string $projectId, Response $response) {
        $state = \json_encode([
            'projectId' => $projectId,
            'redirect' => $redirect
        ]);

        $appName = App::getEnv('_APP_VCS_GITHUB_APP_NAME');
        $url = "https://github.com/apps/$appName/installations/new?" . \http_build_query([
            'state' => $state
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
    ->param('installation_id', '', new Text(256), 'GitHub installation ID', true)
    ->param('setup_action', '', new Text(256), 'GitHub setup actuon type', true)
    ->param('state', '', new Text(2048), 'GitHub state. Contains info sent when starting authorization flow.', true)
    ->param('code', '', new Text(2048), 'OAuth2 code.', true)
    ->inject('gitHub')
    ->inject('user')
    ->inject('project')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $providerInstallationId, string $setupAction, string $state, string $code, GitHub $github, Document $user, Document $project, Request $request, Response $response, Database $dbForConsole) {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Installation requests from organisation members for the Appwrite GitHub App are currently unsupported. To proceed with the installation, login to the Appwrite Console and install the GitHub App.');
        }

        $state = \json_decode($state, true);
        $redirect = $state['redirect'] ?? '';
        $projectId = $state['projectId'] ?? '';

        $project = $dbForConsole->getDocument('projects', $projectId);

        if (empty($redirect)) {
            $redirect = $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/settings/git-installations";
        }

        if ($project->isEmpty()) {
            $response
                ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->addHeader('Pragma', 'no-cache')
                ->redirect($redirect);
            return;
        }

        $personalSlug = '';

        // OAuth Authroization
        if (!empty($code)) {
            $oauth2 = new OAuth2Github(App::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), App::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");
            $accessToken = $oauth2->getAccessToken($code) ?? '';
            $refreshToken = $oauth2->getRefreshToken($code) ?? '';
            $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code) ?? '';
            $personalSlug = $oauth2->getUserSlug($accessToken) ?? '';

            $user = $user
                ->setAttribute('vcsGithubAccessToken', $accessToken)
                ->setAttribute('vcsGithubRefreshToken', $refreshToken)
                ->setAttribute('vcsGithubAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry));

            $dbForConsole->updateDocument('users', $user->getId(), $user);
        }

        // Create / Update installation
        if (!empty($providerInstallationId)) {
            $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
            $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($providerInstallationId) ?? '';

            $projectInternalId = $project->getInternalId();

            $installation = $dbForConsole->findOne('vcsInstallations', [
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

                $installation = $dbForConsole->createDocument('vcsInstallations', $installation);
            } else {
                $installation = $installation
                    ->setAttribute('organization', $owner)
                    ->setAttribute('personal', $personalSlug === $owner);
                $installation = $dbForConsole->updateDocument('vcsInstallations', $installation->getId(), $installation);
            }
        } else {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Installation of the Appwrite GitHub App on organization accounts is restricted to organization owners. As a member of the organization, you do not have the necessary permissions to install this GitHub App. Please contact the organization owner to create the installation from the Appwrite console.');
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirect);
    });

App::post('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/detection')
    ->desc('Detect runtime settings from source code')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'createRepositoryDetection')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DETECTION)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->param('providerRootDirectory', '', new Text(256), 'Path to Root Directory', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $providerRepositoryId, string $providerRootDirectory, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId);
        $repositoryName = $github->getRepositoryName($providerRepositoryId);

        if (empty($repositoryName)) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        $files = $github->listRepositoryContents($owner, $repositoryName, $providerRootDirectory);
        $languages = $github->getRepositoryLanguages($owner, $repositoryName);

        $detectorFactory = new Detector($files, $languages);

        $detectorFactory
            ->addDetector(new JavaScript())
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
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'listRepositories')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_REPOSITORY_LIST)
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

        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

        $page = 1;
        $perPage = 100;

        $loadPage = function ($page) use ($github, $perPage) {
            $repos = $github->listRepositoriesForVCSApp($page, $perPage);
            return $repos;
        };

        $reposPages = batch([
            function () use ($loadPage) {
                return $loadPage(1);
            },
            function () use ($loadPage) {
                return $loadPage(2);
            },
            function () use ($loadPage) {
                return $loadPage(3);
            }
        ]);

        $page += 3;
        $repos = [];
        foreach ($reposPages as $reposPage) {
            $repos = \array_merge($repos, $reposPage);
        }

        // All 3 pages were full, we paginate more
        if (\count($repos) === 3 * $perPage) {
            do {
                $reposPage = $loadPage($page);
                $repos = array_merge($repos, $reposPage);
                $page++;
            } while (\count($reposPage) === $perPage);
        }

        // Filter repositories based on search parameter
        if (!empty($search)) {
            $repos = array_filter($repos, function ($repo) use ($search) {
                return \str_contains(\strtolower($repo['name']), \strtolower($search));
            });
        }
        // Sort repositories by last modified date in descending order
        usort($repos, function ($repo1, $repo2) {
            return \strtotime($repo2['pushed_at']) - \strtotime($repo1['pushed_at']);
        });

        // Limit the maximum results to 5
        $repos = \array_slice($repos, 0, 5);

        $repos = \array_map(function ($repo) use ($installation) {
            $repo['id'] = \strval($repo['id'] ?? '');
            $repo['pushedAt'] = $repo['pushed_at'] ?? null;
            $repo['provider'] = $installation->getAttribute('provider', '') ?? '';
            $repo['organization'] = $installation->getAttribute('organization', '') ?? '';
            return new Document($repo);
        }, $repos);

        $repos = batch(\array_map(function ($repo) use ($github) {
            return function () use ($repo, $github) {
                $files = $github->listRepositoryContents($repo['organization'], $repo['name'], '');
                $languages = $github->getRepositoryLanguages($repo['organization'], $repo['name']);

                $detectorFactory = new Detector($files, $languages);

                $detectorFactory
                    ->addDetector(new JavaScript())
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

                return $repo;
            };
        }, $repos));

        $response->dynamic(new Document([
            'repositories' => $repos,
            'total' => \count($repos),
        ]), Response::MODEL_REPOSITORY_LIST);
    });

App::post('/v1/vcs/github/installations/:installationId/providerRepositories')
    ->desc('Create repository')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'createRepository')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_REPOSITORY)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('name', '', new Text(256), 'Repository name (slug)')
    ->param('private', '', new Boolean(false), 'Mark repository public or private')
    ->inject('gitHub')
    ->inject('user')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $name, bool $private, GitHub $github, Document $user, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('personal', false) === true) {
            $oauth2 = new OAuth2Github(App::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), App::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

            $accessToken = $user->getAttribute('vcsGithubAccessToken');
            $refreshToken = $user->getAttribute('vcsGithubRefreshToken');
            $accessTokenExpiry = $user->getAttribute('vcsGithubAccessTokenExpiry');

            $isExpired = new \DateTime($accessTokenExpiry) < new \DateTime('now');
            if ($isExpired) {
                $oauth2->refreshTokens($refreshToken);

                $accessToken = $oauth2->getAccessToken('');
                $refreshToken = $oauth2->getRefreshToken('');

                $verificationId = $oauth2->getUserID($accessToken);

                if (empty($verificationId)) {
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, "Another request is currently refreshing OAuth token. Please try again.");
                }

                $user = $user
                    ->setAttribute('vcsGithubAccessToken', $accessToken)
                    ->setAttribute('vcsGithubRefreshToken', $refreshToken)
                    ->setAttribute('vcsGithubAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

                $dbForConsole->updateDocument('users', $user->getId(), $user);
            }

            $repository = $oauth2->createRepository($accessToken, $name, $private);
        } else {
            $providerInstallationId = $installation->getAttribute('providerInstallationId');
            $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
            $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($providerInstallationId);

            $repository = $github->createRepository($owner, $name, $private);
        }

        $repository['id'] = \strval($repository['id']) ?? '';
        $repository['pushedAt'] = $repository['pushed_at'] ?? '';
        $repository['organization'] = $installation->getAttribute('organization', '');
        $repository['provider'] = $installation->getAttribute('provider', '');

        $response->dynamic(new Document($repository), Response::MODEL_REPOSITORY);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId')
    ->desc('Get repository')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'getRepository')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_REPOSITORY)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('providerRepositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $providerRepositoryId, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId) ?? '';
        $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';

        if (empty($repositoryName)) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        $repository = $github->getRepository($owner, $repositoryName);

        $repository['id'] = \strval($repository['id']) ?? '';
        $repository['pushedAt'] = $repository['pushed_at'] ?? '';
        $repository['organization'] = $installation->getAttribute('organization', '');
        $repository['provider'] = $installation->getAttribute('provider', '');

        $response->dynamic(new Document($repository), Response::MODEL_REPOSITORY);
    });

App::get('/v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/branches')
    ->desc('List Repository Branches')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
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
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($providerInstallationId) ?? '';
        $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';

        if (empty($repositoryName)) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        $branches = $github->listBranches($owner, $repositoryName) ?? [];

        $response->dynamic(new Document([
            'branches' => \array_map(function ($branch) {
                return ['name' => $branch];
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
    ->action(
        function (GitHub $github, Request $request, Response $response, Database $dbForConsole, callable $getProjectDB) use ($createGitDeployments) {
            $signature = $request->getHeader('x-hub-signature-256', '');
            $payload = $request->getRawPayload();

            $signatureKey = App::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '');

            $valid = $github->validateWebhookEvent($payload, $signature, $signatureKey);
            if (!$valid) {
                throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook signature.");
            }

            $event = $request->getHeader('x-github-event', '');
            $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
            $parsedPayload = $github->getEvent($event, $payload);

            if ($event == $github::EVENT_PUSH) {
                $providerBranch = $parsedPayload["branch"] ?? '';
                $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                $providerInstallationId = $parsedPayload["installationId"] ?? '';
                $providerCommitHash = $parsedPayload["SHA"] ?? '';

                $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

                //find functionId from functions table
                $repositories = $dbForConsole->find('vcsRepositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::limit(100),
                ]);

                $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerCommitHash, '', false, $dbForConsole, $getProjectDB, $request);
            } elseif ($event == $github::EVENT_INSTALLATION) {
                if ($parsedPayload["action"] == "deleted") {
                    // TODO: Use worker for this job instead (update function as well)
                    $providerInstallationId = $parsedPayload["installationId"];

                    $installations = $dbForConsole->find('vcsInstallations', [
                        Query::equal('providerInstallationId', [$providerInstallationId]),
                        Query::limit(1000)
                    ]);

                    foreach ($installations as $installation) {
                        $repositories = $dbForConsole->find('vcsRepositories', [
                            Query::equal('installationInternalId', [$installation->getInternalId()]),
                            Query::limit(1000)
                        ]);

                        foreach ($repositories as $repository) {
                            $dbForConsole->deleteDocument('vcsRepositories', $repository->getId());
                        }

                        $dbForConsole->deleteDocument('vcsInstallations', $installation->getId());
                    }
                }
            } elseif ($event == $github::EVENT_PULL_REQUEST) {
                if ($parsedPayload["action"] == "opened" || $parsedPayload["action"] == "reopened" || $parsedPayload["action"] == "synchronize") {
                    $providerBranch = $parsedPayload["branch"] ?? '';
                    $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                    $providerInstallationId = $parsedPayload["installationId"] ?? '';
                    $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
                    $providerCommitHash = $parsedPayload["SHA"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    // Ignore sync for non-external. We handle it in push webhook
                    if (!$external && $parsedPayload["action"] == "synchronize") {
                        return $response->json($parsedPayload);
                    }

                    $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

                    $repositories = $dbForConsole->find('vcsRepositories', [
                        Query::equal('providerRepositoryId', [$providerRepositoryId]),
                        Query::orderDesc('$createdAt')
                    ]);

                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerCommitHash, $providerPullRequestId, $external, $dbForConsole, $getProjectDB, $request);
                } elseif ($parsedPayload["action"] == "closed") {
                    // Allowed external contributions cleanup

                    $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                    $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    if ($external) {
                        $repositories = $dbForConsole->find('vcsRepositories', [
                            Query::equal('providerRepositoryId', [$providerRepositoryId]),
                            Query::orderDesc('$createdAt')
                        ]);

                        foreach ($repositories as $repository) {
                            $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                            if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                                $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                                $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                                $repository = Authorization::skip(fn () => $dbForConsole->updateDocument('vcsRepositories', $repository->getId(), $repository));
                            }
                        }
                    }
                }
            }

            $response->json($parsedPayload);
        }
    );

App::get('/v1/vcs/installations')
    ->groups(['api', 'vcs'])
    ->desc('List installations')
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'vcs')
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
        $queries = Query::parseQueries($queries);

        $queries[] = Query::equal('projectInternalId', [$project->getInternalId()]);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $installationId = $cursor->getValue();
            $cursorDocument = $dbForConsole->getDocument('vcsInstallations', $installationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Installation '{$installationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForConsole->find('vcsInstallations', $queries);
        $total = $dbForConsole->count('vcsInstallations', $filterQueries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'installations' => $results,
            'total' => $total,
        ]), Response::MODEL_INSTALLATION_LIST);
    });

App::get('/v1/vcs/installations/:installationId')
    ->groups(['api', 'vcs'])
    ->desc('Get installation')
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'vcs')
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
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId);

        if ($installation === false || $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $response->dynamic($installation, Response::MODEL_INSTALLATION);
    });

App::delete('/v1/vcs/installations/:installationId')
    ->groups(['api', 'vcs'])
    ->desc('Delete Installation')
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'deleteInstallation')
    ->label('sdk.description', '/docs/references/vcs/delete-installation.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('deletes')
    ->action(function (string $installationId, Response $response, Document $project, Database $dbForConsole, Delete $deletes) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!$dbForConsole->deleteDocument('vcsInstallations', $installation->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove installation from DB');
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($installation);

        $response->noContent();
    });

App::patch('/v1/vcs/github/installations/:installationId/repositories/:repositoryId')
    ->desc('Authorize external deployment')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
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
    ->action(function (string $installationId, string $repositoryId, string $providerPullRequestId, GitHub $github, Request $request, Response $response, Document $project, Database $dbForConsole, callable $getProjectDB) use ($createGitDeployments) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $repository = $dbForConsole->getDocument('vcsRepositories', $repositoryId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($repository->isEmpty()) {
            throw new Exception(Exception::VCS_REPOSITORY_NOT_FOUND);
        }

        if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
            throw new Exception(Exception::VCS_CONTRIBUTION_ALREADY_AUTHORIZED);
        }

        $providerPullRequestIds = \array_unique(\array_merge($repository->getAttribute('providerPullRequestIds', []), [$providerPullRequestId]));
        $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);

        // TODO: Delete from array when PR is closed

        $repository = $dbForConsole->updateDocument('vcsRepositories', $repository->getId(), $repository);

        $privateKey = App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('_APP_VCS_GITHUB_APP_ID');
        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $github->initialiseVariables($providerInstallationId, $privateKey, $githubAppId);

        $repositories = [$repository];
        $providerRepositoryId = $repository->getAttribute('providerRepositoryId');

        $owner = $github->getOwnerName($providerInstallationId);
        $repositoryName = $github->getRepositoryName($providerRepositoryId);
        $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $providerPullRequestId);

        $providerBranch = \explode(':', $pullRequestResponse['head']['label'])[1] ?? '';
        $providerCommitHash = $pullRequestResponse['head']['sha'] ?? '';

        $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerCommitHash, $providerPullRequestId, true, $dbForConsole, $getProjectDB, $request);

        $response->noContent();
    });
