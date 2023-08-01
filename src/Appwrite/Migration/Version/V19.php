<?php

namespace Appwrite\Migration\Version;

use Appwrite\Auth\Auth;
use Utopia\Config\Config;
use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V19 extends Migration
{
    public function execute(): void
    {

        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

        $this->alterPermissionIndex('_metadata');
        $this->alterUidType('_metadata');

        Console::info('Migrating Databases');
        $this->migrateDatabases();

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate all Databases.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateDatabases(): void
    {
        foreach ($this->documentsIterator('databases') as $database) {
            Console::log("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $databaseTable = "database_{$database->getInternalId()}";

            $this->alterPermissionIndex($databaseTable);
            $this->alterUidType($databaseTable);

            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";
                Console::log("Migrating Collections of {$collectionTable} {$collection->getId()} ({$collection->getAttribute('name')})");
                $this->alterPermissionIndex($collectionTable);
                $this->alterUidType($collectionTable);
            }
        }
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    private function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case 'projects':
                    try {
                        /**
                         * Create 'passwordHistory' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'smtp');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'templates');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'SMTP and Templates' from {$id}: {$th->getMessage()}");
                    }
                    break;
                default:
                    break;
            }
            if (!in_array($id, ['files', 'collections'])) {
                $this->alterPermissionIndex($id);
                $this->alterUidType($id);
            }

            usleep(50000);
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     */
    protected function fixDocument(Document $document): Document
    {
        switch ($document->getCollection()) {
            case 'projects':
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.4.0');

                $document->setAttribute('smtp', []);
                $document->setAttribute('templates', []);

                break;
        }

        return $document;
    }

    protected function alterPermissionIndex($collectionName): void
    {
        try {
            $table = "`{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$collectionName}_perms`";
            $this->pdo->prepare("
                ALTER TABLE {$table}
                DROP INDEX `_permission`, 
                ADD INDEX `_permission` (`_permission`, `_type`, `_document`);
            ")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }
    }

    protected function alterUidType($collectionName): void
    {
        try {
            $table = "`{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$collectionName}`";

            $this->pdo->prepare("
            ALTER TABLE {$table}
            CHANGE COLUMN `_uid` `_uid` VARCHAR(255) NOT NULL ;
            ")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
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
            $id = "bucket_{$bucket->getInternalId()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");
            $this->alterPermissionIndex($id);
            $this->alterUidType($id);
        }
    }
}
