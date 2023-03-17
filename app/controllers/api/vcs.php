<?php

use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Appwrite\Utopia\Response;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\Database\Helpers\ID;


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
        $github = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'installationId' => $installationId,
            'projectId' => $state,
            'provider' => "GitHub",
            'accessToken' => null
        ]);

        $github = $dbForConsole->createDocument('vcs', $github);

        $response
            ->redirect("/listRepos.html?installationId=$installationId");
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

        $github = new GitHub($installationId, $privateKey, $githubAppId, 'vermakhushboo');
        $repos = $github->listRepositoriesForGitHubApp();
        $response->json($repos);
    });