<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V12 extends Migration
{
    /**
     * @var \PDO $pdo
     */
    private $pdo;

    public function execute(): void
    {
        global $register;
        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');

        $this->pdo = $register->get('db');

        Console::info('Migrating Project Schemas');
        $this->migrateProjectSchema($this->project->getId());

        /**
         * Switch to migrated Console Project
         */
        if ($this->project->getId() === 'console') {
            $this->consoleDB->setNamespace('_console');
            $this->projectDB->setNamespace('_console');
        }

        Console::info('Migrating Permissions');
        $this->fixPermissions();
        Console::info('Migrating Collections');
        $this->fixCollections();
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate Project Tables.
     *
     * @param string $projectId
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    private function migrateProjectSchema(string $projectId): void
    {
        /**
         * Remove empty generated Console Project.
         */
        if ($this->consoleDB->getNamespace() === '_project_console' && $projectId === 'console') {
            $all = [];
            foreach ($this->collections as $collection) {
                $all[] = "_{$projectId}_{$collection['$id']}";
                $all[] = "_{$projectId}_{$collection['$id']}_perms";
            }
            $this->pdo->prepare('DROP TABLE IF EXISTS ' . implode(', ', $all) . ';')->execute();
        } elseif ($this->projectDB->getNamespace() === '_console') {
            return;
        }

        /**
         * Rename Database Tables.
         */
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            $this->pdo->prepare("ALTER TABLE IF EXISTS _project_{$projectId}_{$id} RENAME TO _{$projectId}_{$id}")->execute();
            $this->pdo->prepare("CREATE TABLE IF NOT EXISTS _{$projectId}_{$id}_perms (
                `_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `_type` VARCHAR(12) NOT NULL,
                `_permission` VARCHAR(255) NOT NULL,
                `_document` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`_id`),
                UNIQUE INDEX `_index1` (`_type`,`_document`,`_permission`),
                INDEX `_index2` (`_permission`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")->execute();
        }
    }

    /**
     * Migrate all Collection Structure.
     *
     * @return void
     */
    protected function fixCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];
            Console::log("- {$id}");
            switch ($id) {
                case 'sessions':
                    try {
                        /**
                         * Rename providerToken to providerAccessToken
                         */
                        $this->projectDB->renameAttribute($id, 'providerToken', 'providerAccessToken');
                    } catch (\Throwable $th) {
                        Console::warning("'providerAccessToken' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create providerRefreshToken
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'providerRefreshToken', type: Database::VAR_STRING, size: 16384, signed: true, required: true, filters: ['encrypt']);
                    } catch (\Throwable $th) {
                        Console::warning("'providerRefreshToken' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Create providerAccessTokenExpiry
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'providerAccessTokenExpiry', type: Database::VAR_INTEGER, size: 0, required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'providerAccessTokenExpiry' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'memberships':
                    try {
                        /**
                         * Add search attribute and index to memberships.
                         */
                        $this->projectDB->createAttribute(collection: $id, id: 'search', type: Database::VAR_STRING, size: 16384, required: false);
                        $this->projectDB->createIndex(collection: $id, id: '_key_search', type: Database::INDEX_FULLTEXT, attributes: ['search']);
                    } catch (\Throwable $th) {
                        Console::warning("'search' from {$id}: {$th->getMessage()}");
                    }
                    break;
            }
            usleep(100000);
        }
    }

    /**
     * Migrate all Permission to new System with dedicated Table.
     * @return void
     * @throws \Exception
     */
    protected function fixPermissions()
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];
            Console::log("- {$collection['$id']}");
            $nextDocument = null;

            do {
                $documents = $this->projectDB->find($id, limit: $this->limit, cursor: $nextDocument);
                $count = count($documents);

                \Co\run(function (array $documents) {
                    foreach ($documents as $document) {
                        go(function (Document $document) {
                            $sql = "SELECT _read, _write FROM `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_{$document->getCollection()}` WHERE _uid = {$this->pdo->quote($document->getid())}";
                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute();

                            $permissions = $stmt->fetch();

                            $read  = json_decode($permissions['_read'] ?? null) ?? [];
                            $write = json_decode($permissions['_write'] ?? null) ?? [];

                            $permissions = [];
                            foreach ($read as $permission) {
                                $permissions[] = "('read', '{$permission}', '{$document->getId()}')";
                            }

                            foreach ($write as $permission) {
                                $permissions[] = "('write', '{$permission}', '{$document->getId()}')";
                            }

                            if (!empty($permissions)) {
                                $queryPermissions = "INSERT IGNORE INTO `{$this->projectDB->getDefaultDatabase()}`.`{$this->projectDB->getNamespace()}_{$document->getCollection()}_perms` (_type, _permission, _document) VALUES " . implode(', ', $permissions);
                                $stmtPermissions = $this->pdo->prepare($queryPermissions);
                                $stmtPermissions->execute();
                            }
                        }, $document);
                    }
                }, $documents);

                if ($count !== $this->limit) {
                    $nextDocument = null;
                } else {
                    $nextDocument = end($documents);
                }
            } while (!is_null($nextDocument));
        }

        /**
         * Timeout to give MariaDB some room to breath
         */
        usleep(100000);
    }

    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '0.13.0');

                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'users':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'email', 'name'], $document));
                }

                break;

            case 'teams':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'files':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'functions':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name', 'runtime'], $document));
                }

                break;

            case 'tags':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'command'], $document));
                }

                break;

            case 'executions':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'functionId'], $document));
                }

                break;

            case 'memberships':
                /**
                 * Populate search string.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'userId'], $document));
                }

                break;

            case 'sessions':
                $document
                    ->setAttribute('providerRefreshToken', '')
                    ->setAttribute('providerAccessTokenExpiry', 0)
                    ->setAttribute('providerAccessToken', $document->getAttribute('providerToken', ''))
                    ->removeAttribute('providerToken');

                break;
        }

        return $document;
    }

    /**
     * Builds a search string for a fulltext index.
     *
     * @param array $values
     * @param Document $document
     * @return string
     */
    private function buildSearchAttribute(array $values, Document $document): string
    {
        $values = array_filter(array_map(fn (string $value) => $document->getAttribute($value) ?? '', $values));

        return implode(' ', $values);
    }
}
