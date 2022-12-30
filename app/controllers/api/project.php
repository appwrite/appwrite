<?php

use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;

App::patch('/v1/project/:projectId/team')
    ->desc('Update Project Team')
    ->groups(['api', 'project'])
    ->label('scope', 'projects.transfer')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'updateTeam')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('teamId', '', new UID(), 'New Team ID.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForConsole')
    ->inject('deletes')
    ->action(function (string $projectId, string $teamId, Response $response, Document $user, Database $dbForConsole, Delete $deletes) {
        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $team = $dbForConsole->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if ($team->getInternalId() === $project->getAttribute('teamInternalId')) {
            throw new Exception(Exception::PROJECT_TEAM_ALREADY_MATCHES);
        }

        $project
            ->setAttribute('teamId', $team->getId())
            ->setAttribute('teamInternalId', $team->getInternalId())
        ;

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $response->dynamic($project, Response::MODEL_PROJECT);
    });