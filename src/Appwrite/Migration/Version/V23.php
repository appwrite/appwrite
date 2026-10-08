<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\AttributeUpdate;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;

class V23 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        /**
         * Disable SubQueries for Performance.
         */
        $subQueries = [
            'subQueryAttributes',
            'subQueryAuthenticators',
            'subQueryChallenges',
            'subQueryDevKeys',
            'subQueryIndexes',
            'subQueryKeys',
            'subQueryMemberships',
            'subQueryPlatforms',
            'subQueryProjectVariables',
            'subQuerySessions',
            'subQueryTargets',
            'subQueryTokens',
            'subQueryTopicTargets',
            'subQueryVariables',
            'subQueryWebhooks',
        ];
        foreach ($subQueries as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::info('Migrating collections');
        $this->migrateCollections();

        if ($this->project->getSequence() != 'console') {
            Console::info('Migrating Databases');
            $this->migrateDatabases();
        }

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        Console::info('Migrating documents');
        $this->forEachDocument($this->migrateDocument(...));
    }

    /**
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $projectInternalId = $this->project->getSequence();

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];

        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if (empty($id)) {
                continue;
            }

            Console::log("Migrating collection \"{$id}\"");

            // Clear cache to ensure new $sequence is used
            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case '_metadata':
                    $this->createCollection('transactions');
                    $this->createCollection('transactionLogs');
                    break;
                case 'projects':
                    $attributes = [
                        'pingCount',
                        'pingedAt'
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'databases':
                    $attributes = [
                        'type',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'schedules':
                    try {
                        $this->dbForProject->updateAttribute($id, 'resourceInternalId', new AttributeUpdate(required: false));
                    } catch (Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'migrations':
                    try {
                        $this->updateMigrateErrorSize();
                    } catch (\Throwable $th) {
                        Console::warning("Failed to  migration error attribute size in collection {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'buckets':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'transformations');
                    } catch (Throwable $th) {
                        Console::warning("'transformations' from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'users':
                    $attributes = [
                        'emailCanonical',
                        'emailIsFree',
                        'emailIsDisposable',
                        'emailIsCorporate',
                        'emailIsCanonical',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Migrate all Database Table tables
     *
     * @return void
     * @throws Exception
     */
    private function migrateDatabases(): void
    {
        foreach ($this->dbForProject->cursor('databases', batchSize: 25) as $database) {
            Console::log("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $databaseTable = "database_{$database->getSequence()}";
            $this->dbForProject->purgeCachedCollection($databaseTable);

            foreach ($this->dbForProject->cursor($databaseTable, batchSize: 25) as $collection) {
                Console::log("Migrating Collection of {$collection->getId()} ({$collection->getAttribute('name')})");

                $collectionTable = "{$databaseTable}_collection_{$collection->getSequence()}";
                $this->dbForProject->purgeCachedCollection($collectionTable);
            }
        }
    }

    /**
     * Migrate all Bucket tables
     *
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function migrateBuckets(): void
    {
        foreach ($this->dbForProject->cursor('buckets', batchSize: 25) as $bucket) {
            Console::log("Migrating Bucket {$bucket->getId()} ({$bucket->getAttribute('name')})");

            $bucketTable = "bucket_{$bucket->getSequence()}";
            $this->dbForProject->purgeCachedCollection($bucketTable);
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     * @throws Conflict
     * @throws Structure
     * @throws Timeout
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Authorization
     * @throws \Utopia\Database\Exception\Query
     */
    private function migrateDocument(Document $document): Document
    {
        switch ($document->getCollection()) {
            case 'databases':
                $document->setAttribute('type', $document->getAttribute('type', 'legacy'));
                break;
            default:
                break;
        }
        return $document;
    }

    /**
     * Update migration attribute size
     * @return void
     */
    private function updateMigrateErrorSize(): void
    {

        if ($this->project->getId() === 'console') {
            return;
        }

        // Read-modify-write from the live schema to avoid overwriting unrelated changes.
        $migration = $this->dbForProject->findCollection('migrations');
        if ($migration === null) {
            Console::warning("Skipping: migrations collection not found for project {$this->project->getId()}");
            return;
        }

        $attributes = $migration->getAttribute('attributes', []);
        $storedAttributes = \array_map(fn (Document $attribute) => $attribute->getArrayCopy(), $attributes);
        $errorsPosition = \array_search('errors', \array_column($storedAttributes, '$id'));

        if ($errorsPosition === false) {
            Console::warning("Skipping: 'errors' attribute not found in migrations collection for project {$this->project->getId()}");
            return;
        }

        $desiredSize = 1_000_000;
        foreach (Config::getParam('collections', [])['projects']['migrations']['attributes'] ?? [] as $attribute) {
            if ($attribute->key === 'errors' && $attribute->size !== null) {
                $desiredSize = $attribute->size;
            }
        }

        $currentSize = (int) ($attributes[$errorsPosition]['size'] ?? 0);

        if ($currentSize === $desiredSize) {
            Console::warning("Skipping: 'errors' attribute already of desired size {$desiredSize} in migrations collection for project {$this->project->getId()}");
            return;
        }
        $attributes[$errorsPosition]['size'] = $desiredSize;
        $migration->setAttribute('attributes', $attributes);
        $this->dbForProject->updateDocument($migration->getCollection(), $migration->getId(), $migration);
        $this->dbForProject->purgeCachedCollection('migrations');
    }
}
