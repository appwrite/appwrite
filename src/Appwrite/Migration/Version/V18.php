<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V18 extends Migration
{
    public function execute(): void
    {

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
        $this->forEachDocument(function (Document $document) {
            $this->migrateDocument($document);
        });
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

                foreach ($collection['attributes'] ?? [] as $attribute) {
                    if ($attribute['type'] !== Database::VAR_FLOAT) {
                        continue;
                    }
                    $this->changeAttributeInternalType($collectionTable, $attribute['key'], 'DOUBLE');
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

            foreach ($collection['attributes'] ?? [] as $attribute) {
                if ($attribute['type'] !== Database::VAR_FLOAT) {
                    continue;
                }
                $this->changeAttributeInternalType($id, $attribute['$id'], 'DOUBLE');
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
                case 'teams':
                    try {
                        /**
                         * Create 'prefs' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'prefs');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'prefs' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'attributes':
                    try {
                        /**
                         * Create 'options' attribute
                         */
                        $this->createAttributeFromCollection($this->projectDB, $id, 'options');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'options' from {$id}: {$th->getMessage()}");
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
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.3.0');

                /**
                 * Set default passwordHistory
                 */
                $document->setAttribute('auths', array_merge($document->getAttribute('auths', []), [
                    'passwordHistory' => 0,
                    'passwordDictionary' => false,
                ]));
                break;
            case 'users':
                /**
                 * Default Password history
                 */
                $document->setAttribute('passwordHistory', []);
                break;
            case 'teams':
                /**
                 * Default prefs
                 */
                $document->setAttribute('prefs', new \stdClass());
                break;
            case 'attributes':
                /**
                 * Default options
                 */
                $document->setAttribute('options', new \stdClass());
                break;
        }

        return $document;
    }
}
