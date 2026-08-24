<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;

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
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'photoUrl');
                    } catch (Duplicate) {
                        Console::warning('Attribute "photoUrl" already exists in collection "identities"; skipping.');
                    }
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_userId_photoUrl_updatedAt');
                    } catch (Duplicate) {
                        Console::warning('Index "_key_userId_photoUrl_updatedAt" already exists in collection "identities"; skipping.');
                    }
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
