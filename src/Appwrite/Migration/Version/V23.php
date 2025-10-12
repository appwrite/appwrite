<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
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
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables', 'subQueryChallenges', 'subQueryProjectVariables', 'subQueryTargets', 'subQueryTopicTargets'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        $this->dbForProject->setNamespace("_{$this->project->getSequence()}");

        if ($this->project->getSequence() !== 'console') {
            Console::info('Migrating Buckets');
            // Ensure attribute exists on buckets collection
            try {
                $this->createAttributeFromCollection($this->dbForProject, 'buckets', 'imageTransformations');
            } catch (\Throwable $th) {
                Console::warning("Failed to create attribute 'imageTransformations' on buckets: {$th->getMessage()}");
            }

            // Ensure attribute exists on bucket files collections (as 'files' attribute)
            $this->migrateBuckets();
        }
    }

    /**
     * Migrating Buckets - set imageTransformations=true when missing.
     *
     * @return void
     */
    private function migrateBuckets(): void
    {
        $this->dbForProject->forEach('buckets', function (Document $bucket) {
            $bucketId = 'bucket_' . $bucket['$sequence'];

            Console::log("Migrating Bucket {$bucketId} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            try {
                // Only set the attribute when it's missing to preserve existing explicit settings
                if ($bucket->getAttribute('imageTransformations', null) === null) {
                    $bucket->setAttribute('imageTransformations', true);
                    $this->dbForProject->updateDocument('buckets', $bucket->getId(), $bucket);
                }

                // Also ensure per-bucket files collections have the attribute created
                $bucketFilesCollection = 'bucket_' . $bucket['$sequence'];
                try {
                    $this->createAttributeFromCollection($this->dbForProject, $bucketFilesCollection, 'transformedAt', 'files');
                } catch (\Throwable $th) {
                    // ignore - attribute may already exist or creation may not be necessary
                }
            } catch (Throwable $th) {
                Console::warning("Failed to update bucket {$bucket->getId()}: {$th->getMessage()}");
            }
        });
    }
}
