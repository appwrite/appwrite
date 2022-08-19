<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class V14 extends Migration
{
    /**
     * @var \PDO $pdo
     */
    private $pdo;

    public function execute(): void
    {
        global $register;
        $this->pdo = $register->get('db');

        if ($this->project->getId() === 'console' && $this->project->getInternalId() !== 'console') {
            return;
        }

        /**
         * Disable SubQueries for Speed.
         */
        foreach (['subQueryAttributes', 'subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships'] as $name) {
            Database::addFilter($name, fn () => null, fn () => []);
        }

        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        Console::info('Migrating Collections');
        $this->migrateCollections();
        Console::info('Create Default Database Layer');
        $this->createDatabaseLayer();
        if ($this->project->getId() !== 'console') {
            Console::info('Migrating Database Collections');
            $this->migrateCustomCollections();
        }
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Creates the default Database for existing Projects.
     *
     * @return void
     * @throws \Throwable
     */
    public function createDatabaseLayer(): void
    {
        try {
            if (!$this->projectDB->exists('databases')) {
                $this->createCollection('databases');
            }
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }

        if ($this->project->getInternalId() === 'console') {
            return;
        }

        try {
            $this->projectDB->createDocument('databases', new Document([
                '$id' => ID::custom('default'),
                'name' => 'Default',
                'search' => 'default Default'
            ]));
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }
    }

    /**
     * Migrates all Files.
     *
     * @param \Utopia\Database\Document $bucket
     * @return void
     * @throws \Exception
     */
    protected function migrateBucketFiles(Document $bucket): void
    {
        $nextFile = null;
        do {
            $queries = [Query::limit($this->limit)];
            if ($nextFile !== null) {
                $queries[] = Query::cursorAfter($nextFile);
            }
            $documents = $this->projectDB->find("bucket_{$bucket->getInternalId()}", $queries);
            $count = count($documents);

            foreach ($documents as $document) {
                go(function (Document $bucket, Document $document) {
                    Console::log("Migrating File {$document->getId()}");
                    try {
                        /**
                         * Migrate $createdAt.
                         */
                        if (empty($document->getCreatedAt())) {
                            $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                            $this->projectDB->updateDocument("bucket_{$bucket->getInternalId()}", $document->getId(), $document);
                        }
                    } catch (\Throwable $th) {
                        Console::warning($th->getMessage());
                    }
                }, $bucket, $document);
            }

            if ($count !== $this->limit) {
                $nextFile = null;
            } else {
                $nextFile = end($documents);
            }
        } while (!is_null($nextFile));
    }

    /**
     * Migrates all Database Collections.
     * @return void
     * @throws \Exception
     */
    protected function migrateCustomCollections(): void
    {
        try {
            $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_collections` RENAME TO `_{$this->project->getInternalId()}_database_1`")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }
        try {
            $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_collections_perms` RENAME TO `_{$this->project->getInternalId()}_database_1_perms`")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }

        /**
         * Update metadata table.
         */
        try {
            $this->pdo->prepare("UPDATE `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}__metadata`
                SET
                    _uid = 'database_1',
                    name = 'database_1'
                WHERE _uid = 'collections';
            ")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }

        try {
            /**
             * Add Database ID for Collections.
             */
            $this->createAttributeFromCollection($this->projectDB, 'database_1', 'databaseId', 'collections');

            /**
             * Add Database Internal ID for Collections.
             */
            $this->createAttributeFromCollection($this->projectDB, 'database_1', 'databaseInternalId', 'collections');
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }

        $nextCollection = null;

        do {
            $queries = [Query::limit($this->limit)];
            if ($nextCollection !== null) {
                $queries[] = Query::cursorAfter($nextCollection);
            }
            $documents = $this->projectDB->find('database_1', $queries);
            $count = count($documents);

            \Co\run(function (array $documents) {
                foreach ($documents as $document) {
                    go(function (Document $collection) {
                        $id = $collection->getId();
                        $internalId = $collection->getInternalId();

                        Console::log("- {$id} ({$collection->getAttribute('name')})");

                        try {
                            /**
                             * Rename user's colletion table schema
                             */
                            $this->createNewMetaData("collection_{$internalId}", "database_1_collection_{$internalId}");
                        } catch (\Throwable $th) {
                            Console::warning($th->getMessage());
                        }

                        try {
                            /**
                             * Update metadata table.
                             */
                            $this->pdo->prepare("UPDATE `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}__metadata`
                                SET
                                    _uid = 'database_1_collection_{$internalId}',
                                    name = 'database_1_collection_{$internalId}'
                                WHERE _uid = 'collection_{$internalId}';
                            ")->execute();
                        } catch (\Throwable $th) {
                            Console::warning($th->getMessage());
                        }

                        try {
                            /**
                             * Update internal ID's.
                             */
                            $collection
                                ->setAttribute('databaseId', 'default')
                                ->setAttribute('databaseInternalId', '1');
                            $this->projectDB->updateDocument('database_1', $collection->getId(), $collection);
                        } catch (\Throwable $th) {
                            Console::warning($th->getMessage());
                        }
                        /**
                         * Migrate Attributes
                         */
                        $this->migrateAttributesAndCollections('attributes', $collection);
                        /**
                         * Migrate Indexes
                         */
                        $this->migrateAttributesAndCollections('indexes', $collection);
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

    protected function migrateAttributesAndCollections(string $type, Document $collection): void
    {
        /**
         * Offset pagination instead of cursor, since documents are re-created!
         */
        $offset = 0;
        $attributesCount = $this->projectDB->count($type, queries: [Query::equal('collectionId', [$collection->getId()])]);

        do {
            $queries = [
                Query::limit($this->limit),
                Query::offset($offset),
                Query::equal('collectionId', [$collection->getId()]),
            ];
            $documents = $this->projectDB->find($type, $queries);
            $offset += $this->limit;

            foreach ($documents as $document) {
                go(function (Document $document, string $internalId, string $type) {
                    try {
                        /**
                         * Skip already migrated Documents.
                         */
                        if (!is_null($document->getAttribute('databaseId'))) {
                            return;
                        }
                        /**
                         * Add Internal ID 'collectionInternalId' for Subqueries.
                         */
                        $document->setAttribute('collectionInternalId', $internalId);
                        /**
                         * Add Internal ID 'databaseInternalId' for Subqueries.
                         */
                        $document->setAttribute('databaseInternalId', '1');
                        /**
                         * Add Internal ID 'databaseId'.
                         */
                        $document->setAttribute('databaseId', 'default');

                        /**
                         * Re-create Attribute.
                         */
                        $this->projectDB->deleteDocument($document->getCollection(), $document->getId());
                        $this->projectDB->createDocument($document->getCollection(), $document->setAttribute('$id', "1_{$internalId}_{$document->getAttribute('key')}"));
                    } catch (\Throwable $th) {
                        Console::error("Failed to {$type} document: " . $th->getMessage());
                    }
                }, $document, $collection->getInternalId(), $type);
            }
        } while ($offset < $attributesCount);
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

            Console::log("- {$id}");

            $this->createNewMetaData($id);

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case 'attributes':
                case 'indexes':
                    try {
                        /**
                         * Create 'databaseInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'databaseId');
                    } catch (\Throwable $th) {
                        Console::warning("'databaseInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'databaseInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'databaseInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'databaseInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'collectionInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'collectionInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Re-Create '_key_collection' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_collection');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_db_collection');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_collection' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'projects':
                    try {
                        /**
                         * Create 'teamInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'teamInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'platforms':
                case 'domains':
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'keys':
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'expire' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'expire');
                    } catch (\Throwable $th) {
                        Console::warning("'expire' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'webhooks':
                    try {
                        /**
                         * Create 'signatureKey' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'signatureKey');
                    } catch (\Throwable $th) {
                        Console::warning("'signatureKey' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'users':
                    try {
                        /**
                         * Create 'phone' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'phone');
                    } catch (\Throwable $th) {
                        Console::warning("'phone' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'phoneVerification' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'phoneVerification');
                    } catch (\Throwable $th) {
                        Console::warning("'phoneVerification' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create '_key_phone' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_phone');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_phone' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'tokens':
                case 'sessions':
                    try {
                        /**
                         * Create 'userInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'userInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_user' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_user');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_user');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_user' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'memberships':
                    try {
                        /**
                         * Create 'teamInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'teamInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'userInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'userInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_unique' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_unique');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_unique');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_unique' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_team' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_team');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_team');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_team' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_user' index
                         */
                        @$this->projectDB->deleteIndex($id, '_key_user');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_user');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_user' from {$id}: {$th->getMessage()}");
                    }
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
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '0.15.0');

                if (!empty($document->getAttribute('teamId')) && is_null($document->getAttribute('teamInternalId'))) {
                    $internalId = $this->projectDB->getDocument('teams', $document->getAttribute('teamId'))->getInternalId();
                    $document->setAttribute('teamInternalId', $internalId);
                }

                break;
            case 'keys':
                /**
                 * Add new 'expire' attribute and default to never (0).
                 */
                if (is_null($document->getAttribute('expire'))) {
                    $document->setAttribute('expire', 0);
                }
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'audit':
                /**
                 * Add Database Layer to collection resource.
                 */
                if (str_starts_with($document->getAttribute('resource'), 'collection/')) {
                    $document
                        ->setAttribute('resource', "database/default/{$document->getAttribute('resource')}")
                        ->setAttribute('event', "databases.default.{$document->getAttribute('event')}");
                }

                if (str_starts_with($document->getAttribute('resource'), 'document/')) {
                    $collectionId = explode('.', $document->getAttribute('event'))[1];
                    $document
                        ->setAttribute('resource', "database/default/collection/{$collectionId}/{$document->getAttribute('resource')}")
                        ->setAttribute('event', "databases.default.{$document->getAttribute('event')}");
                }

                break;
            case 'stats':
                /**
                 * Add Database Layer to stats metric.
                 */
                if (str_starts_with($document->getAttribute('metric'), 'database.')) {
                    $metric = ltrim($document->getAttribute('metric'), 'database.');
                    $document->setAttribute('metric', "databases.default.{$metric}");
                }

                break;
            case 'webhooks':
                /**
                 * Add new 'signatureKey' attribute and generate a random value.
                 */
                if (empty($document->getAttribute('signatureKey'))) {
                    $document->setAttribute('signatureKey', \bin2hex(\random_bytes(64)));
                }
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'domains':
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'tokens':
            case 'sessions':
                /**
                 * Add Internal ID 'userId' for Subqueries.
                 */
                if (!empty($document->getAttribute('userId')) && is_null($document->getAttribute('userInternalId'))) {
                    $internalId = $this->projectDB->getDocument('users', $document->getAttribute('userId'))->getInternalId();
                    $document->setAttribute('userInternalId', $internalId);
                }

                break;
            case 'memberships':
                /**
                 * Add Internal ID 'userId' for Subqueries.
                 */
                if (!empty($document->getAttribute('userId')) && is_null($document->getAttribute('userInternalId'))) {
                    $internalId = $this->projectDB->getDocument('users', $document->getAttribute('userId'))->getInternalId();
                    $document->setAttribute('userInternalId', $internalId);
                }
                /**
                 * Add Internal ID 'teamId' for Subqueries.
                 */
                if (!empty($document->getAttribute('teamId')) && is_null($document->getAttribute('teamInternalId'))) {
                    $internalId = $this->projectDB->getDocument('teams', $document->getAttribute('teamId'))->getInternalId();
                    $document->setAttribute('teamInternalId', $internalId);
                }

                break;
            case 'platforms':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                /**
                 * Migrate dateUpdated to $updatedAt.
                 */
                if (empty($document->getUpdatedAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }
                /**
                 * Add Internal ID 'projectId' for Subqueries.
                 */
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'buckets':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                /**
                 * Migrate dateUpdated to $updatedAt.
                 */
                if (empty($document->getUpdatedAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                /**
                 * Migrate all Storage Buckets to use Internal ID.
                 */
                $internalId = $this->projectDB->getDocument('buckets', $document->getId())->getInternalId();
                $this->createNewMetaData("bucket_{$internalId}");

                /**
                 * Migrate all Storage Bucket Files.
                 */
                $this->migrateBucketFiles($document);

                break;
            case 'users':
                /**
                 * Set 'phoneVerification' to false if not set.
                 */
                if (is_null($document->getAttribute('phoneVerification'))) {
                    $document->setAttribute('phoneVerification', false);
                }

                break;
            case 'functions':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                /**
                 * Migrate dateUpdated to $updatedAt.
                 */
                if (empty($document->getUpdatedAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                break;
            case 'deployments':
            case 'executions':
            case 'teams':
                /**
                 * Migrate dateCreated to $createdAt.
                 */
                if (empty($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }

                break;
        }

        return $document;
    }

    /**
     * Creates new metadata that was introduced for a collection and enforces the Internal ID.
     *
     * @param string $id
     * @return void
     */
    protected function createNewMetaData(string $id, string $to = null): void
    {
        $to ??= $id;
        /**
         * Skip files collection.
         */
        if (in_array($id, ['files', 'databases'])) {
            return;
        }

        try {
            /**
             * Replace project UID with Internal ID.
             */
            $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getId()}_{$id}` RENAME TO `_{$this->project->getInternalId()}_{$to}`")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
        try {
            /**
             * Replace project UID with Internal ID on permissions table.
             */
            $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getId()}_{$id}_perms` RENAME TO `_{$this->project->getInternalId()}_{$to}_perms`")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
        try {
            /**
             * Add _createdAt attribute.
             */
            $this->pdo->prepare("ALTER TABLE `_{$this->project->getInternalId()}_{$to}` ADD COLUMN IF NOT EXISTS `_createdAt` int unsigned DEFAULT NULL")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
        try {
            /**
             * Add _updatedAt attribute.
             */
            $this->pdo->prepare("ALTER TABLE `_{$this->project->getInternalId()}_{$to}` ADD COLUMN IF NOT EXISTS `_updatedAt` int unsigned DEFAULT NULL")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
        try {
            /**
             * Create index for _createdAt.
             */
            $this->pdo->prepare("CREATE INDEX IF NOT EXISTS `_created_at` ON `_{$this->project->getInternalId()}_{$to}` (`_createdAt`)")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
        try {
            /**
             * Create index for _updatedAt.
             */
            $this->pdo->prepare("CREATE INDEX IF NOT EXISTS `_updated_at` ON `_{$this->project->getInternalId()}_{$to}` (`_updatedAt`)")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
    }
}
