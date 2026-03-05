<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\DSN\DSN;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDatabase';
    }

    protected function getDatabaseDSN(Document $project): string
    {
        // TODO: use database worker for for creating the v2 schema if not present
        // it is considered that the v2 metadata schema is already created during server start in the http.php
        return $this->constructDatabaseDSNFromProjectDatabase($this->getDatabaseType(), $project->getAttribute('region'), $project->getAttribute('database'));
    }

    private function constructDatabaseDSNFromProjectDatabase(string $databasetype, $region, ?string $dsn = null): string
    {
        $databases = [];
        $databaseKeys = [];
        /**
         * @var string|null $databaseOverride
        */
        $databaseOverride = '';
        $dbScheme = '';
        $sharedTables = [];
        $sharedTablesV1 = [];
        $sharedTablesV2 = [];

        switch ($databasetype) {
            case DOCUMENTSDB:
                $databases = Config::getParam('pools-documentsdb', []);
                $databaseKeys = System::getEnv('_APP_DATABASE_DOCUMENTSDB_KEYS', '');
                $databaseOverride = System::getEnv('_APP_DATABASE_DOCUMENTSDB_OVERRIDE');
                $dbScheme = System::getEnv('_APP_DB_HOST_DOCUMENTSDB', 'mongodb');
                $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES', ''));
                $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES_V1', ''));
                break;
            case VECTORDB:
                $databases = Config::getParam('pools-vectordb', []);
                $databaseKeys = System::getEnv('_APP_DATABASE_VECTORDB_KEYS', '');
                $databaseOverride = System::getEnv('_APP_DATABASE_VECTORDB_OVERRIDE');
                $dbScheme = System::getEnv('_APP_DB_HOST_VECTORDB', 'postgresql');
                $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_VECTORDB_SHARED_TABLES', ''));
                $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_VECTORDB_SHARED_TABLES_V1', ''));
                break;
            default:
                // legacy/tablesdb
                // it is already created during create project
                return $dsn;
        }

        $isSharedTablesV1 = false;
        $isSharedTablesV2 = false;

        if (!empty($dsn)) {
            try {
                $parsedDsn = new DSN($dsn);
                $dsnHost = $parsedDsn->getHost();
            } catch (\InvalidArgumentException) {
                $dsnHost = $dsn;
            }

            $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
            $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES_V1', ''));
            $sharedTablesV2 = \array_diff($sharedTables, $sharedTablesV1);
            $isSharedTablesV1 = \in_array($dsnHost, $sharedTablesV1);
            $isSharedTablesV2 = \in_array($dsnHost, $sharedTablesV2);
        }

        if ($region !== 'default') {
            $keys = explode(',', $databaseKeys);
            $databases = array_filter($keys, function ($value) use ($region) {
                return str_contains($value, $region);
            });
        }
        $sharedTablesV2 = \array_diff($sharedTables, $sharedTablesV1);

        $index = \array_search($databaseOverride, $databases);
        if ($index !== false) {
            $selectedDsn = $databases[$index];
        } else {
            if (!empty($dsn)) {
                if ($isSharedTablesV1) {
                    $databases = array_filter($databases, fn ($value) => \in_array($value, $sharedTablesV1));
                } elseif ($isSharedTablesV2) {
                    $databases = array_filter($databases, fn ($value) => \in_array($value, $sharedTablesV2));
                } else {
                    $databases = array_filter($databases, fn ($value) => !\in_array($value, $sharedTables));
                }
            }
            $selectedDsn = !empty($databases) ? $databases[array_rand($databases)] : '';
        }

        if (\in_array($selectedDsn, $sharedTables)) {
            $schema = 'appwrite';
            $database = 'appwrite';
            $namespace = System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', '');
            $selectedDsn = $schema . '://' . $selectedDsn . '?database=' . $database;

            if (!empty($namespace)) {
                $selectedDsn .= '&namespace=' . $namespace;
            }
        }
        try {
            new DSN($selectedDsn);
        } catch (\InvalidArgumentException) {
            $selectedDsn = $dbScheme.'://' . $selectedDsn;
        }
        return $selectedDsn;
    }

    protected function getDatabaseCollection()
    {
        return match ($this->getDatabaseType()) {
            'vectordb' => (Config::getParam('collections', [])['vectordb'] ?? [])['collections'] ?? [],
            default => (Config::getParam('collections', [])['databases'] ?? [])['collections'] ?? [],
        };
    }
    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases')
            ->desc('Create database')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].create')
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'database.create')
            ->label('audits.resource', 'database/{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: 'databases',
                    name: 'create',
                    description: '/docs/references/databases/create.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: UtopiaResponse::MODEL_DATABASE,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.create',
                    )
                )
            ])
            ->param('databaseId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
            ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $name, bool $enabled, Document $project, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents): void
    {
        $databaseId = $databaseId == 'unique()' ? ID::unique() : $databaseId;

        try {
            $dbForProject->createDocument('databases', new Document([
                '$id' => $databaseId,
                'name' => $name,
                'enabled' => $enabled,
                'search' => implode(' ', [$databaseId, $name]),
                'type' => $this->getDatabaseType(),
                'database' => $this->getDatabaseDSN($project)
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS, params: [$databaseId]);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $database = $dbForProject->getDocument('databases', $databaseId);

        $collections = $this->getDatabaseCollection();
        if (empty($collections)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The "collections" collection is not configured.');
        }

        $attributes = [];
        foreach ($collections['attributes'] as $attribute) {
            $attributes[] = new Document($attribute);
        }

        $indexes = [];
        foreach ($collections['indexes'] as $index) {
            $indexes[] = new Document($index);
        }

        try {
            $dbForProject->createCollection('database_' . $database->getSequence(), $attributes, $indexes);
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS, params: [$databaseId]);
        } catch (IndexException $e) {
            throw new Exception(Exception::INDEX_INVALID);
        } catch (LimitException) {
            // TODO: @Jake, how do we handle this collection/table?
            // there's no context awareness at this level on what the api is.
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED, params: [$databaseId]);
        }

        $queueForEvents->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($database, UtopiaResponse::MODEL_DATABASE);
    }
}
