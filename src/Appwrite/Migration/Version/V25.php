<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;

class V25 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        Console::info('Migrating collections');
        $this->migrateCollections();

        Console::info('Migrating buckets');
        $this->migrateBuckets();
    }

    /**
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $projectInternalId = $this->project->getSequence();

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];

        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if (empty($id)) {
                continue;
            }

            Console::log("Migrating collection \"{$id}\"");

            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case 'projects':
                    if ($collectionType === 'console') {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, '_key_accessedAt');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"_key_accessedAt\" from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
            }
        }
    }

    /**
     * Add the virtual folder `folder` attribute and index to every
     * per-bucket files collection.
     *
     * @return void
     * @throws Throwable
     */
    private function migrateBuckets(): void
    {
        $this->dbForProject->foreach('buckets', function (Document $bucket) {
            $bucketTable = "bucket_{$bucket->getSequence()}";
            Console::log("Migrating Bucket {$bucket->getId()} ({$bucket->getAttribute('name')})");

            try {
                $this->createAttributeFromCollection($this->dbForProject, $bucketTable, 'folder', 'files');
            } catch (Duplicate) {
                Console::warning("'folder' from {$bucketTable}: Column already exists");
            } catch (Throwable $th) {
                Console::warning("'folder' from {$bucketTable}: {$th->getMessage()}");
            }

            try {
                $this->createIndexFromCollection($this->dbForProject, $bucketTable, '_key_folder', 'files');
            } catch (Duplicate) {
                Console::warning("'_key_folder' from {$bucketTable}: Index already exists");
            } catch (Throwable $th) {
                Console::warning("'_key_folder' from {$bucketTable}: {$th->getMessage()}");
            }

            $this->dbForProject->purgeCachedCollection($bucketTable);
        });
    }
}
