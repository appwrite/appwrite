<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V26 extends Migration
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
     * @throws Throwable
     */
    private function migrateCollections(): void
    {
        $collections = $this->collections['projects'];

        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if (empty($id)) {
                continue;
            }

            Console::log("Migrating collection \"{$id}\"");

            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case 'identities':
                    $this->createAttributeFromCollection($this->dbForProject, $id, 'photoUrl');
                    $this->createIndexFromCollection($this->dbForProject, $id, '_key_userId_photoUrl_updatedAt');
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
            }
        }
    }

    protected function migrateDocument(Document $document): Document
    {
        return $document;
    }
}
