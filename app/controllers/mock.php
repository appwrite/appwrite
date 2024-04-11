<?php

global $utopia, $request, $response;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\UID;
use Utopia\System\System;
use Utopia\Validator\Host;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

App::get('/v1/mock/tests/general/oauth2')
    ->desc('OAuth Login')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('sdk.mock', true)
    ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
    ->param('redirect_uri', '', new Host(['localhost']), 'OAuth2 Redirect URI.') // Important to deny an open redirect attack
    ->param('scope', '', new Text(100), 'OAuth2 scope list.')
    ->param('state', '', new Text(1024), 'OAuth2 state.')
    ->inject('response')
    ->action(function (string $client_id, string $redirectURI, string $scope, string $state, Response $response) {

        $response->redirect($redirectURI . '?' . \http_build_query(['code' => 'abcdef', 'state' => $state]));
    });

App::get('/v1/mock/tests/general/oauth2/token')
    ->desc('OAuth2 Token')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('sdk.mock', true)
    ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
    ->param('client_secret', '', new Text(100), 'OAuth2 scope list.')
    ->param('grant_type', 'authorization_code', new WhiteList(['refresh_token', 'authorization_code']), 'OAuth2 Grant Type.', true)
    ->param('redirect_uri', '', new Host(['localhost']), 'OAuth2 Redirect URI.', true)
    ->param('code', '', new Text(100), 'OAuth2 state.', true)
    ->param('refresh_token', '', new Text(100), 'OAuth2 refresh token.', true)
    ->inject('response')
    ->action(function (string $client_id, string $client_secret, string $grantType, string $redirectURI, string $code, string $refreshToken, Response $response) {

        if ($client_id != '1') {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid client ID');
        }

        if ($client_secret != '123456') {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid client secret');
        }

        $responseJson = [
            'access_token' => '123456',
            'refresh_token' => 'tuvwxyz',
            'expires_in' => 14400
        ];

        if ($grantType === 'authorization_code') {
            if ($code !== 'abcdef') {
                throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
            }

            $response->json($responseJson);
        } elseif ($grantType === 'refresh_token') {
            if ($refreshToken !== 'tuvwxyz') {
                throw new Exception(Exception::GENERAL_MOCK, 'Invalid refresh token');
            }

            $response->json($responseJson);
        } else {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid grant type');
        }
    });

App::get('/v1/mock/tests/general/oauth2/user')
    ->desc('OAuth2 User')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('token', '', new Text(100), 'OAuth2 Access Token.')
    ->inject('response')
    ->action(function (string $token, Response $response) {

        if ($token != '123456') {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
        }

        $response->json([
            'id' => 1,
            'name' => 'User Name',
            'email' => 'useroauth@localhost.test',
        ]);
    });

App::get('/v1/mock/tests/general/oauth2/success')
    ->desc('OAuth2 Success')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function (Response $response) {

        $response->json([
            'result' => 'success',
        ]);
    });

App::get('/v1/mock/tests/general/oauth2/failure')
    ->desc('OAuth2 Failure')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function (Response $response) {

        $response
            ->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)
            ->json([
                'result' => 'failure',
            ]);
    });

App::patch('/v1/mock/functions-v2')
    ->desc('Update Function Version to V2 (outdated code syntax)')
    ->groups(['mock', 'api', 'functions'])
    ->label('scope', 'functions.write')
    ->label('docs', false)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, Response $response, Database $dbForProject) {
        $isDevelopment = System::getEnv('_APP_ENV', 'development') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('version', 'v2'));

        $response->noContent();
    });

App::get('/v1/mock/github/callback')
    ->desc('Create installation document using GitHub installation id')
    ->groups(['mock', 'api', 'vcs'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('providerInstallationId', '', new UID(), 'GitHub installation ID')
    ->param('projectId', '', new UID(), 'Project ID of the project where app is to be installed')
    ->inject('gitHub')
    ->inject('project')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $providerInstallationId, string $projectId, GitHub $github, Document $project, Response $response, Database $dbForConsole) {
        $isDevelopment = System::getEnv('_APP_ENV', 'development') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';
            throw new Exception(Exception::PROJECT_NOT_FOUND, $error);
        }

        if (!empty($providerInstallationId)) {
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
            $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
            $owner = $github->getOwnerName($providerInstallationId) ?? '';

            $projectInternalId = $project->getInternalId();

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
                'personal' => false
            ]);

            $installation = $dbForConsole->createDocument('installations', $installation);
        }

        $response->json([
            'installationId' => $installation->getId(),
        ]);
    });

App::shutdown()
    ->groups(['mock'])
    ->inject('utopia')
    ->inject('response')
    ->inject('request')
    ->action(function (App $utopia, Response $response, Request $request) {

        $result = [];
        $route  = $utopia->getRoute();
        $path   = APP_STORAGE_CACHE . '/tests.json';
        $tests  = (\file_exists($path)) ? \json_decode(\file_get_contents($path), true) : [];

        if (!\is_array($tests)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Failed to read results', 500);
        }

        $result[$route->getMethod() . ':' . $route->getPath()] = true;

        $tests = \array_merge($tests, $result);

        if (!\file_put_contents($path, \json_encode($tests), LOCK_EX)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Failed to save results', 500);
        }

        $response->dynamic(new Document(['result' => $route->getMethod() . ':' . $route->getPath() . ':passed']), Response::MODEL_MOCK);
    });
