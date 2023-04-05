<?php

use Utopia\App;
use Appwrite\Event\Build;
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
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\Database\Query;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;

App::get('/v1/vcs/github/install')
    ->desc('Install GitHub App')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'vcs')
    ->label('sdk.method', 'install')
    ->label('sdk.description', '')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->inject('response')
    ->inject('project')
    ->action(function (Response $response, Document $project) {
        $projectId = $project->getId();
        //TODO: Update the name of the GitHub App
        $response->redirect("https://github.com/apps/demoappkh/installations/new?state=$projectId");
    });

App::get('/v1/vcs/github/setup')
    ->desc('Capture installation id and state after GitHub App Installation')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->param('installation_id', '', new Text(256), 'installation_id')
    ->param('setup_action', '', new Text(256), 'setup_action')
    ->param('state', '', new Text(256), 'state')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $installationId, string $setup_action, string $state, Response $response, Database $dbForConsole) {
        //TODO: Fix the flow for updating GitHub installation

        var_dump("hello1");
        $project = $dbForConsole->getDocument('projects', $state);

        var_dump($project);

        var_dump($state);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $projectInternalId = $project->getInternalId();

        $github = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'installationId' => $installationId,
            'projectId' => $state,
            'projectInternalId' => $projectInternalId,
            'provider' => "GitHub",
            'accessToken' => null
        ]);

        $github = $dbForConsole->createDocument('vcs', $github);

        var_dump("hello");

        $response
            ->redirect("http://localhost:3000/console/project-$state/settings");
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
    ->label('sdk.response.model', Response::MODEL_FUNCTION_LIST)
    ->param('installationId', '', new Text(256), 'GitHub App Installation ID')
    ->inject('response')
    ->action(function (string $installationId, Response $response) {
        $privateKey = App::getEnv('_APP_GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('_APP_GITHUB_APP_ID');
        //TODO: Update GitHub Username
        $github = new GitHub();
        $github->initialiseVariables($installationId, $privateKey, $githubAppId, 'vermakhushboo');
        $repos = $github->listRepositoriesForGitHubApp();
        $response->json($repos);
    });

App::post('/v1/vcs/github/incoming')
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
            //switch case on different types of incoming requests

            $cache = new Cache(new Redis($cache));

            // parse the webhook payload
            $event = $request->getHeader('x-github-event', '');
            $payload = $request->getRawPayload();

            $github = new GitHub();

            if ($event == "push") { //TODO: Change events to constants
                $parsedPayload = $github->parseWebhookEventPayload($event, $payload);
                $parsedPayload = json_decode($parsedPayload, true);

                if ($parsedPayload["branch"] == "main") {
                    // in case of push events on main branch, map repo id to function id and trigger a deployment
                    $repositoryId = $parsedPayload["repositoryId"];
                    $installationId = $parsedPayload["installationId"];

                    // find function id from functions table
                    $resources = $dbForConsole->find('vcs_map', [
                        Query::equal('repositoryId', [$repositoryId]),
                        Query::limit(100),
                    ]);

                    foreach ($resources as $resource) {
                        $resourceType = $resource->getAttribute('resourceType');

                        if ($resourceType == "function") {
                            // start a new deployment
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
                            $privateKey = App::getEnv('_APP_GITHUB_PRIVATE_KEY');
                            $githubAppId = App::getEnv('_APP_GITHUB_APP_ID');
                            $github->initialiseVariables($installationId, $privateKey, $githubAppId, 'vermakhushboo');
                            $code = $github->generateGitCloneCommand($repositoryId, $parsedPayload["branch"]);

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
                                'path' => $code,
                                'search' => implode(' ', [$deploymentId, $entrypoint]),
                                'activate' => true,
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
            } elseif ($event == "pull_request") {
            }
            $response->json($parsedPayload);
        }
    );
