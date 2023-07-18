<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Http\Http;
use Utopia\Database\Document;

App::init()
    ->groups(['console'])
    ->inject('project')
    ->action(function (Document $project) {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    });


App::get('/v1/console/variables')
    ->desc('Get Variables')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'console')
    ->label('sdk.method', 'variables')
    ->label('sdk.description', '/docs/references/console/variables.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_CONSOLE_VARIABLES)
    ->inject('response')
    ->action(function (Response $response) {

        $variables = new Document([
            '_APP_DOMAIN_TARGET' => App::getEnv('_APP_DOMAIN_TARGET'),
            '_APP_STORAGE_LIMIT' => +App::getEnv('_APP_STORAGE_LIMIT'),
            '_APP_FUNCTIONS_SIZE_LIMIT' => +App::getEnv('_APP_FUNCTIONS_SIZE_LIMIT'),
            '_APP_USAGE_STATS' => App::getEnv('_APP_USAGE_STATS'),
        ]);

        $response->dynamic($variables, Response::MODEL_CONSOLE_VARIABLES);
    });
