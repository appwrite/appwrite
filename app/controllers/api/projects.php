<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Validator\MockNumber;
use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Keys;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

Http::init()
    ->groups(['projects'])
    ->inject('project')
    ->action(function (Document $project) {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    });

Http::get('/v1/projects/:projectId')
    ->desc('Get project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'get',
        description: '/docs/references/projects/get.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/service/all')
    ->desc('Update all service status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->action(function () {
        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED, 'Bulk API no longer exists for services. Please change status individually.');
    });

Http::patch('/v1/projects/:projectId/api/all')
    ->desc('Update all API status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->action(function () {
        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED, 'Bulk API no longer exists for services. Please change status individually.');
    });

Http::patch('/v1/projects/:projectId/oauth2')
    ->desc('Update project OAuth2')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateOAuth2',
        description: '/docs/references/projects/update-oauth2.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'Provider Name')
    ->param('appId', null, new Nullable(new Text(256)), 'Provider app ID. Max length: 256 chars.', true)
    ->param('secret', null, new Nullable(new text(512)), 'Provider secret key. Max length: 512 chars.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Provider status. Set to \'false\' to disable new session creation.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $provider, ?string $appId, ?string $secret, ?bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $providers = $project->getAttribute('oAuthProviders', []);

        if ($appId !== null) {
            $providers[$provider . 'Appid'] = $appId;
        }

        if ($secret !== null) {
            $providers[$provider . 'Secret'] = $secret;
        }

        if ($enabled !== null) {
            $providers[$provider . 'Enabled'] = $enabled;
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('oAuthProviders', $providers));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/:method')
    ->desc('Update project auth method status. Use this endpoint to enable or disable a given auth method for this project.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthStatus',
        description: '/docs/references/projects/update-auth-status.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('method', '', new WhiteList(\array_keys(Config::getParam('auth')), true), 'Auth Method. Possible values: ' . implode(',', \array_keys(Config::getParam('auth'))), false)
    ->param('status', false, new Boolean(true), 'Set the status of this auth method.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $method, bool $status, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);
        $auth = Config::getParam('auth')[$method] ?? [];
        $authKey = $auth['key'] ?? '';

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths[$authKey] = $status;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/mock-numbers')
    ->desc('Update the mock numbers for the project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateMockNumbers',
        description: '/docs/references/projects/update-mock-numbers.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('numbers', '', new ArrayList(new MockNumber(), 10), 'An array of mock numbers and their corresponding verification codes (OTPs). Each number should be a valid E.164 formatted phone number. Maximum of 10 numbers are allowed.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, array $numbers, Response $response, Database $dbForPlatform) {

        $uniqueNumbers = [];
        foreach ($numbers as $number) {
            if (isset($uniqueNumbers[$number['phone']])) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Duplicate phone numbers are not allowed.');
            }
            $uniqueNumbers[$number['phone']] = $number['otp'];
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);

        $auths['mockNumbers'] = $numbers;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::delete('/v1/projects/:projectId')
    ->desc('Delete project')
    ->groups(['api', 'projects'])
    ->label('audits.event', 'projects.delete')
    ->label('audits.resource', 'project/{request.projectId}')
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'delete',
        description: '/docs/references/projects/delete.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->inject('response')
    ->inject('user')
    ->inject('dbForPlatform')
    ->inject('queueForDeletes')
    ->action(function (string $projectId, Response $response, Document $user, Database $dbForPlatform, Delete $queueForDeletes) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $queueForDeletes
            ->setProject($project)
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($project);

        if (!$dbForPlatform->deleteDocument('projects', $projectId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project from DB');
        }

        $response->noContent();
    });

// JWT Keys

Http::post('/v1/projects/:projectId/jwts')
    ->groups(['api', 'projects'])
    ->desc('Create JWT')
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'createJWT',
        description: '/docs/references/projects/create-jwt.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_JWT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of scopes allowed for JWT key. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
    ->param('duration', 900, new Range(0, 3600), 'Time in seconds before JWT expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, array $scopes, int $duration, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => API_KEY_DYNAMIC . '_' . $jwt->encode([
                'projectId' => $project->getId(),
                'scopes' => $scopes
            ])]), Response::MODEL_JWT);
    });

// Backwards compatibility
Http::delete('/v1/projects/:projectId/templates/email')
    ->alias('/v1/projects/:projectId/templates/email/:type/:locale')
    ->desc('Delete custom email template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', true, ['localeCodes'])
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForPlatform) {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

        $project = $dbForPlatform->getDocument('projects', $projectId);
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        unset($templates['email.' . $type . '-' . $locale]);

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->noContent();
    });
