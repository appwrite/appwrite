<?php

global $utopia, $response, $consoleDB;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Database\Database;
use Database\Document;
use Database\Validator\UID;

include_once '../shared/api.php';

$scopes = [ // TODO sync with console UI list
    'users.read',
    'users.write',
    'teams.read',
    'teams.write',
    'collections.read',
    'collections.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
];

$utopia->get('/v1/projects/:projectId/keys')
    ->desc('List Keys')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listKeys')
    ->param('projectId', null, function () { return new UID(); }, 'Project unique ID.')
    ->action(
        function ($projectId) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $response->json($project->getAttribute('keys', [])); //FIXME make sure array objects return correctly
        }
    );

$utopia->get('/v1/projects/:projectId/keys/:keyId')
    ->desc('Get Key')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getKey')
    ->param('projectId', null, function () { return new UID(); }, 'Project unique ID.')
    ->param('keyId', null, function () { return new UID(); }, 'Key unique ID.')
    ->action(
        function ($projectId, $keyId) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $key = $project->search('$uid', $keyId, $project->getAttribute('keys', []));

            if (empty($key) && $key instanceof Document) {
                throw new Exception('Key not found', 404);
            }

            $response->json($key->getArrayCopy());
        }
    );

$utopia->post('/v1/projects/:projectId/keys')
    ->desc('Create Key')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createKey')
    ->param('projectId', null, function () { return new UID(); }, 'Project unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Key name')
    ->param('scopes', null, function () use ($scopes) { return new ArrayList(new WhiteList($scopes)); }, 'Key scopes list')
    ->action(
        function ($projectId, $name, $scopes) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $key = $consoleDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_KEYS,
                '$permissions' => [
                    'read' => ['team:'.$project->getAttribute('teamId', null)],
                    'write' => ['team:'.$project->getAttribute('teamId', null).'/owner', 'team:'.$project->getAttribute('teamId', null).'/developer'],
                ],
                'name' => $name,
                'scopes' => $scopes,
                'secret' => bin2hex(random_bytes(128)),
            ]);

            if (false === $key) {
                throw new Exception('Failed saving key to DB', 500);
            }

            $project->setAttribute('keys', $key, Document::SET_TYPE_APPEND);

            $project = $consoleDB->updateDocument($project->getArrayCopy());

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($key->getArrayCopy())
            ;
        }
    );

$utopia->put('/v1/projects/:projectId/keys/:keyId')
    ->desc('Update Key')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateKey')
    ->param('projectId', null, function () { return new UID(); }, 'Project unique ID.')
    ->param('keyId', null, function () { return new UID(); }, 'Key unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Key name')
    ->param('scopes', null, function () use ($scopes) { return new ArrayList(new WhiteList($scopes)); }, 'Key scopes list')
    ->action(
        function ($projectId, $keyId, $name, $scopes) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $key = $project->search('$uid', $keyId, $project->getAttribute('keys', []));

            if (empty($key) && $key instanceof Document) {
                throw new Exception('Key not found', 404);
            }

            $key
                ->setAttribute('name', $name)
                ->setAttribute('scopes', $scopes)
            ;

            if (false === $consoleDB->updateDocument($key->getArrayCopy())) {
                throw new Exception('Failed saving key to DB', 500);
            }

            $response->json($key->getArrayCopy());
        }
    );

$utopia->delete('/v1/projects/:projectId/keys/:keyId')
    ->desc('Delete Key')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteKey')
    ->param('projectId', null, function () { return new UID(); }, 'Project unique ID.')
    ->param('keyId', null, function () { return new UID(); }, 'Key unique ID.')
    ->action(
        function ($projectId, $keyId) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $key = $project->search('$uid', $keyId, $project->getAttribute('keys', []));

            if (empty($key) && $key instanceof Document) {
                throw new Exception('Key not found', 404);
            }

            if (!$consoleDB->deleteDocument($key->getUid())) {
                throw new Exception('Failed to remove key from DB', 500);
            }

            $response->noContent();
        }
    );