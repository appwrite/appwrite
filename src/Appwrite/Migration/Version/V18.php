<?php

namespace Appwrite\Migration\Version;

use Appwrite\Auth\Auth;
use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V18 extends Migration
{
    public function execute(): void
    {
        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subqueryVariables'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');

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

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

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
     * @param \Utopia\Database\Document $document
     * @return \Utopia\Database\Document
     */
    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            case 'projects':
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.2.0');
                break;
            case 'projects':
                /**
                 * Bump version number.
                 */
                $document->setAttribute('passwordHistory', []);

                /**
                 * Set default passwordHistory
                 */
                $document->setAttribute('auths', array_merge($document->getAttribute('auths', []), [
                    'passwordHistory' => 0,
                    'passwordDictionary' => false,
                ]));

                break;
        }

        return $document;
    }
}
