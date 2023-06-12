<?php

use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Import;
use Appwrite\Event\Migration;
use Appwrite\Extend\Exception;
use Utopia\Database\Helpers\ID;
use Appwrite\Utopia\Database\Validator\Queries\Imports;
use Appwrite\Utopia\Database\Validator\Queries\Migrations;
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

App::get('/v1/migrations')
    ->groups(['api', 'migrations'])
    ->desc('List Migrations')
    ->label('scope', 'migrations.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/migrations/list-migrations.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION_LIST)
    ->param('queries', [], new Migrations(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Migrations::ALLOWED_ATTRIBUTES), true)
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
            $migrationId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('migrations', $migrationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Import '{$migrationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'migrations' => $dbForProject->find('migrations', $queries),
            'total' => $dbForProject->count('migrations', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_MIGRATION_LIST);
    });

App::get('/v1/migrations/:migrationId')
    ->groups(['api', 'migrations'])
    ->desc('Get Import')
    ->label('scope', 'migrations.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/migrations/get-migration.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('migrationId', '', new UID(), 'Import unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $migrationId, Response $response, Database $dbForProject) {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::IMPORT_NOT_FOUND, 'Import not found', 404);
        }

        $response->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/:migrationId')
    ->groups(['api', 'migrations'])
    ->desc('Retry Import')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].retry')
    ->label('audits.event', 'migration.retry')
    ->label('audits.resource', 'migrations/{request.migrationId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'retry')
    ->label('sdk.description', '/docs/references/migrations/retry-migration.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('migrationId', '', new UID(), 'Migration unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $migrationId, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventInstance) {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::IMPORT_NOT_FOUND);
        }

        // if ($migration->getAttribute('status') !== 'failed') {
        //     throw new Exception(Exception::IMPORT_IN_PROGRESS, 'Import not failed');
        // }

        $migration
            ->setAttribute('status', 'pending')
            ->setAttribute('dateUpdated', \time());

        // Trigger Import
        $event = new Migration();
        $event
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response->noContent();
    });

App::delete('/v1/migrations/:migrationId')
    ->groups(['api', 'migrations'])
    ->desc('Delete Import')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].delete')
    ->label('audits.event', 'migrationId.delete')
    ->label('audits.resource', 'migrations/{request.migrationId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-migration.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('migrationId', '', new UID(), 'Import ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $migrationId, Response $response, Database $dbForProject, Delete $deletes, Event $events) {

        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::IMPORT_NOT_FOUND, 'Import not found', 404);
        }

        if (!$dbForProject->deleteDocument('migrations', $migration->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove migration from DB', 500);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($migration);

        $events->setParam('migrationId', $migration->getId());

        $response->noContent();
    });

App::post('/v1/migrations/appwrite')
    ->groups(['api', 'migrations'])
    ->desc('Import Appwrite Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrationAppwrite')
    ->label('sdk.description', '/docs/references/migrations/migration-appwrite.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to migration')
    ->param('endpoint', '', new URL(), "Source's Appwrite Endpoint")
    ->param('projectId', '', new UID(), "Source's Project ID")
    ->param('apiKey', '', new Text(512), "Source's API Key")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $endpoint, string $projectId, string $apiKey, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $migration = $dbForProject->createDocument('migrations', new Document([
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

        $eventsInstance->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $event = new Migration();
        $event
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/firebase')
    ->groups(['api', 'migrations'])
    ->desc('Import Firebase Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrationFirebase')
    ->label('sdk.description', '/docs/references/migrations/migration-firebase.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to migration')
    ->param('serviceAccount', '', new Text(512), "Source's Service Account")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $serviceAccount, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $migration = $dbForProject->createDocument('migrations', new Document([
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

        $eventsInstance->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $event = new Migration();
        $event
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/supabase')
    ->groups(['api', 'migrations'])
    ->desc('Import Supabase Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrationSupabase')
    ->label('sdk.description', '/docs/references/migrations/migration-supabase.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to migration')
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
        $migration = $dbForProject->createDocument('migrations', new Document([
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

        $eventsInstance->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $event = new Migration();
        $event
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/nhost')
    ->groups(['api', 'migrations'])
    ->desc('Import NHost Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrationNhost')
    ->label('sdk.description', '/docs/references/migrations/migration-nhost.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Transfer::ALL_PUBLIC_RESOURCES)), 'List of resources to migration')
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
        $migration = $dbForProject->createDocument('migrations', new Document([
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

        $eventsInstance->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $event = new Migration();
        $event
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });
