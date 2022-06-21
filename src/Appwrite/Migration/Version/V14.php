<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
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

    protected function createNewMetaData(string $id): void
    {
        if (in_array($id, ['files'])) return;

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
            $this->pdo->prepare("CREATE INDEX IF NOT EXISTS `_updated_at` ON `_{$this->project->getInternalId()}_{$id}` (`_updatedAt`)")->execute();
        } catch (\Throwable $th) {
            Console::warning("Migrating {$id} Collection: {$th->getMessage()}");
        }
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

            $this->createNewMetaData($id);

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
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'platforms':
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
                         * Create 'signatureKey' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'signatureKey');
                    } catch (\Throwable $th) {
                        Console::warning("'signatureKey' from {$id}: {$th->getMessage()}");
                    }
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
                        Console::warning("'_key_phone' from {$id}: {$th->getMessage()}");
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
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
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

                if (!empty($document->getAttribute('teamId')) && is_null($document->getAttribute('teamInternalId'))) {
                    $internalId = $this->projectDB->getDocument('teams', $document->getAttribute('teamId'))->getInternalId();
                    $document->setAttribute('teamInternalId', $internalId);
                }

                break;
            case 'keys':
                if (is_null($document->getAttribute('expire'))) {
                    $document->setAttribute('expire', 0);
                }
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'webhooks':
                if (empty($document->getAttribute('signatureKey'))) {
                    $document->setAttribute('signatureKey', \bin2hex(\random_bytes(64)));
                }
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'domains':
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'tokens':
            case 'sessions':
                if (!empty($document->getAttribute('userId')) && is_null($document->getAttribute('userInternalId'))) {
                    $internalId = $this->projectDB->getDocument('users', $document->getAttribute('userId'))->getInternalId();
                    $document->setAttribute('userInternalId', $internalId);
                }

                break;
            case 'memberships':
                if (!empty($document->getAttribute('userId')) && is_null($document->getAttribute('userInternalId'))) {
                    $internalId = $this->projectDB->getDocument('users', $document->getAttribute('userId'))->getInternalId();
                    $document->setAttribute('userInternalId', $internalId);
                }
                if (!empty($document->getAttribute('teamId')) && is_null($document->getAttribute('teamInternalId'))) {
                    $internalId = $this->projectDB->getDocument('teams', $document->getAttribute('teamId'))->getInternalId();
                    $document->setAttribute('teamInternalId', $internalId);
                }

                break;
            case 'attributes':
            case 'indexes':
                if (!empty($document->getAttribute('collectionId')) && is_null($document->getAttribute('collectionInternalId'))) {
                    $internalId = $this->projectDB->getDocument('collections', $document->getAttribute('collectionId'))->getInternalId();
                    $document->setAttribute('collectionInternalId', $internalId);
                }

                break;
            case 'collections':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                if (is_null($document->getUpdateAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                $internalId = $this->projectDB->getDocument('collections', $document->getId())->getInternalId();
                $this->createNewMetaData("collection_{$internalId}");

                break;
            case 'platforms':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                if (is_null($document->getUpdateAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }
                if (!empty($document->getAttribute('projectId')) && is_null($document->getAttribute('projectInternalId'))) {
                    $internalId = $this->projectDB->getDocument('projects', $document->getAttribute('projectId'))->getInternalId();
                    $document->setAttribute('projectInternalId', $internalId);
                }

                break;
            case 'buckets':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                if (is_null($document->getUpdateAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                $internalId = $this->projectDB->getDocument('buckets', $document->getId())->getInternalId();
                $this->createNewMetaData("bucket_{$internalId}");

                break;
            case 'users':
                if (is_null($document->getAttribute('phoneVerification'))) {
                    $document->setAttribute('phoneVerification', false);
                }

                break;
            case 'functions':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }
                if (is_null($document->getUpdateAt())) {
                    $document->setAttribute('$updatedAt', $document->getAttribute('dateUpdated'));
                }

                break;
            case 'deployments':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }

                break;
            case 'executions':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }

                break;
            case 'teams':
                if (is_null($document->getCreatedAt())) {
                    $document->setAttribute('$createdAt', $document->getAttribute('dateCreated'));
                }

                break;
        }

        return $document;
    }
}
