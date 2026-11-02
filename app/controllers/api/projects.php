<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

// Backwards compatibility
Http::delete('/v1/projects/:projectId/templates/email')
    ->alias('/v1/projects/:projectId/templates/email/:type/:locale')
    ->desc('Delete custom email template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Project unique ID.', false, ['dbForPlatform'])
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
