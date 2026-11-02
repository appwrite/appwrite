<?php

global $utopia, $request, $response;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\Locale\Locale;
use Utopia\System\System;

Http::get('/v1/mock/tests/locale')
    ->desc('Mock locale translation key')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('mock', true)
    ->inject('locale')
    ->inject('localeCodes')
    ->inject('request')
    ->inject('response')
    ->action(function (Locale $locale, array $localeCodes, Request $request, Response $response) {
        $localeParam = (string) $request->getParam('locale', $request->getHeaderLine('x-appwrite-locale', ''));
        if (\in_array($localeParam, $localeCodes)) {
            $locale->setDefault($localeParam);
        }

        $response->send($locale->getText('mock'));
    });

Http::get('/v1/mock/tests/avatars/favicon-page')
    ->desc('HTML page with a PNG favicon link for avatar favicon tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('mock', true)
    ->inject('response')
    ->action(function (Response $response) {
        $html = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
            <link rel="icon" href="/v1/mock/tests/avatars/bad-icon.png" sizes="64x64">
            </head>
            <body>favicon test page</body>
            </html>
            HTML;

        $response
            ->setContentType('text/html')
            ->send($html);
    });

Http::get('/v1/mock/tests/avatars/bad-icon.png')
    ->desc('Non-image bytes served from a PNG favicon URL for avatar favicon tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('mock', true)
    ->inject('response')
    ->action(function (Response $response) {
        $response
            ->setContentType('text/html')
            ->send('<html><body>error page</body></html>');
    });

Http::get('/v1/mock/tests/avatars/favicon-page-truncated')
    ->desc('HTML page with a PNG favicon link to truncated bytes for avatar favicon tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('mock', true)
    ->inject('response')
    ->action(function (Response $response) {
        $html = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
            <link rel="icon" href="/v1/mock/tests/avatars/truncated-icon.png" sizes="64x64">
            </head>
            <body>truncated favicon test page</body>
            </html>
            HTML;

        $response
            ->setContentType('text/html')
            ->send($html);
    });

Http::get('/v1/mock/tests/avatars/truncated-icon.png')
    ->desc('Truncated PNG bytes for avatar favicon decode failure tests')
    ->groups(['mock'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->label('mock', true)
    ->inject('response')
    ->action(function (Response $response) {
        $response
            ->setContentType('image/png')
            ->file("\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR");
    });

Http::post('/v1/mock/api-key-unprefixed')
    ->desc('Create API Key (without standard prefix)')
    ->groups(['mock', 'api', 'projects'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', new UID(), 'Project ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, Response $response, Database $dbForPlatform) {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $scopes = array_keys(Config::getParam('projectScopes'));

        $key = new Document([
            '$id' => Id::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceInternalId' => $project->getSequence(),
            'resourceId' => $project->getId(),
            'resourceType' => 'projects',
            'name' => 'Outdated key',
            'scopes' => $scopes,
            'expire' => null,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => \bin2hex(\random_bytes(128)),
        ]);

        $key = $dbForPlatform->createDocument('keys', $key);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_KEY);
    });

Http::get('/v1/mock/github/callback')
    ->desc('Create installation document using GitHub installation id')
    ->groups(['mock', 'api', 'vcs'])
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('providerInstallationId', '', new UID(), 'GitHub installation ID')
    ->param('projectId', '', new UID(), 'Project ID of the project where app is to be installed')
    ->inject('vcsFactory')
    ->inject('project')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $providerInstallationId, string $projectId, VcsFactory $vcsFactory, Document $project, Response $response, Database $dbForPlatform) {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';
            throw new Exception(Exception::PROJECT_NOT_FOUND, $error);
        }

        if (empty($providerInstallationId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing provider installation ID');
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => 'github',
            'providerInstallationId' => $providerInstallationId,
        ]));
        $owner = $vcs->getOwnerName($providerInstallationId);

        $projectInternalId = $project->getSequence();

        $teamId = $project->getAttribute('teamId', '');

        $installation = new Document([
            '$id' => Id::unique(),
            '$permissions' => [
                Permission::read(Role::team(Id::custom($teamId))),
                Permission::update(Role::team(Id::custom($teamId), 'owner')),
                Permission::update(Role::team(Id::custom($teamId), 'developer')),
                Permission::delete(Role::team(Id::custom($teamId), 'owner')),
                Permission::delete(Role::team(Id::custom($teamId), 'developer')),
            ],
            'providerInstallationId' => $providerInstallationId,
            'projectId' => $projectId,
            'projectInternalId' => $projectInternalId,
            'provider' => 'github',
            'organization' => $owner,
            'personal' => false
        ]);

        $installation = $dbForPlatform->createDocument('installations', $installation);

        $response->json([
            'installationId' => $installation->getId(),
        ]);
    });
