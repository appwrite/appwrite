<?php

use Appwrite\Auth\Validator\MockNumber;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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

// Backwards compatibility
Http::patch('/v1/projects/:projectId/oauth2')
    ->desc('Update project OAuth2')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
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

// Backwards compatibility
Http::patch('/v1/projects/:projectId/auth/mock-numbers')
    ->desc('Update the mock numbers for the project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
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
