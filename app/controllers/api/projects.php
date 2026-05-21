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

Http::patch('/v1/projects/:projectId/auth/session-alerts')
    ->desc('Update project sessions emails')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateSessionAlerts',
        description: '/docs/references/projects/update-session-alerts.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('alerts', false, new Boolean(true), 'Set to true to enable session emails.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $alerts, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['sessionAlerts'] = $alerts;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/memberships-privacy')
    ->desc('Update project memberships privacy attributes')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateMembershipsPrivacy',
        description: '/docs/references/projects/update-memberships-privacy.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('userName', true, new Boolean(true), 'Set to true to show userName to members of a team.')
    ->param('userEmail', true, new Boolean(true), 'Set to true to show email to members of a team.')
    ->param('mfa', true, new Boolean(true), 'Set to true to show mfa to members of a team.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $userName, bool $userEmail, bool $mfa, Response $response, Database $dbForPlatform) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);

        $auths['membershipsUserName'] = $userName;
        $auths['membershipsUserEmail'] = $userEmail;
        $auths['membershipsMfa'] = $mfa;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/limit')
    ->desc('Update project users limit')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthLimit',
        description: '/docs/references/projects/update-auth-limit.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('limit', false, new Range(0, APP_LIMIT_USERS), 'Set the max number of users allowed in this project. Use 0 for unlimited.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['limit'] = $limit;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/duration')
    ->desc('Update project authentication duration')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthDuration',
        description: '/docs/references/projects/update-auth-duration.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('duration', 31536000, new Range(0, 31536000), 'Project session length in seconds. Max length: 31536000 seconds.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $duration, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['duration'] = $duration;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

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
        $status = ($status === '1' || $status === 'true' || $status === 1 || $status === true);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths[$authKey] = $status;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-history')
    ->desc('Update authentication password history. Use this endpoint to set the number of password history to save and 0 to disable password history.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordHistory',
        description: '/docs/references/projects/update-auth-password-history.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('limit', 0, new Range(0, APP_LIMIT_USER_PASSWORD_HISTORY), 'Set the max number of passwords to store in user history. User can\'t choose a new password that is already stored in the password history list.  Max number of passwords allowed in history is' . APP_LIMIT_USER_PASSWORD_HISTORY . '. Default value is 0')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordHistory'] = $limit;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-policy/min-length')
    ->desc('Update the minimum password length requirement.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordPolicyMinLength',
        description: '/docs/references/projects/update-auth-password-policy-min-length.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('minLength', 8, new Range(8, 256), 'Set the minimum password length. Value must be between 8 and 256. Default is 8.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $minLength, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordPolicy'] = \array_merge($auths['passwordPolicy'] ?? [], [
            'minLength' => $minLength,
        ]);

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-policy/uppercase')
    ->desc('Update the uppercase password requirement.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordPolicyUppercase',
        description: '/docs/references/projects/update-auth-password-policy-uppercase.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('enabled', false, new Boolean(false), 'Set whether or not passwords must include at least one uppercase letter. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordPolicy'] = \array_merge($auths['passwordPolicy'] ?? [], [
            'requireUppercase' => $enabled,
        ]);

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-policy/lowercase')
    ->desc('Update the lowercase password requirement.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordPolicyLowercase',
        description: '/docs/references/projects/update-auth-password-policy-lowercase.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('enabled', false, new Boolean(false), 'Set whether or not passwords must include at least one lowercase letter. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordPolicy'] = \array_merge($auths['passwordPolicy'] ?? [], [
            'requireLowercase' => $enabled,
        ]);

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-policy/number')
    ->desc('Update the numeric password requirement.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordPolicyNumber',
        description: '/docs/references/projects/update-auth-password-policy-number.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('enabled', false, new Boolean(false), 'Set whether or not passwords must include at least one number. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordPolicy'] = \array_merge($auths['passwordPolicy'] ?? [], [
            'requireNumber' => $enabled,
        ]);

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-policy/special-char')
    ->desc('Update the special character password requirement.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordPolicySpecialChar',
        description: '/docs/references/projects/update-auth-password-policy-special-char.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('enabled', false, new Boolean(false), 'Set whether or not passwords must include at least one special character. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordPolicy'] = \array_merge($auths['passwordPolicy'] ?? [], [
            'requireSpecialChar' => $enabled,
        ]);

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/password-dictionary')
    ->desc('Update authentication password dictionary status. Use this endpoint to enable or disable the dicitonary check for user password')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordDictionary',
        description: '/docs/references/projects/update-auth-password-dictionary.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('enabled', false, new Boolean(false), 'Set whether or not to enable checking user\'s password against most commonly used passwords. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordDictionary'] = $enabled;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/personal-data')
    ->desc('Update personal data check')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updatePersonalDataCheck',
        description: '/docs/references/projects/update-personal-data-check.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('enabled', false, new Boolean(false), 'Set whether or not to check a password for similarity with personal data. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['personalDataCheck'] = $enabled;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

Http::patch('/v1/projects/:projectId/auth/max-sessions')
    ->desc('Update project user sessions limit')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthSessionsLimit',
        description: '/docs/references/projects/update-auth-sessions-limit.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Project unique ID.', false, ['dbForPlatform'])
    ->param('limit', false, new Range(1, APP_LIMIT_USER_SESSIONS_MAX), 'Set the max number of users allowed in this project. Value allowed is between 1-' . APP_LIMIT_USER_SESSIONS_MAX . '. Default is ' . APP_LIMIT_USER_SESSIONS_DEFAULT)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['maxSessions'] = $limit;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

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
