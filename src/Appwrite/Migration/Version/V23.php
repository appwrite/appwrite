<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;

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
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryDevKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables', 'subQueryChallenges', 'subQueryProjectVariables', 'subQueryTargets', 'subQueryTopicTargets'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::info('Migrating collections');
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
        $this->migrateMigrationsCollections();
    }

    /**
     * Add `error` attribute on `migrations` collection.
     */
    private function migrateMigrationsCollections(): void
    {
        $projectInternalId = $this->project->getSequence();

        if ($projectInternalId !== 'projects') {
            return;
        }

        Console::info("  └── Migrating `migrations` collections.");

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        /**
         * direct access.\
         * same as `$this->collections['projects']['migrations']['$id']`.
         */
        $migrationCollectionId = 'migrations';

        try {
            $attributes = ['error'];
            $this->createAttributesFromCollection($this->dbForProject, $migrationCollectionId, $attributes);
        } catch (\Throwable $th) {
            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$migrationCollectionId}: {$th->getMessage()}");
        }

        $this->dbForProject->purgeCachedCollection($migrationCollectionId);
    }
}
