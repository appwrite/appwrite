<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;

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
            }
        }
    }
}
