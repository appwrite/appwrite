<?php

use Utopia\App;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\Database\Helpers\ID;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Installations;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\Database\Query;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;

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
    ->inject('response')
    ->inject('project')
    ->action(function (Response $response, Document $project) {
        $projectId = $project->getId();

        $appName = App::getEnv('VCS_GITHUB_NAME');
        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect("https://github.com/apps/$appName/installations/new?state=$projectId");
    });

App::get('/v1/vcs/github/incominginstallation')
    ->desc('Capture installation id and state after GitHub App Installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->param('installation_id', '', new Text(256), 'installation_id')
    ->param('setup_action', '', new Text(256), 'setup_action')
    ->param('state', '', new Text(256), 'state')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $setupAction, string $state, Request $request, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $state);

        \var_dump($project);

        if ($project->isEmpty()) {
            $url = $request->getProtocol() . '://' . $request->getHostname() . "/";
            $response->redirect($url);
        }

        $projectInternalId = $project->getInternalId();

        $vcsInstallation = $dbForConsole->findOne('vcs_installations', [
            Query::equal('installationId', [$installationId]),
            Query::equal('projectInternalId', [$projectInternalId])
        ]);

        if (!$vcsInstallation) {
            $vcsInstallation = new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'installationId' => $installationId,
                'projectId' => $state,
                'projectInternalId' => $projectInternalId,
                'provider' => 'GitHub',
                'organization' => '(todo) My Awesome Organization',
                'accessToken' => null
            ]);

            $vcsInstallation = $dbForConsole->createDocument('vcs_installations', $vcsInstallation);
        } else {
            $vcsInstallation = $vcsInstallation->setAttribute('organization', '(todo) My Awesome Organization');
            $vcsInstallation = $dbForConsole->updateDocument('vcs_installations', $vcsInstallation->getId(), $vcsInstallation);
        }

        $url = $request->getProtocol() . '://' . $request->getHostname() . ":3000/console/project-$state/settings/git-installations";

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    });

App::get('v1/vcs/github/installations/:installationId/repositories')
    ->desc('List repositories')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'listRepositories')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_REPOSITORY_LIST)
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $vcsInstallationId, Response $response, Document $project, Database $dbForConsole) {
        $installation = $dbForConsole->getDocument('vcs_installations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $installationId = $installation->getAttribute('installationId');

        $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
        $githubAppName = App::getEnv('VCS_GITHUB_NAME');
        $github = new GitHub();
        $github->initialiseVariables($installationId, $privateKey, $githubAppId, $githubAppName);
        $repos = $github->listRepositoriesForGitHubApp();

        $response->dynamic(new Document([
            'repositories' => $repos,
            'total' => \count($repos),
        ]), Response::MODEL_REPOSITORY_LIST);
    });

App::post('/v1/vcs/github/incomingwebhook')
    ->desc('Captures GitHub Webhook Events')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('cache')
    ->inject('db')
    ->action(
        function (Request $request, Response $response, Database $dbForConsole, mixed $cache, mixed $db) {
            $cache = new Cache(new Redis($cache));
            $event = $request->getHeader('x-github-event', '');
            $payload = $request->getRawPayload();
            $github = new GitHub();
            $privateKey = App::getEnv('VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = App::getEnv('VCS_GITHUB_APP_ID');
            $githubAppName = App::getEnv('VCS_GITHUB_NAME');
            $parsedPayload = $github->parseWebhookEventPayload($event, $payload);
            $parsedPayload = json_decode($parsedPayload, true);

            if ($event == $github::EVENT_PUSH) {
                $branchName = $parsedPayload["branch"];
                $repositoryId = $parsedPayload["repositoryId"];
                $installationId = $parsedPayload["installationId"];

                //find functionId from functions table
                $resources = $dbForConsole->find('vcs_repos', [
                    Query::equal('repositoryId', [$repositoryId]),
                    Query::limit(100),
                ]);

                foreach ($resources as $resource) {
                    $resourceType = $resource->getAttribute('resourceType');

                    if ($resourceType == "function") {
                        // TODO: For cloud, we might have different $db
                        $dbForProject = new Database(new MariaDB($db), $cache);
                        $dbForProject->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
                        $dbForProject->setNamespace("_{$resource->getAttribute('projectInternalId')}");

                        $functionId = $resource->getAttribute('resourceId');
                        //TODO: Why is Authorization::skip needed?
                        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));
                        $projectId = $resource->getAttribute('projectId');
                        //TODO: Why is Authorization::skip needed?
                        $project = Authorization::skip(fn () => $dbForConsole->getDocument('projects', $projectId));
                        $deploymentId = ID::unique();
                        $entrypoint = 'index.js'; //TODO: Read from function settings
                        $vcsRepoId = $resource->getId();
                        $vcsInstallationId = $resource->getAttribute('vcsInstallationId');
                        $activate = false;

                        if ($branchName == "main") {
                            $activate = true;
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
                            'entrypoint' => $entrypoint,
                            'type' => "vcs",
                            'vcsInstallationId' => $vcsInstallationId,
                            'vcsRepoId' => $vcsRepoId,
                            'branch' => $branchName,
                            'search' => implode(' ', [$deploymentId, $entrypoint]),
                            'activate' => $activate,
                        ]));

                        $buildEvent = new Build();
                        $buildEvent
                            ->setType(BUILD_TYPE_DEPLOYMENT)
                            ->setResource($function)
                            ->setDeployment($deployment)
                            ->setProject($project)
                            ->trigger();

                        //TODO: Add event?
                    }
                }
            } else if ($event == $github::EVENT_INSTALLATION) {
                if ($parsedPayload["action"] == "deleted") {
                    // TODO: Use worker for this job instead
                    $installationId = $parsedPayload["installationId"];

                    $vcsInstallations = $dbForConsole->find('vcs_installations', [
                        Query::equal('installationId', [$installationId]),
                        Query::limit(1000)
                    ]);

                    foreach ($vcsInstallations as $installation) {
                        $vcsRepos = $dbForConsole->find('vcs_repos', [
                            Query::equal('vcsInstallationId', [$installation->getId()]),
                            Query::limit(1000)
                        ]);

                        foreach ($vcsRepos as $repo) {
                            $dbForConsole->deleteDocument('vcs_repos', $repo->getId());
                        }

                        $dbForConsole->deleteDocument('vcs_installations', $installation->getId());
                    }
                }
            } else if ($event == $github::EVENT_PULL_REQUEST) {
                if ($parsedPayload["action"] == "opened" or $parsedPayload["action"] == "reopened") {
                    $startNewDeployment = false;
                    $branchName = $parsedPayload["branch"];
                    $repositoryId = $parsedPayload["repositoryId"];
                    $installationId = $parsedPayload["installationId"];
                    $pullRequestNumber = $parsedPayload["pullRequestNumber"];
                    $repositoryName = $parsedPayload["repositoryName"];
                    $github->initialiseVariables($installationId, $privateKey, $githubAppId, $githubAppName);

                    $vcsRepos = $dbForConsole->find('vcs_repos', [
                        Query::equal('repositoryId', [$repositoryId]),
                        Query::orderDesc('$createdAt')
                    ]);

                    $dbForProject = new Database(new MariaDB($db), $cache);
                    $dbForProject->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

                    if ($vcsRepos) {
                        $dbForProject->setNamespace("_{$vcsRepos[0]->getAttribute('projectInternalId')}");
                        $vcsRepoId = $vcsRepos[0]->getId();
                        $deployment = Authorization::skip(fn () => $dbForProject->find('deployments', [
                            Query::equal('vcsRepoId', [$vcsRepoId]),
                            Query::equal('branch', [$branchName]),
                            Query::orderDesc('$createdAt')
                        ]));

                        if ($deployment) {
                            $buildId = $deployment[0]->getAttribute('buildId');
                            $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $buildId));
                            $buildStatus = $build->getAttribute('status');
                            $comment = "| Build Status |\r\n | --------------- |\r\n | $buildStatus |";
                            $commentId = $github->addComment($repositoryName, $pullRequestNumber, $comment);
                        } else {
                            $startNewDeployment = true;
                        }
                    } else {
                        $startNewDeployment = true;
                    }
                    if ($startNewDeployment) {
                        $commentId = strval($github->addComment($repositoryName, $pullRequestNumber, "Build is not deployed yet 🚀"));

                        foreach ($vcsRepos as $resource) {
                            $resourceType = $resource->getAttribute('resourceType');

                            if ($resourceType == "function") {
                                // TODO: For cloud, we might have different $db
                                $dbForProject->setNamespace("_{$resource->getAttribute('projectInternalId')}");

                                $functionId = $resource->getAttribute('resourceId');
                                //TODO: Why is Authorization::skip needed?
                                $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));
                                $projectId = $resource->getAttribute('projectId');
                                //TODO: Why is Authorization::skip needed?
                                $project = Authorization::skip(fn () => $dbForConsole->getDocument('projects', $projectId));
                                $deploymentId = ID::unique();
                                $entrypoint = 'index.js'; //TODO: Read from function settings
                                $vcsRepoId = $resource->getId();
                                $vcsInstallationId = $resource->getAttribute('vcsInstallationId');
                                $activate = false;

                                if ($branchName == "main") {
                                    $activate = true;
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
                                    'entrypoint' => $entrypoint,
                                    'type' => "vcs",
                                    'vcsInstallationId' => $vcsInstallationId,
                                    'vcsRepoId' => $vcsRepoId,
                                    'branch' => $branchName,
                                    'vcsCommentId' => $commentId,
                                    'search' => implode(' ', [$deploymentId, $entrypoint]),
                                    'activate' => $activate,
                                ]));

                                $buildEvent = new Build();
                                $buildEvent
                                    ->setType(BUILD_TYPE_DEPLOYMENT)
                                    ->setResource($function)
                                    ->setDeployment($deployment)
                                    ->setProject($project)
                                    ->trigger();

                                //TODO: Add event?
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
    ->inject('dbForConsole')
    ->action(function (array $queries, string $search, Response $response, Document $project, Database $dbForConsole) {

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
            $cursorDocument = $dbForConsole->getDocument('vcs_installations', $vcsInstallationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Installation '{$vcsInstallationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'installations' => $dbForConsole->find('vcs_installations', $queries),
            'total' => $dbForConsole->count('vcs_installations', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_INSTALLATION_LIST);
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

        $installation = $dbForConsole->getDocument('vcs_installations', $vcsInstallationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!$dbForConsole->deleteDocument('vcs_installations', $installation->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove installation from DB');
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($installation);

        $response->noContent();
    });