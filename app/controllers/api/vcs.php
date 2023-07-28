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
    ->inject('user')
    ->inject('dbForConsole')
    ->action(function (string $redirect, string $projectId, Response $response, Document $user, Database $dbForConsole) {
        $state = \json_encode([
            'projectId' => $projectId,
            'redirect' => $redirect
        ]);

        // replace github url state with vcsState in user prefs attribute
        $prefs = $user->getAttribute('prefs', []);
        $prefs['vcsState'] = $state;
        $user->setAttribute('prefs', $prefs);
        $dbForConsole->updateDocument('users', $user->getId(), $user);

        $appName = App::getEnv('VCS_GITHUB_APP_NAME');
        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect("https://github.com/apps/$appName/installations/new");
    });

App::get('/v1/vcs/github/redirect')
    ->desc('Capture installation and authorization from GitHub App')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->param('installation_id', '', new Text(256), 'GitHub installation ID', true)
    ->param('setup_action', '', new Text(256), 'GitHub setup actuon type', true)
    // ->param('state', '', new Text(2048), 'GitHub state. Contains info sent when starting authorization flow.', true)
    ->param('code', '', new Text(2048), 'OAuth2 code.', true)
    ->inject('gitHub')
    ->inject('user')
    ->inject('project')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $setupAction, string $code, GitHub $github, Document $user, Document $project, Request $request, Response $response, Database $dbForConsole) {
        // replace github url state with vcsState in user prefs attribute
        $prefs = $user->getAttribute('prefs', []);
        $state = $prefs['vcsState'] ?? '{}';
        $prefs['vcsState'] = '';
        $user->setAttribute('prefs', $prefs);
        $dbForConsole->updateDocument('users', $user->getId(), $user);

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
            $oauth2 = new OAuth2Github(App::getEnv('VCS_GITHUB_CLIENT_ID', ''), App::getEnv('VCS_GITHUB_CLIENT_SECRET', ''), "");
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
        if (!empty($installationId)) {
            $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
            $github->initialiseVariables($installationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($installationId) ?? '';

            $projectInternalId = $project->getInternalId();

            $vcsInstallation = $dbForConsole->findOne('vcsInstallations', [
                Query::equal('installationId', [$installationId]),
                Query::equal('projectInternalId', [$projectInternalId])
            ]);

            if ($vcsInstallation === false || $vcsInstallation->isEmpty()) {
                $teamId = $project->getAttribute('teamId', '');

                $vcsInstallation = new Document([
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::team(ID::custom($teamId))),
                        Permission::update(Role::team(ID::custom($teamId), 'owner')),
                        Permission::update(Role::team(ID::custom($teamId), 'developer')),
                        Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                        Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                    ],
                    'installationId' => $installationId,
                    'projectId' => $projectId,
                    'projectInternalId' => $projectInternalId,
                    'provider' => 'github',
                    'organization' => $owner,
                    'personal' => $personalSlug === $owner
                ]);

                $vcsInstallation = $dbForConsole->createDocument('vcsInstallations', $vcsInstallation);
            } else {
                $vcsInstallation = $vcsInstallation->setAttribute('organization', $owner);
                $vcsInstallation = $vcsInstallation->setAttribute('personal', $personalSlug === $owner);
                $vcsInstallation = $dbForConsole->updateDocument('vcsInstallations', $vcsInstallation->getId(), $vcsInstallation);
            }
        } else {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Installation of the Appwrite GitHub App on organization accounts is restricted to organization owners. As a member of the organization, you do not have the necessary permissions to install this GitHub App. Please contact the organization owner to create the installation from the Appwrite console.');
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirect);
    });

App::get('/v1/vcs/github/installations/:installationId/repositories/:repositoryId/detection')
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
    ->param('repositoryId', '', new Text(256), 'Repository Id')
    ->param('rootDirectoryPath', '', new Text(256), 'Path to Root Directory', true)
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $vcsInstallationId, string $repositoryId, string $rootDirectoryPath, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installationId = $installation->getAttribute('installationId');
        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        $github->initialiseVariables($installationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($installationId);
        $repositoryName = $github->getRepositoryName($repositoryId);

        if (empty($repositoryName)) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        $files = $github->listRepositoryContents($owner, $repositoryName, $rootDirectoryPath);
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

        $detection = [];
        $detection['runtime'] = $runtime;

        $response->dynamic(new Document($detection), Response::MODEL_DETECTION);
    });

App::get('/v1/vcs/github/installations/:installationId/repositories')
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
    ->action(function (string $vcsInstallationId, string $search, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        if (empty($search)) {
            $search = "";
        }

        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installationId = $installation->getAttribute('installationId');
        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        $github->initialiseVariables($installationId, $privateKey, $githubAppId);

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

        $response->dynamic(new Document([
            'repositories' => $repos,
            'total' => \count($repos),
        ]), Response::MODEL_REPOSITORY_LIST);
    });

App::post('/v1/vcs/github/installations/:installationId/repositories')
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
    ->action(function (string $vcsInstallationId, string $name, bool $private, GitHub $github, Document $user, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if ($installation->getAttribute('personal', false) === true) {
            $oauth2 = new OAuth2Github(App::getEnv('VCS_GITHUB_CLIENT_ID', ''), App::getEnv('VCS_GITHUB_CLIENT_SECRET', ''), "");

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
            $installationId = $installation->getAttribute('installationId');
            $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
            $github->initialiseVariables($installationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($installationId);

            $repository = $github->createRepository($owner, $name, $private);
        }

        $repository['id'] = \strval($repository['id']) ?? '';
        $repository['pushedAt'] = $repository['pushed_at'] ?? '';
        $repository['organization'] = $installation->getAttribute('organization', '');
        $repository['provider'] = $installation->getAttribute('provider', '');

        $response->dynamic(new Document($repository), Response::MODEL_REPOSITORY);
    });

App::get('/v1/vcs/github/installations/:installationId/repositories/:repositoryId')
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
    ->param('repositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $vcsInstallationId, string $repositoryId, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installationId = $installation->getAttribute('installationId');
        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        $github->initialiseVariables($installationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($installationId) ?? '';
        $repositoryName = $github->getRepositoryName($repositoryId) ?? '';

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

App::get('/v1/vcs/github/installations/:installationId/repositories/:repositoryId/branches')
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
    ->param('repositoryId', '', new Text(256), 'Repository Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $vcsInstallationId, string $repositoryId, GitHub $github, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installationId = $installation->getAttribute('installationId');
        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        $github->initialiseVariables($installationId, $privateKey, $githubAppId);

        $owner = $github->getOwnerName($installationId) ?? '';
        $repositoryName = $github->getRepositoryName($repositoryId) ?? '';

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

$createGitDeployments = function (GitHub $github, string $installationId, array $vcsRepos, string $branchName, string $vcsCommitHash, string $pullRequest, bool $external, Database $dbForConsole, callable $getProjectDB, Request $request) {
    foreach ($vcsRepos as $resource) {
        $resourceType = $resource->getAttribute('resourceType');

        if ($resourceType === "function") {
            $projectId = $resource->getAttribute('projectId');
            $project = Authorization::skip(fn () => $dbForConsole->getDocument('projects', $projectId));
            $dbForProject = $getProjectDB($project);

            $functionId = $resource->getAttribute('resourceId');
            $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

            $deploymentId = ID::unique();
            $vcsRepoId = $resource->getId();
            $vcsRepoInternalId = $resource->getInternalId();
            $repositoryId = $resource->getAttribute('repositoryId');
            $vcsInstallationId = $resource->getAttribute('vcsInstallationId');
            $vcsInstallationInternalId = $resource->getAttribute('vcsInstallationInternalId');
            $productionBranch = $function->getAttribute('vcsBranch');
            $activate = false;

            if ($branchName == $productionBranch && $external === false) {
                $activate = true;
            }

            $latestCommentId = '';

            if (!empty($pullRequest)) {
                $latestComment = Authorization::skip(fn () => $dbForConsole->findOne('vcsComments', [
                    Query::equal('vcsInstallationInternalId', [$vcsInstallationInternalId]),
                    Query::equal('projectInternalId', [$project->getInternalId()]),
                    Query::equal('repositoryId', [$repositoryId]),
                    Query::equal('pullRequestId', [$pullRequest]),
                    Query::orderDesc('$createdAt'),
                ]));

                if ($latestComment !== false && !$latestComment->isEmpty()) {
                    $latestCommentId = $latestComment->getAttribute('commentId', '');
                }
            } elseif (!empty($branchName)) {
                $latestComment = Authorization::skip(fn () => $dbForConsole->findOne('vcsComments', [
                    Query::equal('vcsInstallationInternalId', [$vcsInstallationInternalId]),
                    Query::equal('projectInternalId', [$project->getInternalId()]),
                    Query::equal('repositoryId', [$repositoryId]),
                    Query::equal('branch', [$branchName]),
                    Query::orderDesc('$createdAt'),
                ]));

                if ($latestComment !== false && !$latestComment->isEmpty()) {
                    $latestCommentId = $latestComment->getAttribute('commentId', '');
                }
            }

            $owner = $github->getOwnerName($installationId) ?? '';
            $repositoryName = $github->getRepositoryName($repositoryId) ?? '';

            if (empty($repositoryName)) {
                throw new Exception(Exception::REPOSITORY_NOT_FOUND);
            }

            $isAuthorized = !$external;

            if (!$isAuthorized && !empty($pullRequest)) {
                if (\in_array($pullRequest, $resource->getAttribute('pullRequests', []))) {
                    $isAuthorized = true;
                }
            }

            $commentStatus = $isAuthorized ? 'waiting' : 'failed';

            if (empty($latestCommentId)) {
                $comment = new Comment();
                $comment->addBuild($project, $function, $commentStatus, $deploymentId);

                if (!empty($pullRequest)) {
                    $latestCommentId = \strval($github->createComment($owner, $repositoryName, $pullRequest, $comment->generateComment()));
                } elseif (!empty($branchName)) {
                    $gitPullRequest = $github->getBranchPullRequest($owner, $repositoryName, $branchName);
                    $pullRequest = \strval($gitPullRequest['number'] ?? '');
                    if (!empty($pullRequest)) {
                        $latestCommentId = \strval($github->createComment($owner, $repositoryName, $pullRequest, $comment->generateComment()));
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
                        'vcsInstallationInternalId' => $vcsInstallationInternalId,
                        'vcsInstallationId' => $vcsInstallationId,
                        'projectInternalId' => $project->getInternalId(),
                        'projectId' => $project->getId(),
                        'repositoryId' => $repositoryId,
                        'branch' => $branchName,
                        'pullRequestId' => $pullRequest,
                        'commentId' => $latestCommentId
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
                $vcsTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/git/authorize-contributor?projectId={$projectId}&installationId={$vcsInstallationId}&vcsRepositoryId={$vcsRepoId}&pullRequest={$pullRequest}";

                $repositoryId = $resource->getAttribute('repositoryId');
                $repositoryName = $github->getRepositoryName($repositoryId);
                $owner = $github->getOwnerName($installationId);
                $github->updateCommitStatus($repositoryName, $vcsCommitHash, $owner, 'failure', $message, $vcsTargetUrl, $name);
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
                'vcsInstallationId' => $vcsInstallationId,
                'vcsInstallationInternalId' => $vcsInstallationInternalId,
                'vcsRepositoryId' => $repositoryId,
                'vcsRepositoryDocId' => $vcsRepoId,
                'vcsRepositoryDocInternalId' => $vcsRepoInternalId,
                'vcsCommentId' => \strval($latestCommentId),
                'vcsBranch' => $branchName,
                'search' => implode(' ', [$deploymentId, $function->getAttribute('entrypoint')]),
                'activate' => $activate,
            ]));

            $vcsTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/functions/function-$functionId";

            if (!empty($vcsCommitHash) && $function->getAttribute('vcsSilentMode', false) === false) {
                $functionName = $function->getAttribute('name');
                $projectName = $project->getAttribute('name');
                $name = "{$functionName} ({$projectName})";
                $message = 'Starting...';

                $repositoryId = $resource->getAttribute('repositoryId');
                $repositoryName = $github->getRepositoryName($repositoryId);
                $owner = $github->getOwnerName($installationId);
                $github->updateCommitStatus($repositoryName, $vcsCommitHash, $owner, 'pending', $message, $vcsTargetUrl, $name);
            }

            $contribution = new Document([]);
            if ($external) {
                $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $pullRequest);

                $contribution->setAttribute('ownerName', $pullRequestResponse['head']['repo']['owner']['login']);
                $contribution->setAttribute('repositoryName', $pullRequestResponse['head']['repo']['name']);
            }

            $buildEvent = new Build();
            $buildEvent
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($function)
                ->setVcsContribution($contribution)
                ->setDeployment($deployment)
                ->setVcsTargetUrl($vcsTargetUrl)
                ->setVcsCommitHash($vcsCommitHash)
                ->setProject($project)
                ->trigger();

            //TODO: Add event?
        }
    }
};

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

            $signatureKey = App::getEnv('VCS_GITHUB_WEBHOOK_SECRET', '');

            $valid = $github->validateWebhookEvent($payload, $signature, $signatureKey);
            if (!$valid) {
                throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook signature.");
            }

            $event = $request->getHeader('x-github-event', '');
            $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
            $parsedPayload = $github->getEvent($event, $payload);

            if ($event == $github::EVENT_PUSH) {
                $branchName = $parsedPayload["branch"] ?? '';
                $repositoryId = $parsedPayload["repositoryId"] ?? '';
                $installationId = $parsedPayload["installationId"] ?? '';
                $vcsCommitHash = $parsedPayload["SHA"] ?? '';

                $github->initialiseVariables($installationId, $privateKey, $githubAppId);

                //find functionId from functions table
                $vcsRepos = $dbForConsole->find('vcsRepos', [
                    Query::equal('repositoryId', [$repositoryId]),
                    Query::limit(100),
                ]);

                $createGitDeployments($github, $installationId, $vcsRepos, $branchName, $vcsCommitHash, '', false, $dbForConsole, $getProjectDB, $request);
            } elseif ($event == $github::EVENT_INSTALLATION) {
                if ($parsedPayload["action"] == "deleted") {
                    // TODO: Use worker for this job instead (update function as well)
                    $installationId = $parsedPayload["installationId"];

                    $vcsInstallations = $dbForConsole->find('vcsInstallations', [
                        Query::equal('installationId', [$installationId]),
                        Query::limit(1000)
                    ]);

                    foreach ($vcsInstallations as $installation) {
                        $vcsRepos = $dbForConsole->find('vcsRepos', [
                            Query::equal('vcsInstallationInternalId', [$installation->getInternalId()]),
                            Query::limit(1000)
                        ]);

                        foreach ($vcsRepos as $repo) {
                            $dbForConsole->deleteDocument('vcsRepos', $repo->getId());
                        }

                        $dbForConsole->deleteDocument('vcsInstallations', $installation->getId());
                    }
                }
            } elseif ($event == $github::EVENT_PULL_REQUEST) {
                if ($parsedPayload["action"] == "opened" || $parsedPayload["action"] == "reopened" || $parsedPayload["action"] == "synchronize") {
                    $branchName = $parsedPayload["branch"] ?? '';
                    $repositoryId = $parsedPayload["repositoryId"] ?? '';
                    $installationId = $parsedPayload["installationId"] ?? '';
                    $pullRequestNumber = $parsedPayload["pullRequestNumber"] ?? '';
                    $vcsCommitHash = $parsedPayload["SHA"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    // Ignore sync for non-external. We handle it in push webhook
                    if (!$external && $parsedPayload["action"] == "synchronize") {
                        return $response->json($parsedPayload);
                    }

                    $github->initialiseVariables($installationId, $privateKey, $githubAppId);

                    $vcsRepos = $dbForConsole->find('vcsRepos', [
                        Query::equal('repositoryId', [$repositoryId]),
                        Query::orderDesc('$createdAt')
                    ]);

                    $createGitDeployments($github, $installationId, $vcsRepos, $branchName, $vcsCommitHash, $pullRequestNumber, $external, $dbForConsole, $getProjectDB, $request);
                } elseif ($parsedPayload["action"] == "closed") {
                    // Allowed external contributions cleanup

                    $repositoryId = $parsedPayload["repositoryId"] ?? '';
                    $pullRequestNumber = $parsedPayload["pullRequestNumber"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    if ($external) {
                        $vcsRepos = $dbForConsole->find('vcsRepos', [
                            Query::equal('repositoryId', [$repositoryId]),
                            Query::orderDesc('$createdAt')
                        ]);

                        foreach ($vcsRepos as $vcsRepository) {
                            $pullRequests = $vcsRepository->getAttribute('pullRequests', []);

                            if (\in_array($pullRequestNumber, $pullRequests)) {
                                $pullRequests = \array_diff($pullRequests, [$pullRequestNumber]);
                                $vcsRepository = $vcsRepository->setAttribute('pullRequests', $pullRequests);

                                $vcsRepository = Authorization::skip(fn () => $dbForConsole->updateDocument('vcsRepos', $vcsRepository->getId(), $vcsRepository));
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
            $vcsInstallationId = $cursor->getValue();
            $cursorDocument = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Installation '{$vcsInstallationId}' for the 'cursor' value not found.");
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
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $installationId, Response $response, Document $project, Database $dbForProject, Database $dbForConsole) {
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
    ->action(function (string $vcsInstallationId, Response $response, Document $project, Database $dbForConsole, Delete $deletes) {

        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
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

App::patch('/v1/vcs/github/installations/:installationId/vcsRepositories/:vcsRepositoryId')
    ->desc('Authorize external deployment')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'updateExternalDeployments')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('vcsRepositoryId', '', new Text(256), 'VCS Repository Id')
    ->param('pullRequest', '', new Text(256), 'GitHub Pull Request Id')
    ->inject('gitHub')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('getProjectDB')
    ->action(function (string $vcsInstallationId, string $vcsRepositoryId, string $pullRequest, GitHub $github, Request $request, Response $response, Document $project, Database $dbForConsole, callable $getProjectDB) use ($createGitDeployments) {
        $installation = $dbForConsole->getDocument('vcsInstallations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $vcsRepository = $dbForConsole->getDocument('vcsRepos', $vcsRepositoryId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($vcsRepository->isEmpty()) {
            throw new Exception(Exception::VCS_REPOSITORY_NOT_FOUND);
        }

        if (\in_array($pullRequest, $vcsRepository->getAttribute('pullRequests', []))) {
            throw new Exception(Exception::VCS_CONTRIBUTION_ALREADY_AUTHORIZED);
        }

        $pullRequests = \array_unique(\array_merge($vcsRepository->getAttribute('pullRequests', []), [$pullRequest]));
        $vcsRepository = $vcsRepository->setAttribute('pullRequests', $pullRequests);

        // TODO: Delete from array when PR is closed

        $vcsRepository = $dbForConsole->updateDocument('vcsRepos', $vcsRepository->getId(), $vcsRepository);

        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        $installationId = $installation->getAttribute('installationId');
        $github->initialiseVariables($installationId, $privateKey, $githubAppId);

        $vcsRepos = [$vcsRepository];
        $repositoryId = $vcsRepository->getAttribute('repositoryId');

        $owner = $github->getOwnerName($installationId);
        $repositoryName = $github->getRepositoryName($repositoryId);
        $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $pullRequest);

        $branchName = \explode(':', $pullRequestResponse['head']['label'])[1] ?? '';
        $vcsCommitHash = $pullRequestResponse['head']['sha'] ?? '';

        $createGitDeployments($github, $installationId, $vcsRepos, $branchName, $vcsCommitHash, $pullRequest, true, $dbForConsole, $getProjectDB, $request);

        $response->noContent();
    });
