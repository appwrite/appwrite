<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

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
            $databaseTable = "database_{$database->getInternalId()}";

            Console::info("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $this->alterPermissionIndex($databaseTable);

            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";

                $this->alterPermissionIndex($collectionTable);
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

            $this->alterPermissionIndex($id);

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
                $document->setAttribute('version', '1.3.0');

                /**
                 * Set default passwordHistory
                 */
                $document->setAttribute('auths', array_merge([
                    'passwordHistory' => 0,
                    'passwordDictionary' => false,
                ], $document->getAttribute('auths', [])));
                break;
            case 'users':
                /**
                 * Default Password history
                 */
                $document->setAttribute('passwordHistory', $document->getAttribute('passwordHistory', []));
                break;
            case 'teams':
                /**
                 * Default prefs
                 */
                $document->setAttribute('prefs', $document->getAttribute('prefs', new \stdClass()));
                break;
            case 'attributes':
                /**
                 * Default options
                 */
                $document->setAttribute('options', $document->getAttribute('options', new \stdClass()));
                break;
            case 'buckets':
                /**
                 * Set the bucket permission in the metadata table
                 */
                try {
                    $internalBucketId = "bucket_{$this->project->getInternalId()}";
                    $permissions = $document->getPermissions();
                    $fileSecurity = $document->getAttribute('fileSecurity', false);
                    $this->projectDB->updateCollection($internalBucketId, $permissions, $fileSecurity);
                } catch (\Throwable $th) {
                    Console::warning($th->getMessage());
                }
                break;
        }

        return $document;
    }

    protected function alterPermissionIndex($collectionName): void
    {
        $collectionName = "`{$this->projectDB->getDefaultDatabase()}`.`'_{$this->project->getInternalId()}_{$collectionName}_perms`";

        try {
            $this->pdo->prepare("ALTER TABLE {$collectionName} DROP INDEX `_permission`")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }

        try {
            $this->pdo->prepare("ALTER TABLE {$collectionName} ADD INDEX `_permission` (`_permission`, `_type`, `_document`)")->execute();
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
            $this->alterPermissionIndex($id);
        }
    }

}
