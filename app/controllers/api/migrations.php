<?php

use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Migration;
use Appwrite\Extend\Exception;
use Utopia\Database\Helpers\ID;
use Appwrite\Utopia\Database\Validator\Queries\Migrations;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Transfer\Sources\Appwrite;
use Utopia\Transfer\Sources\Firebase;
use Utopia\Transfer\Sources\NHost;
use Utopia\Transfer\Sources\Supabase;
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
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Migration '{$migrationId}' for the 'cursor' value not found.");
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
    ->desc('Get Migration')
    ->label('scope', 'migrations.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/migrations/get-migration.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('migrationId', '', new UID(), 'Migration unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $migrationId, Response $response, Database $dbForProject) {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        $response->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::patch('/v1/migrations/:migrationId')
    ->groups(['api', 'migrations'])
    ->desc('Retry Migration')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].retry')
    ->label('audits.event', 'migration.retry')
    ->label('audits.resource', 'migrations/{request.migrationId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'retry')
    ->label('sdk.description', '/docs/references/migrations/retry-migration.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
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
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        if ($migration->getAttribute('status') !== 'failed') {
            throw new Exception(Exception::MIGRATION_IN_PROGRESS, 'Migration not failed yet');
        }

        $migration
            ->setAttribute('status', 'pending')
            ->setAttribute('dateUpdated', \time());

        // Trigger Migration
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
    ->desc('Delete Migration')
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
    ->param('migrationId', '', new UID(), 'Migration ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $migrationId, Response $response, Database $dbForProject, Delete $deletes, Event $events) {

        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('migrations', $migration->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove migration from DB', 500);
        }

        $events->setParam('migrationId', $migration->getId());

        $response->noContent();
    });

App::post('/v1/migrations/appwrite')
    ->groups(['api', 'migrations'])
    ->desc('Migrate Appwrite Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrateAppwrite')
    ->label('sdk.description', '/docs/references/migrations/migration-appwrite.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Appwrite::getSupportedResources())), 'List of resources to migrate')
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
                'type' => Appwrite::getName(),
                'endpoint' => $endpoint,
                'projectId' => $projectId,
                'apiKey' => $apiKey,
            ]),
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => "{}",
            'errors' => []
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
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::get('/v1/migrations/appwrite/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on Appwrite Data')
    ->label('scope', 'migrations.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'getAppwriteReport')
    ->label('sdk.description', '/docs/references/migrations/migration-appwrite-report.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION_REPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Appwrite::getSupportedResources())), 'List of resources to migrate')
    ->param('endpoint', '', new URL(), "Source's Appwrite Endpoint")
    ->param('projectID', '', new Text(512), "Source's Project ID")
    ->param('key', '', new Text(512), "Source's API Key")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->action(function (array $resources, string $endpoint, string $projectID, string $key, Response $response) {
        try {
            $appwrite = new Appwrite($projectID, $endpoint, $key);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->dynamic(new Document($appwrite->report($resources)), Response::MODEL_MIGRATION_REPORT);
        } catch (\Exception $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, $e->getMessage());
        }
    });


App::post('/v1/migrations/firebase')
    ->groups(['api', 'migrations'])
    ->desc('Migrate Firebase Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrateFirebase')
    ->label('sdk.description', '/docs/references/migrations/migration-firebase.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Firebase::getSupportedResources())), 'List of resources to migrate')
    ->param('serviceAccount', '', new Text(1024), "JSON of the Firebase service account credentials")
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
                'type' => Firebase::getName(),
                'serviceAccount' => $serviceAccount,
            ]),
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => "{}",
            'errors' => []
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
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::get('/v1/migrations/firebase/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on Firebase Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.report')
    ->label('audits.event', 'migration.report')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'getFirebaseReport')
    ->label('sdk.description', '/docs/references/migrations/migration-firebase-report.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION_REPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Firebase::getSupportedResources())), 'List of resources to migrate')
    ->param('serviceAccount', '', new Text(1024), "JSON of the Firebase service account credentials")
    ->param('databaseURL', '', new URL(), "Source's Database URL")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (array $resources, string $serviceAccount, string $databaseURL, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        try {
            $firebase = new Firebase(json_decode($serviceAccount, true), $databaseURL);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->dynamic(new Document($firebase->report($resources)), Response::MODEL_MIGRATION_REPORT);
        } catch (\Exception $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, $e->getMessage());
        }
    });

App::post('/v1/migrations/supabase')
    ->groups(['api', 'migrations'])
    ->desc('Migrate Supabase Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrateSupabase')
    ->label('sdk.description', '/docs/references/migrations/migration-supabase.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(Supabase::getSupportedResources(), true)), 'List of resources to migrate')
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
                'type' => Supabase::getName(),
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
            'errors' => []
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
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });


App::get('/v1/migrations/supabase/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on Supabase Data')
    ->label('scope', 'migrations.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'getSupabaseReport')
    ->label('sdk.description', '/docs/references/migrations/migration-supabase-report.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION_REPORT)
    ->param('resources', [], new ArrayList(new WhiteList(Supabase::getSupportedResources(), true)), 'List of resources to migrate')
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
        try {
            $supabase = new Supabase($endpoint, $apiKey, $databaseHost, 'postgres', $username, $password, $port);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->dynamic(new Document($supabase->report($resources)), Response::MODEL_MIGRATION_REPORT);
        } catch (\Exception $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, $e->getMessage());
        }
    });

App::post('/v1/migrations/nhost')
    ->groups(['api', 'migrations'])
    ->desc('Migrate NHost Data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.create')
    ->label('audits.event', 'migration.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'migrateNHost')
    ->label('sdk.description', '/docs/references/migrations/migration-nhost.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION)
    ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate')
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
                'type' => NHost::getName(),,
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
            'errors' => []
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
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::get('/v1/migrations/nhost/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on NHost Data')
    ->label('scope', 'migrations.write')
    // ->label('event', 'migrations.report')
    // ->label('audits.event', 'migration.report')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'migrations')
    ->label('sdk.method', 'getNHostReport')
    ->label('sdk.description', '/docs/references/migrations/migration-nhost-report.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MIGRATION_REPORT)
    ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate')
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
        try {
            $nhost = new NHost($subdomain, $region, $adminSecret, $database, $username, $password, $port);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->dynamic(new Document($nhost->report($resources)), Response::MODEL_MIGRATION_REPORT);
        } catch (\Exception $e) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, $e->getMessage());
        }
    });
