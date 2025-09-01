<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;

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

        Console::info('Migrating databases');
        $this->migrateDatabases();

        Console::info('Migrating migration collection');
        $this->updateMigrateErrorSize();
    }

    /**
     * Migrate Databases.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateDatabases(): void
    {
        if ($this->project->getId() === 'console') {
            return;
        }

        // since required + default can't be used together
        // so first creating the attribute then bulk updating the attribute
        $this->createAttributeFromCollection($this->dbForProject, 'databases', 'type');
        $this->dbForProject->updateDocuments('databases', new Document(['type' => 'legacy']));
    }

    /**
     * Update migration collection error attribute
     *
     * @return void
     * @throws Exception|Throwable
     */

    private function updateMigrateErrorSize(): void
    {
        if ($this->project->getId() === 'console') {
            return;
        }

        $collection = Config::getParam('collections', [])['projects'] ?? [];
        $migrationAttributes = $collection['migrations']['attributes'];
        $attributeKey = \array_search('errors', \array_column($migrationAttributes, '$id'));
        $migrationAttributes[$attributeKey]['size'] = 131070;
        $migration = $this->dbForProject->getCollection('migrations');
        $migration->setAttribute('attributes', $migrationAttributes);
        $this->dbForProject->updateDocument($migration->getCollection(), $migration->getId(), $migration);
        $this->dbForProject->purgeCachedCollection('migrations');
    }
}
