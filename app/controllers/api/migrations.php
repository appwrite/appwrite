<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Event;
use Appwrite\Event\Migration;
use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CompoundUID;
use Appwrite\Utopia\Database\Validator\Queries\Migrations;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\NHost;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Transfer;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Utopia\Storage\Compression\Compression;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/migrations/appwrite')
    ->groups(['api', 'migrations'])
    ->desc('Migrate Appwrite data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].create')
    ->label('audits.event', 'migration.create')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'createAppwriteMigration',
        description: '/docs/references/migrations/migration-appwrite.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(Appwrite::getSupportedResources())), 'List of resources to migrate')
    ->param('endpoint', '', new URL(), 'Source Appwrite endpoint')
    ->param('projectId', '', new UID(), 'Source Project ID')
    ->param('apiKey', '', new Text(512), 'Source API Key')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForMigrations')
    ->action(function (array $resources, string $endpoint, string $projectId, string $apiKey, Response $response, Database $dbForProject, Document $project, Document $user, Event $queueForEvents, Migration $queueForMigrations) {
        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => Appwrite::getName(),
            'destination' => Appwrite::getName(),
            'credentials' => [
                'endpoint' => $endpoint,
                'projectId' => $projectId,
                'apiKey' => $apiKey,
            ],
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $queueForMigrations
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/firebase')
    ->groups(['api', 'migrations'])
    ->desc('Migrate Firebase data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].create')
    ->label('audits.event', 'migration.create')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'createFirebaseMigration',
        description: '/docs/references/migrations/migration-firebase.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(Firebase::getSupportedResources())), 'List of resources to migrate')
    ->param('serviceAccount', '', new Text(65536), 'JSON of the Firebase service account credentials')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForMigrations')
    ->action(function (array $resources, string $serviceAccount, Response $response, Database $dbForProject, Document $project, Document $user, Event $queueForEvents, Migration $queueForMigrations) {
        $serviceAccountData = json_decode($serviceAccount, true);

        if (empty($serviceAccountData)) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Invalid Service Account JSON');
        }

        if (!isset($serviceAccountData['project_id']) || !isset($serviceAccountData['client_email']) || !isset($serviceAccountData['private_key'])) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Invalid Service Account JSON');
        }

        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => Firebase::getName(),
            'destination' => Appwrite::getName(),
            'credentials' => [
                'serviceAccount' => $serviceAccount,
            ],
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $queueForMigrations
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/supabase')
    ->groups(['api', 'migrations'])
    ->desc('Migrate Supabase data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].create')
    ->label('audits.event', 'migration.create')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'createSupabaseMigration',
        description: '/docs/references/migrations/migration-supabase.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(Supabase::getSupportedResources(), true)), 'List of resources to migrate')
    ->param('endpoint', '', new URL(), 'Source\'s Supabase Endpoint')
    ->param('apiKey', '', new Text(512), 'Source\'s API Key')
    ->param('databaseHost', '', new Text(512), 'Source\'s Database Host')
    ->param('username', '', new Text(512), 'Source\'s Database Username')
    ->param('password', '', new Text(512), 'Source\'s Database Password')
    ->param('port', 5432, new Integer(true), 'Source\'s Database Port', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForMigrations')
    ->action(function (array $resources, string $endpoint, string $apiKey, string $databaseHost, string $username, string $password, int $port, Response $response, Database $dbForProject, Document $project, Document $user, Event $queueForEvents, Migration $queueForMigrations) {
        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => Supabase::getName(),
            'destination' => Appwrite::getName(),
            'credentials' => [
                'endpoint' => $endpoint,
                'apiKey' => $apiKey,
                'databaseHost' => $databaseHost,
                'username' => $username,
                'password' => $password,
                'port' => $port,
            ],
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $queueForMigrations
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/nhost')
    ->groups(['api', 'migrations'])
    ->desc('Migrate NHost data')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].create')
    ->label('audits.event', 'migration.create')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'createNHostMigration',
        description: '/docs/references/migrations/migration-nhost.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate')
    ->param('subdomain', '', new Text(512), 'Source\'s Subdomain')
    ->param('region', '', new Text(512), 'Source\'s Region')
    ->param('adminSecret', '', new Text(512), 'Source\'s Admin Secret')
    ->param('database', '', new Text(512), 'Source\'s Database Name')
    ->param('username', '', new Text(512), 'Source\'s Database Username')
    ->param('password', '', new Text(512), 'Source\'s Database Password')
    ->param('port', 5432, new Integer(true), 'Source\'s Database Port', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForMigrations')
    ->action(function (array $resources, string $subdomain, string $region, string $adminSecret, string $database, string $username, string $password, int $port, Response $response, Database $dbForProject, Document $project, Document $user, Event $queueForEvents, Migration $queueForMigrations) {
        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => NHost::getName(),
            'destination' => Appwrite::getName(),
            'credentials' => [
                'subdomain' => $subdomain,
                'region' => $region,
                'adminSecret' => $adminSecret,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'port' => $port,
            ],
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        // Trigger Transfer
        $queueForMigrations
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::post('/v1/migrations/csv')
    ->groups(['api', 'migrations'])
    ->desc('Import documents from a CSV')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].create')
    ->label('audits.event', 'migration.create')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'createCsvMigration',
        description: '/docs/references/migrations/migration-csv.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
    ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
    ->param('fileId', '', new UID(), 'File ID.')
    ->param('resourceId', null, new CompoundUID(), 'Composite ID in the format {databaseId:collectionId}, identifying a collection within a database.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('deviceForFiles')
    ->inject('deviceForImports')
    ->inject('queueForEvents')
    ->inject('queueForMigrations')
    ->action(function (string $bucketId, string $fileId, string $resourceId, Response $response, Database $dbForProject, Document $project, Device $deviceForFiles, Device $deviceForImports, Event $queueForEvents, Migration $queueForMigrations) {
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty() || (!$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $path = $file->getAttribute('path', '');
        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        // no encryption, compression on files above 20MB.
        $hasEncryption = !empty($file->getAttribute('openSSLCipher'));
        $compression = $file->getAttribute('algorithm', Compression::NONE);
        $hasCompression = $compression !== Compression::NONE;

        $migrationId = ID::unique();
        $newPath = $deviceForImports->getPath('/' . $migrationId . '_' . $fileId . '.csv');

        if ($hasEncryption || $hasCompression) {
            $source = $deviceForFiles->read($path);

            // 1. decrypt
            if ($hasEncryption) {
                $source = OpenSSL::decrypt(
                    $source,
                    $file->getAttribute('openSSLCipher'),
                    System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                    0,
                    hex2bin($file->getAttribute('openSSLIV')),
                    hex2bin($file->getAttribute('openSSLTag'))
                );
            }

            // 2. decompress
            if ($hasCompression) {
                switch ($compression) {
                    case Compression::ZSTD:
                        $source = (new Zstd())->decompress($source);
                        break;
                    case Compression::GZIP:
                        $source = (new GZIP())->decompress($source);
                        break;
                }
            }

            // manual write after decryption and/or decompression
            if (! $deviceForImports->write($newPath, $source, 'text/csv')) {
                throw new \Exception("Unable to copy file");
            }
        } elseif (! $deviceForFiles->transfer($path, $newPath, $deviceForImports)) {
            throw new \Exception("Unable to copy file");
        }

        $fileSize = $deviceForImports->getFileSize($newPath);
        $resources = Transfer::extractServices([Transfer::GROUP_DATABASES]);

        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => $migrationId,
            'status' => 'pending',
            'stage' => 'init',
            'source' => CSV::getName(),
            'destination' => Appwrite::getName(),
            'resources' => $resources,
            'resourceId' => $resourceId,
            'resourceType' => Resource::TYPE_DATABASE,
            'statusCounters' => [],
            'resourceData' => [],
            'errors' => [],
            'options' => [
                'path' => $newPath,
                'size' => $fileSize,
            ],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        $queueForMigrations
            ->setMigration($migration)
            ->setProject($project)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    });

App::get('/v1/migrations')
    ->groups(['api', 'migrations'])
    ->desc('List migrations')
    ->label('scope', 'migrations.read')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'list',
        description: '/docs/references/migrations/list-migrations.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MIGRATION_LIST,
            )
        ]
    ))
    ->param('queries', [], new Migrations(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Migrations::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

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
    ->desc('Get migration')
    ->label('scope', 'migrations.read')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'get',
        description: '/docs/references/migrations/get-migration.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
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

App::get('/v1/migrations/appwrite/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on Appwrite data')
    ->label('scope', 'migrations.write')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'getAppwriteReport',
        description: '/docs/references/migrations/migration-appwrite-report.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MIGRATION_REPORT,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(Appwrite::getSupportedResources())), 'List of resources to migrate')
    ->param('endpoint', '', new URL(), "Source's Appwrite Endpoint")
    ->param('projectID', '', new Text(512), "Source's Project ID")
    ->param('key', '', new Text(512), "Source's API Key")
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->action(function (array $resources, string $endpoint, string $projectID, string $key, Response $response) {
        $appwrite = new Appwrite($projectID, $endpoint, $key);

        try {
            $report = $appwrite->report($resources);
        } catch (\Throwable $e) {
            switch ($e->getCode()) {
                case 401:
                    throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'Source Error: ' . $e->getMessage());
                case 429:
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Source Error: Rate Limit Exceeded, Is your Cloud Provider blocking Appwrite\'s IP?');
                case 500:
                    throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
            }

            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($report), Response::MODEL_MIGRATION_REPORT);
    });

App::get('/v1/migrations/firebase/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on Firebase data')
    ->label('scope', 'migrations.write')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'getFirebaseReport',
        description: '/docs/references/migrations/migration-firebase-report.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MIGRATION_REPORT,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(Firebase::getSupportedResources())), 'List of resources to migrate')
    ->param('serviceAccount', '', new Text(65536), 'JSON of the Firebase service account credentials')
    ->inject('response')
    ->action(function (array $resources, string $serviceAccount, Response $response) {
        $serviceAccount = json_decode($serviceAccount, true);

        if (empty($serviceAccount)) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Invalid Service Account JSON');
        }

        if (!isset($serviceAccount['project_id']) || !isset($serviceAccount['client_email']) || !isset($serviceAccount['private_key'])) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Invalid Service Account JSON');
        }

        $firebase = new Firebase($serviceAccount);

        try {
            $report = $firebase->report($resources);
        } catch (\Throwable $e) {
            switch ($e->getCode()) {
                case 401:
                    throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'Source Error: ' . $e->getMessage());
                case 429:
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Source Error: Rate Limit Exceeded, Is your Cloud Provider blocking Appwrite\'s IP?');
                case 500:
                    throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
            }

            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($report), Response::MODEL_MIGRATION_REPORT);
    });

App::get('/v1/migrations/supabase/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on Supabase Data')
    ->label('scope', 'migrations.write')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'getSupabaseReport',
        description: '/docs/references/migrations/migration-supabase-report.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MIGRATION_REPORT,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(Supabase::getSupportedResources(), true)), 'List of resources to migrate')
    ->param('endpoint', '', new URL(), 'Source\'s Supabase Endpoint.')
    ->param('apiKey', '', new Text(512), 'Source\'s API Key.')
    ->param('databaseHost', '', new Text(512), 'Source\'s Database Host.')
    ->param('username', '', new Text(512), 'Source\'s Database Username.')
    ->param('password', '', new Text(512), 'Source\'s Database Password.')
    ->param('port', 5432, new Integer(true), 'Source\'s Database Port.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $resources, string $endpoint, string $apiKey, string $databaseHost, string $username, string $password, int $port, Response $response) {
        $supabase = new Supabase($endpoint, $apiKey, $databaseHost, 'postgres', $username, $password, $port);

        try {
            $report = $supabase->report($resources);
        } catch (\Throwable $e) {
            switch ($e->getCode()) {
                case 401:
                    throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'Source Error: ' . $e->getMessage());
                case 429:
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Source Error: Rate Limit Exceeded, Is your Cloud Provider blocking Appwrite\'s IP?');
                case 500:
                    throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
            }

            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($report), Response::MODEL_MIGRATION_REPORT);
    });

App::get('/v1/migrations/nhost/report')
    ->groups(['api', 'migrations'])
    ->desc('Generate a report on NHost Data')
    ->label('scope', 'migrations.write')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'getNHostReport',
        description: '/docs/references/migrations/migration-nhost-report.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MIGRATION_REPORT,
            )
        ]
    ))
    ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate.')
    ->param('subdomain', '', new Text(512), 'Source\'s Subdomain.')
    ->param('region', '', new Text(512), 'Source\'s Region.')
    ->param('adminSecret', '', new Text(512), 'Source\'s Admin Secret.')
    ->param('database', '', new Text(512), 'Source\'s Database Name.')
    ->param('username', '', new Text(512), 'Source\'s Database Username.')
    ->param('password', '', new Text(512), 'Source\'s Database Password.')
    ->param('port', 5432, new Integer(true), 'Source\'s Database Port.', true)
    ->inject('response')
    ->action(function (array $resources, string $subdomain, string $region, string $adminSecret, string $database, string $username, string $password, int $port, Response $response) {
        $nhost = new NHost($subdomain, $region, $adminSecret, $database, $username, $password, $port);

        try {
            $report = $nhost->report($resources);
        } catch (\Throwable $e) {
            switch ($e->getCode()) {
                case 401:
                    throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'Source Error: ' . $e->getMessage());
                case 429:
                    throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Source Error: Rate Limit Exceeded, Is your Cloud Provider blocking Appwrite\'s IP?');
                case 500:
                    throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
            }

            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Source Error: ' . $e->getMessage());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($report), Response::MODEL_MIGRATION_REPORT);
    });

App::patch('/v1/migrations/:migrationId')
    ->groups(['api', 'migrations'])
    ->desc('Retry migration')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].retry')
    ->label('audits.event', 'migration.retry')
    ->label('audits.resource', 'migrations/{request.migrationId}')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'retry',
        description: '/docs/references/migrations/retry-migration.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_MIGRATION,
            )
        ]
    ))
    ->param('migrationId', '', new UID(), 'Migration unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('queueForMigrations')
    ->action(function (string $migrationId, Response $response, Database $dbForProject, Document $project, Document $user, Migration $queueForMigrations) {
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
        $queueForMigrations
            ->setMigration($migration)
            ->setProject($project)
            ->setUser($user)
            ->trigger();

        $response->noContent();
    });

App::delete('/v1/migrations/:migrationId')
    ->groups(['api', 'migrations'])
    ->desc('Delete migration')
    ->label('scope', 'migrations.write')
    ->label('event', 'migrations.[migrationId].delete')
    ->label('audits.event', 'migrationId.delete')
    ->label('audits.resource', 'migrations/{request.migrationId}')
    ->label('sdk', new Method(
        namespace: 'migrations',
        name: 'delete',
        description: '/docs/references/migrations/delete-migration.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('migrationId', '', new UID(), 'Migration ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $migrationId, Response $response, Database $dbForProject, Event $queueForEvents) {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('migrations', $migration->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove migration from DB');
        }

        $queueForEvents->setParam('migrationId', $migration->getId());

        $response->noContent();
    });
