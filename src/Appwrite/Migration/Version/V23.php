<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
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

}
