<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
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
     * @throws Throwable
     */
    private function migrateCollections(): void
    {
        Console::log('Adding "notes" attribute to "attributes" collection');

        $this->dbForProject->purgeCachedCollection('attributes');
        $this->dbForProject->purgeCachedDocument(Database::METADATA, 'attributes');

        try {
            $this->createAttributeFromCollection($this->dbForProject, 'attributes', 'notes');
        } catch (Throwable $th) {
            Console::warning("Failed to create attribute \"notes\" in collection attributes: {$th->getMessage()}");
        }

        $this->dbForProject->purgeCachedCollection('attributes');
    }
}
