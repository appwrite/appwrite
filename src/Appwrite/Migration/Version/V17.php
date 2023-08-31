<?php

namespace Appwrite\Migration\Version;

use Appwrite\Auth\Auth;
use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V17 extends Migration
{
    public function execute(): void
    {
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
        Console::info('Migrating Buckets');
        $this->migrateBuckets();
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
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

            try {
                $this->projectDB->updateAttribute($id, 'mimeType', Database::VAR_STRING, 255, true, false);
                $this->projectDB->deleteCachedCollection($id);
            } catch (\Throwable $th) {
                Console::warning("'mimeType' from {$id}: {$th->getMessage()}");
            }
        }
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
                case 'builds':
                    try {
                        /**
                         * Create 'size' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'size');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'size' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'files':
                    try {
                        /**
                         * Update 'mimeType' attribute size (127->255)
                         */
                        $this->projectDB->updateAttribute($id, 'mimeType', Database::VAR_STRING, 255, true, false);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'mimeType' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'bucketInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'bucketInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'builds':
                    try {
                        /**
                         * Delete 'endTime' attribute (use startTime+duration if needed)
                         */
                        $this->projectDB->deleteAttribute($id, 'endTime');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'endTime' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Rename 'outputPath' to 'path'
                         */
                        $this->projectDB->renameAttribute($id, 'outputPath', 'path');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'path' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'deploymentInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'stats':
                    try {
                        /**
                         * Delete 'type' attribute
                         */
                        $this->projectDB->deleteAttribute($id, 'type');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'type' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'schedules':
                    try {
                        /**
                         * Create 'resourceInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'resourceInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'functions':
                    try {
                        /**
                         * Create 'deploymentInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'scheduleInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scheduleInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'scheduleInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Delete 'scheduleUpdatedAt' attribute
                         */
                        $this->projectDB->deleteAttribute($id, 'scheduleUpdatedAt');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'scheduleUpdatedAt' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'deployments':
                    try {
                        /**
                         * Create 'resourceInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'resourceInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'buildInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'buildInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'buildInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'executions':
                    try {
                        /**
                         * Create 'functionInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'functionInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'functionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'deploymentInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }
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
            case 'projects':
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.2.0');

                /**
                 * Set default maxSessions
                 */
                $document->setAttribute('auths', array_merge($document->getAttribute('auths', []), [
                    'maxSessions' => APP_LIMIT_USER_SESSIONS_DEFAULT
                ]));
                break;
            case 'users':
                 /**
                 * Set hashOptions type
                 */
                $document->setAttribute('hashOptions', array_merge($document->getAttribute('hashOptions', []), [
                    'type' => $document->getAttribute('hash', Auth::DEFAULT_ALGO)
                ]));
                break;
        }

        return $document;
    }
}
