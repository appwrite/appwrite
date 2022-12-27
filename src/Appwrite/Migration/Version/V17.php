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
