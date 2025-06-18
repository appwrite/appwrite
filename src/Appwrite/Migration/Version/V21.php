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
        $this->dbForProject->setNamespace("_{$this->project->getSequence()}");

        Console::info('Migrating Collections');
        $this->migrateCollections();

        if ($this->project->getSequence() !== 'console') {
            Console::info('Migrating Buckets');
            $this->migrateBuckets();
        }

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
        $internalProjectId = $this->project->getSequence();
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
                case 'projects':
                    // Create accessedAt attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'accessedAt');
                    } catch (Throwable $th) {
                        Console::warning("'accessedAt' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'rules':
                    $attributesToCreate = ['owner', 'region'];
                    foreach ($attributesToCreate as $attribute) {
                        // Create attribute
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = ['_key_owner', '_key_region'];
                    foreach ($indexesToCreate as $index) {
                        // Create index
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }
                    break;
                case 'platforms':
                    // Increase 'type' length to 255
                    try {
                        $this->dbForProject->updateAttribute($id, 'type', size: 255);
                    } catch (Throwable $th) {
                        Console::warning("'type' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'installations':
                    $attributesToCreate = ['personalAccessToken', 'personalAccessTokenExpiry', 'personalRefreshToken'];
                    foreach ($attributesToCreate as $attribute) {
                        // Create attribute
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }
                    break;
                case 'migrations':
                    // Create destination attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'destination');
                    } catch (Throwable $th) {
                        Console::warning("'destination' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'schedules':
                    // Create data attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'data');
                    } catch (Throwable $th) {
                        Console::warning("'data' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'databases':
                    // Create originalId attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'originalId');
                    } catch (Throwable $th) {
                        Console::warning("'originalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'functions':
                    // Create scopes attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'scopes');
                    } catch (Throwable $th) {
                        Console::warning("'scopes' from {$id}: {$th->getMessage()}");
                    }

                    // Create specification attribute
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'specification');
                    } catch (Throwable $th) {
                        Console::warning("'specification' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'executions':
                    // Create requestMethod index
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_requestMethod');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_requestMethod' from {$id}: {$th->getMessage()}");
                    }

                    // Create requestPath index
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_requestPath');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_requestPath' from {$id}: {$th->getMessage()}");
                    }

                    // Create deployment index
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_deployment');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_deployment' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'scheduledAt' attribute
                         */
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'scheduledAt');
                    } catch (\Throwable $th) {
                        Console::warning("'scheduledAt' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'scheduleInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'scheduleInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'scheduleInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create 'scheduleId' attribute
                         */
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'scheduleId');
                    } catch (\Throwable $th) {
                        Console::warning("'scheduleId' from {$id}: {$th->getMessage()}");
                    }
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
                $document->setAttribute('version', '1.6.0');

                // Add accessedAt attribute
                $document->setAttribute('accessedAt', DateTime::now());
                break;
            case 'functions':
                // Set scopes attribute
                if (empty($document->getAttribute('scopes', []))) {
                    $document->setAttribute('scopes', []);
                }

                // Set specification attribute
                if (empty($document->getAttribute('specification'))) {
                    $document->setAttribute('specification', APP_COMPUTE_SPECIFICATION_DEFAULT);
                }
        }

        return $document;
    }

    /**
     * Migrating Buckets.
     *
     * @return void
     */
    private function migrateBuckets(): void
    {
        $this->dbForProject->forEach('buckets', function (Document $bucket) {
            $bucketId = 'bucket_' . $bucket['$sequence'];

            Console::log("Migrating Bucket {$bucketId} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            try {
                $this->dbForProject->updateAttribute($bucketId, 'metadata', size: 65534);
            } catch (\Throwable $th) {
                Console::warning("'metadata' from {$bucketId}: {$th->getMessage()}");
            }

            try {
                $this->createAttributeFromCollection($this->dbForProject, $bucketId, 'transformedAt', 'files');
            } catch (\Throwable $th) {
                Console::warning("'transformedAt' from {$bucketId}: {$th->getMessage()}");
            }

            try {
                $this->createIndexFromCollection($this->dbForProject, $bucketId, '_key_transformedAt', 'files');
            } catch (\Throwable $th) {
                Console::warning("'_key_transformedAt' from {$bucketId}: {$th->getMessage()}");
            }

            try {
                $this->dbForProject->purgeCachedCollection($bucketId);
            } catch (\Throwable $th) {
                Console::warning("purging {$bucketId}: {$th->getMessage()}");
            }
        });
    }
}
