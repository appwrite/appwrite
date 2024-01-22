<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V20 extends Migration
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

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     * @throws \Throwable
     * @throws Exception
     */
    private function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case '_metadata':
                    $this->createCollection('providers');
                    $this->createCollection('messages');
                    $this->createCollection('topics');
                    $this->createCollection('subscribers');
                    $this->createCollection('targets');

                    break;
                case 'users':
                    // Create targets attribute
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'targets');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'targets' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'projects':
                    // Rename providers to oAuthProviders
                    try {
                        $this->projectDB->renameAttribute($id, 'providers', 'oAuthProviders');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'oAuthProviders' from {$id}: {$th->getMessage()}");
                    }
                case 'schedules':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'resourceCollection');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'schedules' from {$id}: {$th->getMessage()}");
                    }
                case 'webhooks':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'enabled');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'logs');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'attempts');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'webhooks' from {$id}: {$th->getMessage()}");
                    }
                default:
                    break;
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
                $document->setAttribute('version', '1.5.0');
                break;
            case 'schedules':
                $document->setAttribute('resourceCollection', 'functions');
                break;
        }
        return $document;
    }
}
