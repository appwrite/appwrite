<?php

namespace Appwrite\Migration\Version;

use Appwrite\Auth\Auth;
use Appwrite\Migration\Migration;
use Appwrite\Query;
use PDO;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V18 extends Migration
{
    private \Redis $redis;

    public function execute(): void
    {
        global $register;

        $this->redis = $register->get('cache');

        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

        Console::info('Migrating Databases');
        $this->migrateDatabases();

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'migrateDocument']);

        Console::info('Migrating Cache');
        $this->forEachDocument([$this, 'migrateCache']);
    }

    /**
     * Migrate all Databases.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateDatabases(): void
    {
        foreach ($this->documentsIterator('databases') as $database) {
            $databaseTable = "database_{$database->getInternalId()}";

            Console::info("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");
            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";

                $floats = \array_filter($collection->getAttributes(), function ($attribute) {
                    return $attribute->getAttribute('type') === Database::VAR_FLOAT;
                });

                foreach ($floats as $attribute) {
                    $this->changeAttributeInternalType($collectionTable, $attribute->getId(), 'DOUBLE');
                }
            }
        }
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    private function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $floats = \array_filter($collection, function ($attribute) {
                return $attribute['type'] === Database::VAR_FLOAT;
            });

            foreach ($floats as $attribute) {
                $this->changeAttributeInternalType($id, $attribute->getId(), 'DOUBLE');
            }

            switch ($id) {
                case 'users':
                    try {
                        /**
                         * Create 'passwordHistory' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'passwordHistory');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'passwordHistory' from {$id}: {$th->getMessage()}");
                    }
                    break;
                default:
                    break;
            }

            usleep(50000);
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     */
    private function migrateDocument(Document $document): Document
    {
        switch ($document->getCollection()) {
            case 'projects':
                $document->setAttribute('version', '1.3.0');
                $document->setAttribute('passwordHistory', []);
                $document->setAttribute('auths', array_merge($document->getAttribute('auths', []), [
                    'passwordHistory' => 0,
                    'passwordDictionary' => false,
                ]));
                break;
        }

        return $document;
    }

    private function migrateCache(Document $document)
    {
        $key = "cache-_{$this->project->getInternalId()}:_{$document->getCollection()}:{$document->getId()}";
        $value = $this->redis->get($key);

        if ($value) {
            $this->redis->del($key);
            $this->redis->set($key . ':*', $value);
        }
    }
}
