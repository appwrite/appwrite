<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class V12 extends Migration
{
    /**
     * @var \PDO $pdo
     */
    private $pdo;

    public function execute(): void
    {
        global $register;
        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');

        $this->pdo = $register->get('db');

        Console::info('Migrating Project Schemas');
        $this->migrateProjectSchema($this->project->getId());

        /**
         * Switch to migrated Console Project
         */
        if ($this->project->getId() === 'console') {
            $this->consoleDB->setNamespace('_console');
            $this->projectDB->setNamespace('_console');
        }

        Console::info('Migrating Permissions');
        $this->fixPermissions();
        Console::info('Migrating Collections');
        $this->migrateCustomCollections();
        $this->fixCollections();
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate Project Tables.
     *
     * @param string $projectId
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    private function migrateProjectSchema(string $projectId): void
    {
        /**
         * Remove empty generated Console Project.
         */
        if ($this->consoleDB->getNamespace() === '_project_console' && $projectId === 'console') {
            $all = ['_console_bucket_1', '_console_bucket_1_perms'];
            foreach ($this->collections as $collection) {
                $all[] = "_{$projectId}_{$collection['$id']}";
                $all[] = "_{$projectId}_{$collection['$id']}_perms";
            }
            $this->pdo->prepare('DROP TABLE IF EXISTS ' . implode(', ', $all) . ';')->execute();
        } elseif ($this->projectDB->getNamespace() === '_console') {
            return;
        }

        /**
         * Rename Database Tables.
         */
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            /**
             * Skip new tables that don't exists on old schema.
             */
            if (in_array($id, ['buckets', 'deployments', 'builds'])) {
                continue;
            }

            $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_project_{$projectId}_{$id}` RENAME TO `_{$projectId}_{$id}`")->execute();
            $this->pdo->prepare("CREATE TABLE IF NOT EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$projectId}_{$id}_perms` (
                `_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `_type` VARCHAR(12) NOT NULL,
                `_permission` VARCHAR(255) NOT NULL,
                `_document` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`_id`),
                UNIQUE INDEX `_index1` (`_type`,`_document`,`_permission`),
                INDEX `_index2` (`_permission`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")->execute();
        }
    }

    /**
     * Migrate all Collection Structure.
     *
     * @return void
     */
    protected function fixCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            /**
             * Skip new tables that don't exists on old schema.
             */
            if (in_array($id, ['buckets', 'deployments', 'builds'])) {
                continue;
            }
            Console::log("- {$id}");
            switch ($id) {
                case 'sessions':
                    try {
                        /**
                         * Rename providerToken to providerAccessToken
                         */
                        $this->projectDB->renameAttribute($id, 'providerToken', 'providerAccessToken');
                    } catch (\Throwable $th) {
                        Console::warning("'providerAccessToken' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create providerRefreshToken
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'providerRefreshToken', type: Database::VAR_STRING, size: 16384, signed: true, required: false, filters: ['encrypt']);
                    } catch (\Throwable $th) {
                        Console::warning("'providerRefreshToken' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create providerAccessTokenExpiry
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'providerAccessTokenExpiry', type: Database::VAR_INTEGER, size: 0, required: false);
                    } catch (\Throwable $th) {
                        Console::warning("'providerAccessTokenExpiry' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'memberships':
                    try {
                        /**
                         * Add search attribute and index to memberships.
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'search', type: Database::VAR_STRING, size: 16384, required: false);
                        $this->projectDB->createIndex(collection: $id, id: '_key_search', type: Database::INDEX_FULLTEXT, attributes: ['search']);
                    } catch (\Throwable $th) {
                        Console::warning("'search' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'files':
                    /**
                     * Create bucket table if not exists.
                     */
                    $this->createCollection('buckets');

                    if (!$this->projectDB->findOne('buckets', [Query::equal('$id', ['default'])])) {
                        $this->projectDB->createDocument('buckets', new Document([
                            '$id' => ID::custom('default'),
                            '$collection' => ID::custom('buckets'),
                            'dateCreated' => \time(),
                            'dateUpdated' => \time(),
                            'name' => 'Default',
                            'permission' => 'file',
                            'maximumFileSize' => (int) App::getEnv('_APP_STORAGE_LIMIT', 0), // 10MB
                            'allowedFileExtensions' => [],
                            'enabled' => true,
                            'encryption' => true,
                            'antivirus' => true,
                            '$read' => ['role:all'],
                            '$write' => ['role:all'],
                            'search' => 'buckets Default',
                        ]));
                        $this->createCollection('files', 'bucket_1');

                        /**
                         * Migrate all files to default Bucket.
                         */
                        $nextDocument = null;
                        do {
                            $queries = [Query::limit($this->limit)];
                            if ($nextDocument !== null) {
                                $queries[] = Query::cursorAfter($nextDocument);
                            }
                            $documents = $this->projectDB->find('files', $queries);
                            $count = count($documents);
                            \Co\run(function (array $documents) {
                                foreach ($documents as $document) {
                                    go(function (Document $document) {
                                        /**
                                         * Update File Path
                                         */
                                        $path = "/storage/uploads/app-{$this->project->getId()}";
                                        $new = str_replace($path, "{$path}/default", $document->getAttribute('path'));
                                        $document->setAttribute('path', $new);

                                        /**
                                         * Populate search string from Migration to 0.12.
                                         */
                                        if (empty($document->getAttribute('search'))) {
                                            $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                                        }

                                        /**
                                         * Set new values.
                                         */
                                        $document
                                            ->setAttribute('bucketId', 'default')
                                            ->setAttribute('chunksTotal', 1)
                                            ->setAttribute('chunksUploaded', 1);

                                        $this->projectDB->createDocument('bucket_1', $document);
                                    }, $document);
                                }
                            }, $documents);

                            if ($count !== $this->limit) {
                                $nextDocument = null;
                            } else {
                                $nextDocument = end($documents);
                                $nextDocument->setAttribute('$collection', 'files');
                            }
                        } while (!is_null($nextDocument));

                        /**
                         * Rename folder on volumes.
                         */
                        $path = "/storage/uploads/app-{$this->project->getId()}";

                        if (is_dir("{$path}/")) {
                            mkdir("/storage/uploads/app-{$this->project->getId()}/default");

                            foreach (new \DirectoryIterator($path) as $fileinfo) {
                                if ($fileinfo->isDir() && !$fileinfo->isDot() && $fileinfo->getFilename() !== 'default') {
                                    rename("{$path}/{$fileinfo->getFilename()}", "{$path}/default/{$fileinfo->getFilename()}");
                                }
                            }
                        }
                    }

                    break;

                case 'functions':
                    try {
                        /**
                         * Rename tag to deployment
                         */
                        $this->projectDB->renameAttribute($id, 'tag', 'deployment');
                    } catch (\Throwable $th) {
                        Console::warning("'deployment' from {$id}: {$th->getMessage()}");
                    }

                    /**
                     * Create deployments table if not exists.
                     */
                    $this->createCollection('deployments');

                    /**
                     * Create builds table if not exists.
                     */
                    $this->createCollection('builds');

                    break;

                case 'executions':
                    try {
                        /**
                         * Rename tag to deployment
                         */
                        $this->projectDB->renameAttribute($id, 'tagId', 'deploymentId');
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create statusCode
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'statusCode', type: Database::VAR_INTEGER, size: 0, required: false);
                    } catch (\Throwable $th) {
                        Console::warning("'statusCode' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'teams':
                    try {
                        /**
                         * Rename tag to deployment
                         */
                        $this->projectDB->renameAttribute($id, 'sum', 'total');
                    } catch (\Throwable $th) {
                        Console::warning("'total' from {$id}: {$th->getMessage()}");
                    }

                    break;
            }
            usleep(100000);
        }
    }

    /**
     * Migrates permissions to dedicated table.
     *
     * @param \Utopia\Database\Document $document
     * @param string $internalId
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function migratePermissionsToDedicatedTable(string $collection, Document $document): void
    {
        $sql = "SELECT _read, _write FROM `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_{$collection}` WHERE _uid = {$this->pdo->quote($document->getid())}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $permissions = $stmt->fetch();

        $read  = json_decode($permissions['_read'] ?? null) ?? [];
        $write = json_decode($permissions['_write'] ?? null) ?? [];

        $permissions = [];
        foreach ($read as $permission) {
            $permissions[] = "('read', '{$permission}', '{$document->getId()}')";
        }

        foreach ($write as $permission) {
            $permissions[] = "('write', '{$permission}', '{$document->getId()}')";
        }

        if (!empty($permissions)) {
            $queryPermissions = "INSERT IGNORE INTO `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_{$collection}_perms` (_type, _permission, _document) VALUES " . implode(', ', $permissions);
            $stmtPermissions = $this->pdo->prepare($queryPermissions);
            $stmtPermissions->execute();
        }
    }

    /**
     * Migrates all user's database collections.
     *
     * @return void
     * @throws \Exception
     */
    protected function migrateCustomCollections(): void
    {
        $nextCollection = null;

        do {
            $queries = [Query::limit($this->limit)];
            if ($nextCollection !== null) {
                $queries[] = Query::cursorAfter($nextCollection);
            }
            $documents = $this->projectDB->find('collections', $queries);
            $count = count($documents);

            \Co\run(function (array $documents) {
                foreach ($documents as $document) {
                    go(function (Document $collection) {
                        $id = $collection->getId();
                        $projectId = $this->project->getId();
                        $internalId = $collection->getInternalId();

                        if ($this->projectDB->exists(App::getEnv('_APP_DB_SCHEMA', 'appwrite'), "collection_{$internalId}")) {
                            return;
                        }
                        Console::log("- {$id} ({$collection->getAttribute('name')})");

                        /**
                         * Rename user's colletion table schema
                         */
                        $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_project_{$projectId}_collection_{$id}` RENAME TO `_{$projectId}_collection_{$internalId}`")->execute();
                        $this->pdo->prepare("CREATE TABLE IF NOT EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$projectId}_collection_{$internalId}_perms` (
                            `_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                            `_type` VARCHAR(12) NOT NULL,
                            `_permission` VARCHAR(255) NOT NULL,
                            `_document` VARCHAR(255) NOT NULL,
                            PRIMARY KEY (`_id`),
                            UNIQUE INDEX `_index1` (`_type`,`_document`,`_permission`),
                            INDEX `_index2` (`_permission`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")->execute();

                        /**
                         * Update metadata table.
                         */
                        $this->pdo->prepare("UPDATE `{$this->projectDB->getDefaultDatabase()}`.`_{$projectId}__metadata`
                            SET
                                _uid = 'collection_{$internalId}',
                                name = 'collection_{$internalId}'
                            WHERE _uid = 'collection_{$id}';
                        ")->execute();


                        $nextDocument = null;

                        do {
                            $queries = [Query::limit($this->limit)];
                            if ($nextDocument !== null) {
                                $queries[] = Query::cursorAfter($nextDocument);
                            }
                            $documents = $this->projectDB->find('collection_' . $internalId, $queries);
                            $count = count($documents);

                            foreach ($documents as $document) {
                                go(function (Document $document, string $internalId) {
                                    $this->migratePermissionsToDedicatedTable("collection_{$internalId}", $document);
                                }, $document, $internalId);
                            }

                            if ($count !== $this->limit) {
                                $nextDocument = null;
                            } else {
                                $nextDocument = end($documents);
                            }
                        } while (!is_null($nextDocument));

                        /**
                         * Remove _read and _write columns
                         */
                        $this->pdo->prepare("
                            ALTER TABLE `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_collection_{$internalId}`
                            DROP COLUMN _read,
                            DROP COLUMN _write
                        ")->execute();
                    }, $document);
                }
            }, $documents);

            if ($count !== $this->limit) {
                $nextCollection = null;
            } else {
                $nextCollection = end($documents);
            }
        } while (!is_null($nextCollection));
    }

    /**
     * Migrate all Permission to new System with dedicated Table.
     *
     * @return void
     * @throws \Exception
     */
    protected function fixPermissions()
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            /**
             * Skip new tables that don't exists on old schema.
             */
            if (in_array($id, ['buckets', 'deployments', 'builds'])) {
                continue;
            }
            /**
             * Check if permissions have already been migrated.
             */
            try {
                $stmtCheck = $this->pdo->prepare("SHOW COLUMNS from `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_{$id}` LIKE '_read'");
                $stmtCheck->execute();

                if (empty($stmtCheck->fetchAll())) {
                    continue;
                }
            } catch (\Throwable $th) {
                if ($th->getCode() === "42S02") {
                    continue;
                }
                throw $th;
            }


            Console::log("- {$collection['$id']}");
            $nextDocument = null;

            do {
                $queries = [Query::limit($this->limit)];
                if ($nextDocument !== null) {
                    $queries[] = Query::cursorAfter($nextDocument);
                }
                $documents = $this->projectDB->find($id, $queries);
                $count = count($documents);

                \Co\run(function (array $documents) {
                    foreach ($documents as $document) {
                        go(function (Document $document) {
                            $this->migratePermissionsToDedicatedTable($document->getCollection(), $document);
                        }, $document);
                    }
                }, $documents);

                if ($count !== $this->limit) {
                    $nextDocument = null;
                } else {
                    $nextDocument = end($documents);
                }
            } while (!is_null($nextDocument));

            /**
             * Remove _read and _write columns
             */
            $this->pdo->prepare("
                ALTER TABLE `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_{$id}`
                DROP COLUMN _read,
                DROP COLUMN _write
            ")->execute();
        }

        /**
         * Timeout to give MariaDB some room to breath
         */
        usleep(100000);
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
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '0.13.0');

                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'users':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'email', 'name'], $document));
                }

                break;

            case 'teams':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'functions':
                $document->setAttribute('deployment', null);

                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name', 'runtime'], $document));
                }

                break;

            case 'executions':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'functionId'], $document));
                }

                break;

            case 'memberships':
                /**
                 * Populate search string.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'userId'], $document));
                }

                break;

            case 'sessions':
                $document
                    ->setAttribute('providerRefreshToken', '')
                    ->setAttribute('providerAccessTokenExpiry', 0)
                    ->setAttribute('providerAccessToken', $document->getAttribute('providerToken', ''))
                    ->removeAttribute('providerToken');

                break;
        }

        return $document;
    }

    /**
     * Builds a search string for a fulltext index.
     *
     * @param array $values
     * @param Document $document
     * @return string
     */
    private function buildSearchAttribute(array $values, Document $document): string
    {
        $values = array_filter(array_map(fn (string $value) => $document->getAttribute($value) ?? '', $values));

        return implode(' ', $values);
    }
}
