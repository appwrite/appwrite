<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;

class V25 extends Migration
{
    public function execute(): void
    {
        // Presence logs are only relevant for regular (project) databases.
        if ($this->project->getSequence() === 'console') {
            return;
        }

        $collectionId = 'presenceLogs';

        try {
            Console::info("Ensuring collection \"{$collectionId}\" exists for project \"{$this->project->getId()}\".");
            $this->dbForProject->purgeCachedCollection($collectionId);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $collectionId);

            $this->createCollection($collectionId);
        } catch (Throwable $th) {
            Console::warning("Failed to create collection \"{$collectionId}\": {$th->getMessage()}");

            // Re-throw so the migration fails fast and doesn't leave the system in a partially migrated state.
            throw $th;
        }
    }
}
