<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

class V21 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables', 'subQueryChallenges', 'subQueryProjectVariables', 'subQueryTargets', 'subQueryTopicTargets'] as $name) {
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
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $internalProjectId = $this->project->getInternalId();
        $collectionType = match ($internalProjectId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_$internalProjectId");

            switch ($id) {
                case 'projects':
                    // Create accessedAt attribute
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'accessedAt');
                    } catch (Throwable $th) {
                        Console::warning("'accessedAt' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'executions':
                    try {
                        /**
                         * Create 'scheduledAt' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scheduledAt');
                    } catch (\Throwable $th) {
                        Console::warning("'scheduledAt' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'scheduleInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scheduleInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'scheduleInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'scheduleId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scheduleId');
                    } catch (\Throwable $th) {
                        Console::warning("'scheduleId' from {$id}: {$th->getMessage()}");
                    }

                    break;    
                case 'schedules':
                    // Create data attribute
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'data');
                    } catch (Throwable $th) {
                        Console::warning("'data' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'functions':
                    // Create scopes attribute
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scopes');
                    } catch (Throwable $th) {
                        Console::warning("'scopes' from {$id}: {$th->getMessage()}");
                    }

                    // Create size attribute
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'size');
                    } catch (Throwable $th) {
                        Console::warning("'size' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'executions':
                    // Create requestMethod index
                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_requestMethod');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_requestMethod' from {$id}: {$th->getMessage()}");
                    }

                    // Create requestPath index
                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_requestPath');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_requestPath' from {$id}: {$th->getMessage()}");
                    }

                    // Create deployment index
                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_deployment');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_deployment' from {$id}: {$th->getMessage()}");
                    }
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
                $document->setAttribute('version', '1.6.0');

                // Add accessedAt attribute
                $document->setAttribute('accessedAt', DateTime::now());
                break;
            case 'functions':
                // Add scopes attribute
                $document->setAttribute('scopes', []);

                // Add size attribute
                $document->setAttribute('size', 's-1vcpu-512m');
        }

        return $document;
    }
}
