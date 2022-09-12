<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Appwrite\OpenSSL\OpenSSL;
use Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class V15 extends Migration
{
    /**
     * @var \PDO $pdo
     */
    private $pdo;

    public function execute(): void
    {
        global $register;
        $this->pdo = $register->get('db');

        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subqueryVariables'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        Console::info('Migrating Collections');
        $this->migrateCollections();
        Console::info('Migrating Databases');
        $this->migrateDatabases();
        Console::info('Migrating Buckets');
        $this->migrateBuckets();
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
        Console::info("Clean up 'write' Permissions");
        foreach ($this->collections as $collection) {
            if ($collection['$collection'] === Database::METADATA) {
                $this->removeWritePermissions($collection['$id']);
            }
        }
    }

    /**
     * Migrating all Bucket tables.
     *
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function migrateBuckets(): void
    {
        foreach ($this->documentsIterator('buckets') as $bucket) {
            $bucketTable = "bucket_{$bucket->getInternalId()}";

            $this->createPermissionsColumn($bucketTable);
            $this->migrateDateTimeAttribute($bucketTable, '_createdAt');
            $this->migrateDateTimeAttribute($bucketTable, '_updatedAt');

            $this->populatePermissionsAttribute(
                document: $bucket,
                addCreatePermission: true
            );

            if (!is_null($bucket->getAttribute('permission'))) {
                $bucket->setAttribute('fileSecurity', $bucket->getAttribute('permissions') === 'document');
            }

            if (is_null($bucket->getAttribute('compression'))) {
                $bucket->setAttribute('compression', 'none');
            }

            $this->projectDB->updateDocument('buckets', $bucket->getId(), $bucket);

            Console::info("Migrating Files of {$bucket->getId()} ({$bucket->getAttribute('name')})");
            foreach ($this->documentsIterator($bucketTable) as $file) {
                $this->populatePermissionsAttribute(
                    document: $file,
                    table: $bucketTable,
                    addCreatePermission: false
                );
                $this->projectDB->updateDocument($bucketTable, $file->getId(), $file);
            }
            $this->removeWritePermissions($bucketTable);
        }

        try {
            $this->projectDB->deleteAttribute('buckets', 'permission');
        } catch (\Throwable $th) {
            Console::warning("'permissions' from buckets: {$th->getMessage()}");
        }
    }

    /**
     * Migrating all Database and Collection tables.
     *
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function migrateDatabases(): void
    {
        foreach ($this->documentsIterator('databases') as $database) {
            $databaseTable = "database_{$database->getInternalId()}";
            $this->createPermissionsColumn($databaseTable);
            $this->migrateDateTimeAttribute($databaseTable, '_createdAt');
            $this->migrateDateTimeAttribute($databaseTable, '_updatedAt');
            $this->populatePermissionsAttribute(
                document: $database,
                table: 'databases',
                addCreatePermission: false
            );

            $this->projectDB->updateDocument('databases', $database->getId(), $database);

            try {
                $this->createAttributeFromCollection($this->projectDB, $databaseTable, 'documentSecurity', 'collections');
            } catch (\Throwable $th) {
                Console::warning("'documentSecurity' from {$databaseTable}: {$th->getMessage()}");
            }

            Console::info("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");
            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";
                $this->createPermissionsColumn($collectionTable);
                $this->migrateDateTimeAttribute($collectionTable, '_createdAt');
                $this->migrateDateTimeAttribute($collectionTable, '_updatedAt');

                $this->populatePermissionsAttribute(
                    document: $collection,
                    table: $databaseTable,
                    addCreatePermission: true
                );

                if (!is_null($collection->getAttribute('permission'))) {
                    $collection->setAttribute('documentSecurity', $collection->getAttribute('permissions') === 'document');
                }

                $this->projectDB->updateDocument($databaseTable, $collection->getId(), $collection);

                Console::info("Migrating Documents of {$collection->getId()} ({$collection->getAttribute('name')})");
                $requiredAttributes = array_reduce($collection->getAttribute('attributes', []), function (array $carry, Document $item) {
                    if ($item->getAttribute('required', false)) {
                        $carry = array_merge($carry, [
                            $item->getAttribute('key') => $item->getAttribute('default')
                        ]);
                    }
                    return $carry;
                }, []);

                foreach ($this->documentsIterator($collectionTable) as $document) {
                    foreach ($document->getAttributes() as $attribute => $default) {
                        if (array_key_exists($attribute, $requiredAttributes)) {
                            if (is_null($default)) {
                                Console::warning("Skipping migration for Document {$document->getId()} in Collection {$collection->getId()} ({$collection->getAttribute('name')}) because of missing required attribute \"{$attribute}\" without default value.");

                                continue 2;
                            }
                            $document->setAttribute($attribute, $default);
                        }
                    }
                    $this->populatePermissionsAttribute(
                        document: $document,
                        table: $collectionTable,
                        addCreatePermission: false
                    );

                    $this->projectDB->updateDocument($collectionTable, $document->getId(), $document);
                }
                $this->removeWritePermissions($collectionTable);
            }
            $this->removeWritePermissions($databaseTable);

            try {
                $this->projectDB->deleteAttribute("database_{$database->getInternalId()}", 'permission');
            } catch (\Throwable $th) {
                Console::warning("'permission' from {$databaseTable}: {$th->getMessage()}");
            }
        }
    }

    /**
     * Removes all 'write' permissions from a table.
     *
     * @param string $table
     * @return void
     */
    protected function removeWritePermissions(string $table): void
    {
        try {
            $this->pdo->prepare("DELETE FROM `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}_perms` WHERE _type = 'write'")->execute();
        } catch (\Throwable $th) {
            Console::warning("Remove 'write' permissions from {$table}: {$th->getMessage()}");
        }
    }

    /**
     * Returns all columns from the Table.
     *
     * @param string $table
     * @return array
     * @throws \Exception
     * @throws \PDOException
     */
    protected function getSQLColumnTypes(string $table): array
    {
        $query = $this->pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '_{$this->project->getInternalId()}_{$table}' AND table_schema = '{$this->projectDB->getDefaultDatabase()}'");
        $query->execute();

        return array_reduce($query->fetchAll(), function (array $carry, array $item) {
            $carry[$item['COLUMN_NAME']] = $item['DATA_TYPE'];

            return $carry;
        }, []);
    }

    /**
     * Migrates all Integer colums for timestamps to DateTime.
     *
     * @return void
     * @throws \Exception
     */
    protected function migrateDateTimeAttribute(string $table, string $attribute): void
    {
        $columns = $this->getSQLColumnTypes($table);

        if ($columns[$attribute] === 'int') {
            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` MODIFY {$attribute} VARCHAR(64)")->execute();
                $this->pdo->prepare("UPDATE `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` SET {$attribute} = IF({$attribute} = 0, NULL, FROM_UNIXTIME({$attribute}))")->execute();
                $columns[$attribute] = 'varchar';
            } catch (\Throwable $th) {
                Console::warning($th->getMessage());
            }
        }

        if ($columns[$attribute] === 'varchar') {
            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` MODIFY {$attribute} DATETIME(3)")->execute();
            } catch (\Throwable $th) {
                Console::warning($th->getMessage());
            }
        }

        /**
         * Skip adding filter on internal attributes.
         */
        if (!str_starts_with($attribute, '_')) {
            try {
                /**
                 * Add datetime filter.
                 */
                $this->projectDB->updateAttributeFilters($table, ID::custom($attribute), ['datetime']);
                /**
                 * Change data type to DateTime.
                 */
                $this->projectDB->updateAttribute(
                    collection: $table,
                    id: $attribute,
                    type: Database::VAR_DATETIME,
                    signed: false
                );
            } catch (\Throwable $th) {
                Console::warning("Add 'datetime' filter to '{$attribute}' from {$table}: {$th->getMessage()}");
            }
        }

        $this->projectDB->deleteCachedCollection($table);
    }

    /**
     * Create the '_permissions' column to a table.
     *
     * @param string $table
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function createPermissionsColumn(string $table): void
    {
        $columns = $this->getSQLColumnTypes($table);

        if (!array_key_exists('_permissions', $columns)) {
            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}` ADD `_permissions` MEDIUMTEXT DEFAULT NULL")->execute();
            } catch (\Throwable $th) {
                Console::warning("Add '_permissions' column to '{$table}': {$th->getMessage()}");
            }
        }
    }

    /**
     * Populate '$permissions' from '$read' and '$write'.
     *
     * @param \Utopia\Database\Document $document
     * @param null|string $table
     * @param bool $addCreatePermission
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function populatePermissionsAttribute(Document &$document, ?string $table = null, bool $addCreatePermission = true): void
    {
        $table ??= $document->getCollection();

        $query = $this->pdo->prepare("SELECT * FROM `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$table}_perms` WHERE _document = '{$document->getId()}'");
        $query->execute();
        $results = $query->fetchAll();
        $permissions = [];

        foreach ($results as $result) {
            $type = $result['_type'];
            $permission = $this->migratePermission($result['_permission']);

            if ($type === 'write') {
                /**
                 * Migrate write permissions from 'role:all' to 'role:member'.
                 */
                if ($permission === 'role:all') {
                    $permission = 'role:member';
                }

                $permissions[] = "update(\"{$permission}\")";
                $permissions[] = "delete(\"{$permission}\")";
                if ($addCreatePermission) {
                    $permissions[] = "create(\"{$permission}\")";
                }
            } else {
                $permissions[] = "{$type}(\"{$permission}\")";
            }
        }

        $document->setAttribute('$permissions', $permissions);
    }

    /**
     * Migrates a permission string
     *
     * @param string $permission
     * @return string
     */
    protected function migratePermission(string $permission): string
    {
        return match ($permission) {
            'role:all' => 'any',
            'role:guest' => 'guests',
            default => $permission
        };
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    protected function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case '_metadata':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    Console::log('Created new Collection "cache" collection');
                    $this->createCollection('cache');
                    Console::log('Created new Collection "variables" collection');
                    $this->createCollection('variables');
                    $this->projectDB->deleteCachedCollection($id);
                    break;

                case 'abuse':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;

                case 'attributes':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;

                case 'audit':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;

                case 'buckets':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    try {
                        /**
                         * Create 'compression' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'compression');
                    } catch (\Throwable $th) {
                        Console::warning("'compression' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'fileSecurity' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'fileSecurity');
                    } catch (\Throwable $th) {
                        Console::warning("'fileSecurity' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_enabled' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_enabled');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_enabled' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_name' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_name');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_name' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_fileSecurity' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_fileSecurity');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_fileSecurity' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_maximumFileSize' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_maximumFileSize');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_maximumFileSize' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_encryption' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_encryption');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_encryption' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_antivirus' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_antivirus');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_antivirus' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'builds':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'startTime');
                    $this->migrateDateTimeAttribute($id, 'endTime');
                    break;

                case 'certificates':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'issueDate');
                    $this->migrateDateTimeAttribute($id, 'renewDate');
                    $this->migrateDateTimeAttribute($id, 'updated');
                    break;

                case 'databases':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;

                case 'deployments':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    try {
                        /**
                         * Create '_key_entrypoint' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_entrypoint');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_entrypoint' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_size' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_size');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_size' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_buildId' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_buildId');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_buildId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_activate' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_activate');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_activate' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'domains':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'updated');

                    break;

                case 'executions':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    try {
                        /**
                         * Create 'stdout' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'stdout');
                    } catch (\Throwable $th) {
                        Console::warning("'stdout' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Rename 'time' to 'duration'
                         */
                        $this->projectDB->renameAttribute($id, 'time', 'duration');
                    } catch (\Throwable $th) {
                        Console::warning("'duration' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_trigger' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_trigger');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_trigger' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_status' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_status');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_status' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_statusCode' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_statusCode');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_statusCode' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_duration' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_duration');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_duration' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'functions':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'scheduleNext');
                    $this->migrateDateTimeAttribute($id, 'schedulePrevious');

                    /**
                     * Migrate function variables into a new table.
                     */
                    Console::log("Migrating Collection \"{$id}\" Variables");

                    foreach ($this->documentsIterator('functions') as $function) {
                        foreach ($function->getAttribute('vars', []) as $key => $value) {
                            if ($value instanceof Document) {
                                continue;
                            }
                            $variableId = ID::unique();
                            $variable = new Document([
                                '$id' => $variableId,
                                '$permissions' => [
                                    Permission::read(Role::any()),
                                    Permission::update(Role::any()),
                                    Permission::delete(Role::any()),
                                ],
                                'functionId' => $function->getId(),
                                'functionInternalId' => $function->getInternalId(),
                                'key' => $key,
                                'value' => $value,
                                'search' => implode(' ', [$variableId, $key, $function->getId()])
                            ]);
                            $this->projectDB->createDocument('variables', $variable);
                        }
                        $this->projectDB->deleteAttribute('functions', 'vars');
                        $this->createAttributeFromCollection($this->projectDB, 'functions', 'vars');
                    }

                    try {
                        /**
                         * Create '_key_name' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_name');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_name' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_status' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_status');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_status' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_runtime' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_runtime');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_runtime' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_deployment' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_deployment');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_deployment' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_schedule' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_schedule');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_schedule' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_scheduleNext' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_scheduleNext');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_scheduleNext' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_schedulePrevious' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_schedulePrevious');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_schedulePrevious' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_timeout' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_timeout');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_timeout' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'indexes':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    break;

                case 'keys':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'expire');

                    try {
                        /**
                         * Update 'expire' default value
                         */
                        $this->projectDB->updateAttributeDefault('keys', 'expire', null);
                    } catch (\Throwable $th) {
                        Console::warning("'expire' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'accessedAt' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'sdks' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'sdks');
                    } catch (\Throwable $th) {
                        Console::warning("'sdks' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_accessedAt' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'memberships':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'invited');
                    $this->migrateDateTimeAttribute($id, 'joined');

                    try {
                        /**
                         * Create '_key_userId' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_userId');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_userId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_teamId' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_teamId');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_teamId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_invited' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_invited');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_invited' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_joined' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_joined');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_joined' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_confirm' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_confirm');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_confirm' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'platforms':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    break;

                case 'projects':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    try {
                        /**
                         * Create '_key_name' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_name');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_name' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'realtime':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'timestamp');

                    break;

                case 'sessions':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'expire');
                    $this->migrateDateTimeAttribute($id, 'providerAccessTokenExpiry');

                    break;

                case 'stats':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'time');

                    try {
                        /**
                         * Re-Create '_key_metric' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_metric');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_period_time');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_period_time' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Re-Create '_key_metric_period' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_metric_period');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_metric_period_time');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_metric_period_time' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'teams':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');

                    try {
                        /**
                         * Create '_key_name' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_name');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_name' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_total' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_total');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_total' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'tokens':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'expire');

                    break;

                case 'users':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    $this->migrateDateTimeAttribute($id, 'registration');
                    $this->migrateDateTimeAttribute($id, 'passwordUpdate');

                    try {
                        /**
                         * Create 'hash' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'hash');
                    } catch (\Throwable $th) {
                        Console::warning("'hash' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'hashOptions' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'hashOptions');
                    } catch (\Throwable $th) {
                        Console::warning("'hashOptions' from {$id}: {$th->getMessage()}");
                    }

                    /**
                     * Update user password before adding encrypt filter.
                     */
                    Console::log("Migrating Collection \"{$id}\" Passwords");

                    foreach ($this->documentsIterator('users') as $user) {
                        /**
                         * Skip when no password.
                         */
                        if (is_null($user->getAttribute('password'))) {
                            continue;
                        }
                        /**
                         * Skip when password is JSON.
                         */
                        json_decode($user->getAttribute('password'));
                        if (json_last_error() === JSON_ERROR_NONE) {
                            continue;
                        }

                        /**
                         * Add default hash.
                         */
                        $user->setAttribute('hash', 'bcrypt');

                        /**
                         * Add default hash options.
                         */
                        $user->setAttribute('hashOptions', json_encode(['cost' => 8]));

                        /**
                         * Encrypt hashed password.
                         */
                        $user->setAttribute('password', $this->encryptFilter($user->getAttribute('password')));

                        /**
                         * Migrate permissions.
                         */
                        $this->populatePermissionsAttribute($user, addCreatePermission: false);

                        $this->projectDB->updateDocument('users', $user->getId(), $user);
                    }

                    try {
                        /**
                         * Add datetime filter to password.
                         */
                        $this->projectDB->updateAttributeFilters($id, 'password', ['encrypt']);
                    } catch (\Throwable $th) {
                        Console::warning("Add 'encrypt' filter to 'password' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_name' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_name');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_name' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_status' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_status');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_status' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_passwordUpdate' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_passwordUpdate');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_passwordUpdate' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_registration' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_registration');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_registration' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_emailVerification' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_emailVerification');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_emailVerification' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_phoneVerification' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_phoneVerification');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_phoneVerification' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'webhooks':
                    $this->createPermissionsColumn($id);
                    $this->migrateDateTimeAttribute($id, '_createdAt');
                    $this->migrateDateTimeAttribute($id, '_updatedAt');
                    break;

                default:
                    break;
            }

            usleep(50000);
        }
    }

    /**
     * Fix run on each document
     *
     * @param \Utopia\Database\Document $document
     * @return \Utopia\Database\Document
     */
    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            case 'cache':
            case 'variables':
            case 'users':
                /**
                 * skipping migration for 'cache' and 'variables'.
                 * 'users' already migrated.
                 */
                return null;

            case '_metadata':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'abuse':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'attributes':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'audit':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'buckets':
                /**
                 * Populate permissions attribute.
                 *
                 * Note: Buckets need to migrate 'create' permissions.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'builds':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'certificates':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'databases':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'deployments':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'domains':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'executions':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'functions':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                /**
                 * Migrate execute permissions.
                 */
                $document->setAttribute('execute', array_map(
                    fn ($p) => $this->migratePermission($p),
                    $document->getAttribute('execute', [])
                ));

                break;

            case 'indexes':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'keys':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'memberships':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'platforms':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'projects':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.0.0-RC1');
                break;

            case 'realtime':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'sessions':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'stats':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'teams':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'tokens':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'users':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;

            case 'webhooks':
                /**
                 * Populate permissions attribute.
                 */
                $this->populatePermissionsAttribute($document, addCreatePermission: false);

                break;
        }

        return $document;
    }

    /**
     * Filter from the 'encrypt' filter.
     *
     * @param string $value
     * @return string|false
     */
    protected function encryptFilter(string $value): string
    {
        $key = App::getEnv('_APP_OPENSSL_KEY_V1');
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;

        return json_encode([
            'data' => OpenSSL::encrypt($value, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
            'method' => OpenSSL::CIPHER_AES_128_GCM,
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag ?? ''),
            'version' => '1',
        ]);
    }
}
