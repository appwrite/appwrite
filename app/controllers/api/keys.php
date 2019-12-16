<?php

global $utopia, $response, $consoleDB, $project;

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

$utopia->get('/v1/keys')
    ->desc('List Keys')
    ->label('scope', 'keys.read')
    ->label('sdk.namespace', 'keys')
    ->label('sdk.method', 'listKeys')
    ->action(
        function () use ($response, $consoleDB, $project) {
            $response->json($project->getAttribute('keys', [])); //FIXME make sure array objects return correctly
        }
    );

$utopia->get('/v1/keys/:keyId')
    ->desc('Get Key')
    ->label('scope', 'keys.read')
    ->label('sdk.namespace', 'keys')
    ->label('sdk.method', 'getKey')
    ->param('keyId', null, function () { return new UID(); }, 'Key unique ID.')
    ->action(
        function ($keyId) use ($response, $consoleDB, $project) {
            $key = $project->search('$uid', $keyId, $project->getAttribute('keys', []));

            if (empty($key) && $key instanceof Document) {
                throw new Exception('Key not found', 404);
            }

            $response->json($key->getArrayCopy());
        }
    );

$utopia->post('/v1/keys')
    ->desc('Create Key')
    ->label('scope', 'keys.write')
    ->label('sdk.namespace', 'keys')
    ->label('sdk.method', 'createKey')
    ->param('name', null, function () { return new Text(256); }, 'Key name')
    ->param('scopes', null, function () use ($scopes) { return new ArrayList(new WhiteList($scopes)); }, 'Key scopes list')
    ->action(
        function ($name, $scopes) use ($response, $consoleDB, $project) {
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

$utopia->put('/v1/keys/:keyId')
    ->desc('Update Key')
    ->label('scope', 'keys.write')
    ->label('sdk.namespace', 'keys')
    ->label('sdk.method', 'updateKey')
    ->param('keyId', null, function () { return new UID(); }, 'Key unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Key name')
    ->param('scopes', null, function () use ($scopes) { return new ArrayList(new WhiteList($scopes)); }, 'Key scopes list')
    ->action(
        function ($keyId, $name, $scopes) use ($response, $consoleDB, $project) {
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

$utopia->delete('/v1/keys/:keyId')
    ->desc('Delete Key')
    ->label('scope', 'keys.write')
    ->label('sdk.namespace', 'keys')
    ->label('sdk.method', 'deleteKey')
    ->param('keyId', null, function () { return new UID(); }, 'Key unique ID.')
    ->action(
        function ($keyId) use ($response, $consoleDB, $project) {
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