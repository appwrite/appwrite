<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;

class V22 extends Migration
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

            $this->dbForProject->setNamespace("_$internalProjectId");

            switch ($id) {
                case 'installations':
                    // Create personalAccessToken attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'personalAccessToken');
                    } catch (Throwable $th) {
                        Console::warning("'personalAccessToken' from {$id}: {$th->getMessage()}");
                    }

                    // Create personalAccessTokenExpiry attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'personalAccessTokenExpiry');
                    } catch (Throwable $th) {
                        Console::warning("'personalAccessTokenExpiry' from {$id}: {$th->getMessage()}");
                    }

                    // Create personalRefreshToken attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'personalRefreshToken');
                    } catch (Throwable $th) {
                        Console::warning("'personalRefreshToken' from {$id}: {$th->getMessage()}");
                    }
                    break;
            }

            usleep(50000);
        }
    }
}
