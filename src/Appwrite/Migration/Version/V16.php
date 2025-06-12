<?php

namespace Appwrite\Migration\Version;

use Appwrite\Auth\Auth;
use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V16 extends Migration
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

            $this->dbForProject->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case 'sessions':
                    try {
                        /**
                         * Create 'expire' attribute
                         */
                        $this->dbForProject->deleteAttribute($id, 'expire');
                    } catch (\Throwable $th) {
                        Console::warning("'expire' from {$id}: {$th->getMessage()}");
                    }

                    break;

                case 'projects':
                    try {
                        /**
                         * Create 'region' attribute
                         */
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'region');
                    } catch (\Throwable $th) {
                        Console::warning("'region' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        /**
                         * Create '_key_team' index
                         */
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_team');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_team' from {$id}: {$th->getMessage()}");
                    }
                    break;

                case 'stats':
                    try {
                        /**
                         * Create 'region' attribute
                         */
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'region');
                    } catch (\Throwable $th) {
                        Console::warning("'region' from {$id}: {$th->getMessage()}");
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
                $document->setAttribute('version', '1.1.0');

                /**
                 * Set default authDuration
                 */
                $document->setAttribute('auths', array_merge($document->getAttribute('auths', []), [
                    'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG
                ]));

                /**
                 * Enable OAuth providers with data
                 */
                $oAuthProviders = $document->getAttribute('oAuthProviders', []);

                foreach (Config::getParam('oAuthProviders') as $provider => $value) {
                    if (!$value['enabled']) {
                        continue;
                    }

                    if (($oAuthProviders[$provider . 'Appid'] ?? false) && ($oAuthProviders[$provider . 'Secret'] ?? false)) {
                        if (array_key_exists($provider . 'Enabled', $oAuthProviders)) {
                            continue;
                        }

                        $oAuthProviders[$provider . 'Enabled'] = true;
                    }
                }

                $document->setAttribute('oAuthProviders', $oAuthProviders);

                break;
        }

        return $document;
    }
}
