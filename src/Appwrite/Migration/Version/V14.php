<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V14 extends Migration
{
    /**
     * @var \PDO $pdo
     */
    private $pdo;

    public function execute(): void
    {
        global $register;
        $this->pdo = $register->get('db');

        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        Console::info('Migrating Collections');
        $this->migrateCollections();
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    protected function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("- {$id}");

            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getId()}_{$id}` RENAME TO `_{$this->project->getInternalId()}_{$id}`")->execute();
            } catch (\Throwable $th) {
                Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
            }
            try {
                $this->pdo->prepare("ALTER TABLE IF EXISTS `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getId()}_{$id}_perms` RENAME TO `_{$this->project->getInternalId()}_{$id}_perms`")->execute();
            } catch (\Throwable $th) {
                Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
            }
            try {
                $this->pdo->prepare("ALTER TABLE `_{$this->project->getInternalId()}_{$id}` ADD COLUMN IF NOT EXISTS `_createdAt` int unsigned DEFAULT NULL")->execute();
            } catch (\Throwable $th) {
                Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
            }
            try {
                $this->pdo->prepare("ALTER TABLE `_{$this->project->getInternalId()}_{$id}` ADD COLUMN IF NOT EXISTS `_updatedAt` int unsigned DEFAULT NULL")->execute();
            } catch (\Throwable $th) {
                Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
            }
            try {
                $this->pdo->prepare("CREATE INDEX IF NOT EXISTS `_created_at` ON `_{$this->project->getInternalId()}_{$id}` (`_createdAt`)")->execute();
            } catch (\Throwable $th) {
                Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
            }
            try {
                $this->pdo->prepare("CREATE INDEX IF NOT EXISTS `_updatedAt` ON `_{$this->project->getInternalId()}_{$id}` (`_updatedAt`)")->execute();
            } catch (\Throwable $th) {
                Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
            }

            usleep(100000);

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case 'attributes':
                case 'indexes':
                    try {
                        /**
                         * Create 'collectionInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'collectionInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Re-Create '_key_collection' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_collection');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_collection');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_collection' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'projects':
                    try {
                        /**
                         * Create 'teamInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'teamInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'platforms':
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'domains':
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'keys':
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'expire' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'expire');
                    } catch (\Throwable $th) {
                        Console::warning("'expire' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'webhooks':
                    try {
                        /**
                         * Create 'projectInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'projectInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_project' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_project');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_project');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'users':
                    try {
                        /**
                         * Create 'phone' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'phone');
                    } catch (\Throwable $th) {
                        Console::warning("'phone' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'phoneVerification' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'phoneVerification');
                    } catch (\Throwable $th) {
                        Console::warning("'phoneVerification' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create '_key_phone' index
                         */
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_phone');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_project' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'tokens':
                    try {
                        /**
                         * Create 'userInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'userInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_user' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_user');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_user');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_user' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'sessions':
                    try {
                        /**
                         * Create 'userInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'userInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_user' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_user');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_user');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_user' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'memberships':
                    try {
                        /**
                         * Create 'teamInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'teamInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create 'userInternalId' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'userInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_unique' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_unique');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_unique');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_unique' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_team' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_team');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_team');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_team' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Re-Create '_key_user' index
                         */
                        $this->projectDB->deleteIndex($id, '_key_user');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_user');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_user' from {$id}: {$th->getMessage()}");
                    }
                    break;
            }
            usleep(100000);
        }
    }

    /**
     * Fix run on each document
     *
     * @param \Utopia\Database\Document $document
     * @return \Utopia\Database\Document
     */
    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '0.15.0');

                break;
        }

        return $document;
    }
}
