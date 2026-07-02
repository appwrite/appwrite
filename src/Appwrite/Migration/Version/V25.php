<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;

class V25 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
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

                case 'installations':
                    if ($collectionType === 'console') {
                        foreach (['personalAccessToken', 'personalRefreshToken'] as $attribute) {
                            try {
                                $this->dbForProject->updateAttribute($id, $attribute, size: 2048);
                            } catch (Throwable $th) {
                                Console::warning("Failed to resize attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                            }
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
            }
        }
    }
}
