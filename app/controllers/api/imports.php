<?php

use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Import;
use Appwrite\Extend\Exception;
use Utopia\Database\Helpers\ID;
use Appwrite\Utopia\Database\Validator\Queries\Imports;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Transfer\Transfer;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

include_once __DIR__ . '/../shared/api.php';

App::get('/v1/imports')
    ->groups(['api', 'imports'])
    ->desc('List Imports')
    ->label('scope', 'imports.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/imports/list-imports.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT_LIST)
    ->param('queries', [], new Imports(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Imports::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $importId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('imports', $importId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Import '{$importId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'imports' => $dbForProject->find('imports', $queries),
            'total' => $dbForProject->count('imports', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_IMPORT_LIST);
    });

App::get('/v1/imports/:importId')
    ->groups(['api', 'imports'])
    ->desc('Get Import')
    ->label('scope', 'imports.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/imports/get-import.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT)
    ->param('importId', '', new UID(), 'Import unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $importId, Response $response, Database $dbForProject) {
        $import = $dbForProject->getDocument('imports', $importId);

        if ($import->isEmpty()) {
            throw new Exception(Exception::IMPORT_NOT_FOUND, 'Import not found', 404);
        }

        $response->dynamic($import, Response::MODEL_IMPORT);
    });

App::post('/v1/imports/:importId')
    ->groups(['api', 'imports'])
    ->desc('Retry Import')
    ->label('scope', 'imports.write')
    ->label('event', 'imports.[importId].retry')
    ->label('audits.event', 'import.retry')
    ->label('audits.resource', 'imports/{request.importId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'retry')
    ->label('sdk.description', '/docs/references/imports/retry-import.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT)
    ->param('importId', '', new UID(), 'Import unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $importId, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventInstance) {
        $import = $dbForProject->getDocument('imports', $importId);

        if ($import->isEmpty()) {
            throw new Exception(Exception::IMPORT_NOT_FOUND);
        }

        // if ($import->getAttribute('status') !== 'failed') {
        //     throw new Exception(Exception::IMPORT_IN_PROGRESS, 'Import not failed');
        // }

        $import
            ->setAttribute('status', 'pending')
            ->setAttribute('dateUpdated', \time());

        // Trigger Import
        $event = new Import();
        $event
            ->setImport($import)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response->noContent();
    });

App::delete('/v1/imports/:importId')
    ->groups(['api', 'imports'])
    ->desc('Delete Import')
    ->label('scope', 'imports.write')
    ->label('event', 'imports.[importId].delete')
    ->label('audits.event', 'importId.delete')
    ->label('audits.resource', 'imports/{request.importId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-import.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('importId', '', new UID(), 'Import ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $importId, Response $response, Database $dbForProject, Delete $deletes, Event $events) {

        $import = $dbForProject->getDocument('imports', $importId);

        if ($import->isEmpty()) {
            throw new Exception(Exception::IMPORT_NOT_FOUND, 'Import not found', 404);
        }

        if (!$dbForProject->deleteDocument('imports', $import->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove import from DB', 500);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($import);

        $events->setParam('importId', $import->getId());

        $response->noContent();
    });

App::post('/v1/imports/appwrite')
    ->groups(['api', 'imports'])
    ->desc('Import Appwrite Data')
    ->label('scope', 'imports.write')
    ->label('event', 'imports.create')
    ->label('audits.event', 'import.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'importAppwrite')
    ->label('sdk.description', '/docs/references/imports/import-appwrite.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to import')
    ->param('endpoint', '', new URL(), "Source's Appwrite Endpoint")
    ->param('projectId', '', new UID(), "Source's Project ID")
    ->param('apiKey', '', new Text(512), "Source's API Key")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $endpoint, string $projectId, string $apiKey, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $import = $dbForProject->createDocument('imports', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => json_encode([
                'type' => 'appwrite',
                'endpoint' => $endpoint,
                'projectId' => $projectId,
                'apiKey' => $apiKey,
            ]),
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => "{}",
            'errorData' => ""
        ]));

        $eventsInstance->setParam('importId', $import->getId());

        // Trigger Transfer
        $event = new Import();
        $event
            ->setImport($import)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($import, Response::MODEL_IMPORT);
    });

App::post('/v1/imports/firebase')
    ->groups(['api', 'imports'])
    ->desc('Import Firebase Data')
    ->label('scope', 'imports.write')
    ->label('event', 'imports.create')
    ->label('audits.event', 'import.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'importFirebase')
    ->label('sdk.description', '/docs/references/imports/import-firebase.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to import')
    ->param('serviceAccount', '', new Text(512), "Source's Service Account")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $serviceAccount, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $import = $dbForProject->createDocument('imports', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => json_encode([
                'type' => 'firebase',
                'serviceAccount' => $serviceAccount,
            ]),
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => "{}",
            'errorData' => ""
        ]));

        $eventsInstance->setParam('importId', $import->getId());

        // Trigger Transfer
        $event = new Import();
        $event
            ->setImport($import)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($import, Response::MODEL_IMPORT);
    });

App::post('/v1/imports/supabase')
    ->groups(['api', 'imports'])
    ->desc('Import Supabase Data')
    ->label('scope', 'imports.write')
    ->label('event', 'imports.create')
    ->label('audits.event', 'import.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'importSupabase')
    ->label('sdk.description', '/docs/references/imports/import-supabase.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to import')
    ->param('endpoint', '', new URL(), "Source's Supabase Endpoint")
    ->param('apiKey', '', new Text(512), "Source's API Key")
    ->param('databaseHost', '', new Text(512), "Source's Database Host")
    ->param('username', '', new Text(512), "Source's Database Username")
    ->param('password', '', new Text(512), "Source's Database Password")
    ->param('port', 5432, new Integer(), "Source's Database Port", true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $endpoint, string $apiKey, string $databaseHost, string $username, string $password, int $port, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $import = $dbForProject->createDocument('imports', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => json_encode([
                'type' => 'supabase',
                'endpoint' => $endpoint,
                'apiKey' => $apiKey,
                'databaseHost' => $databaseHost,
                'username' => $username,
                'password' => $password,
                'port' => $port,
            ]),
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => "{}",
            'errorData' => ""
        ]));

        $eventsInstance->setParam('importId', $import->getId());

        // Trigger Transfer
        $event = new Import();
        $event
            ->setImport($import)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($import, Response::MODEL_IMPORT);
    });

App::post('/v1/imports/nhost')
    ->groups(['api', 'imports'])
    ->desc('Import NHost Data')
    ->label('scope', 'imports.write')
    ->label('event', 'imports.create')
    ->label('audits.event', 'import.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'imports')
    ->label('sdk.method', 'importNhost')
    ->label('sdk.description', '/docs/references/imports/import-nhost.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IMPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to import')
    ->param('subdomain', '', new URL(), "Source's Subdomain")
    ->param('region', '', new Text(512), "Source's Region")
    ->param('adminSecret', '', new Text(512), "Source's Admin Secret")
    ->param('database', '', new Text(512), "Source's Database Name")
    ->param('username', '', new Text(512), "Source's Database Username")
    ->param('password', '', new Text(512), "Source's Database Password")
    ->param('port', 5432, new Integer(), "Source's Database Port", true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $subdomain, string $region, string $adminSecret, string $database, string $username, string $password, int $port, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $import = $dbForProject->createDocument('imports', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => json_encode([
                'type' => 'nhost',
                'subdomain' => $subdomain,
                'region' => $region,
                'adminSecret' => $adminSecret,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'port' => $port,
            ]),
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => "{}",
            'errorData' => ""
        ]));

        $eventsInstance->setParam('importId', $import->getId());

        // Trigger Transfer
        $event = new Import();
        $event
            ->setImport($import)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($import, Response::MODEL_IMPORT);
    });
