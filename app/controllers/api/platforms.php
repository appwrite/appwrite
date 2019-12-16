<?php

global $utopia, $request, $response, $consoleDB, $project;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Validator\URL;
use Database\Database;
use Database\Document;
use Database\Validator\UID;

include_once __DIR__ . '/../shared/api.php';

$utopia->get('/v1/platforms')
    ->desc('List Platforms')
    ->label('scope', 'platforms.read')
    ->label('sdk.namespace', 'platforms')
    ->label('sdk.method', 'listPlatforms')
    ->action(
        function () use ($request, $response, $consoleDB, $project) {
            $response->json($project->getAttribute('platforms', []));
        }
    );

$utopia->get('/v1/platforms/:platformId')
    ->desc('Get Platform')
    ->label('scope', 'platforms.read')
    ->label('sdk.namespace', 'platforms')
    ->label('sdk.method', 'getPlatform')
    ->param('platformId', null, function () { return new UID(); }, 'Platform unique ID.')
    ->action(
        function ($platformId) use ($request, $response, $consoleDB, $project) {
            $platform = $project->search('$uid', $platformId, $project->getAttribute('platforms', []));

            if (empty($platform) && $platform instanceof Document) {
                throw new Exception('Platform not found', 404);
            }

            $response->json($platform->getArrayCopy());
        }
    );

$utopia->post('/v1/platforms')
    ->desc('Create Platform')
    ->label('scope', 'platforms.write')
    ->label('sdk.namespace', 'platforms')
    ->label('sdk.method', 'createPlatform')
    ->param('type', null, function () { return new WhiteList(['web', 'ios', 'android', 'unity']); }, 'Platform name')
    ->param('name', null, function () { return new Text(256); }, 'Platform name')
    ->param('key', '', function () { return new Text(256); }, 'Package name for android or bundle ID for iOS', true)
    ->param('store', '', function () { return new Text(256); }, 'App store or Google Play store ID', true)
    ->param('url', '', function () { return new URL(); }, 'Platform client URL', true)
    ->action(
        function ($type, $name, $key, $store, $url) use ($response, $consoleDB, $project) {
            $platform = $consoleDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_PLATFORMS,
                '$permissions' => [
                    'read' => ['team:'.$project->getAttribute('teamId', null)],
                    'write' => ['team:'.$project->getAttribute('teamId', null).'/owner', 'team:'.$project->getAttribute('teamId', null).'/developer'],
                ],
                'type' => $type,
                'name' => $name,
                'key' => $key,
                'store' => $store,
                'url' => $url,
                'dateCreated' => time(),
                'dateUpdated' => time(),
            ]);

            if (false === $platform) {
                throw new Exception('Failed saving platform to DB', 500);
            }

            $project->setAttribute('platforms', $platform, Document::SET_TYPE_APPEND);

            $project = $consoleDB->updateDocument($project->getArrayCopy());

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($platform->getArrayCopy())
            ;
        }
    );

$utopia->put('/v1/platforms/:platformId')
    ->desc('Update Platform')
    ->label('scope', 'platforms.write')
    ->label('sdk.namespace', 'platforms')
    ->label('sdk.method', 'updatePlatform')
    ->param('platformId', null, function () { return new UID(); }, 'Platform unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Platform name')
    ->param('key', '', function () { return new Text(256); }, 'Package name for android or bundle ID for iOS', true)
    ->param('store', '', function () { return new Text(256); }, 'App store or Google Play store ID', true)
    ->param('url', '', function () { return new URL(); }, 'Platform client URL', true)
    ->action(
        function ($platformId, $name, $key, $store, $url) use ($response, $consoleDB, $project) {
            $platform = $project->search('$uid', $platformId, $project->getAttribute('platforms', []));

            if (empty($platform) && $platform instanceof Document) {
                throw new Exception('Platform not found', 404);
            }

            $platform
                ->setAttribute('name', $name)
                ->setAttribute('dateUpdated', time())
                ->setAttribute('key', $key)
                ->setAttribute('store', $store)
                ->setAttribute('url', $url)
            ;

            if (false === $consoleDB->updateDocument($platform->getArrayCopy())) {
                throw new Exception('Failed saving platform to DB', 500);
            }

            $response->json($platform->getArrayCopy());
        }
    );

$utopia->delete('/v1/platforms/:platformId')
    ->desc('Delete Platform')
    ->label('scope', 'platforms.write')
    ->label('sdk.namespace', 'platforms')
    ->label('sdk.method', 'deletePlatform')
    ->param('platformId', null, function () { return new UID(); }, 'Platform unique ID.')
    ->action(
        function ($platformId) use ($response, $consoleDB, $project) {
            $platform = $project->search('$uid', $platformId, $project->getAttribute('platforms', []));

            if (empty($platform) && $platform instanceof Document) {
                throw new Exception('Platform not found', 404);
            }

            if (!$consoleDB->deleteDocument($platform->getUid())) {
                throw new Exception('Failed to remove platform from DB', 500);
            }

            $response->noContent();
        }
    );
