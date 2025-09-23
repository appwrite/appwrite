<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;

class V23 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        /**
         * Disable SubQueries for Performance.
         */
        $subQueries = [
            'subQueryAttributes',
            'subQueryAuthenticators',
            'subQueryChallenges',
            'subQueryDevKeys',
            'subQueryIndexes',
            'subQueryKeys',
            'subQueryMemberships',
            'subQueryPlatforms',
            'subQueryProjectVariables',
            'subQuerySessions',
            'subQueryTargets',
            'subQueryTokens',
            'subQueryTopicTargets',
            'subQueryVariables',
            'subQueryWebhooks',
        ];
        foreach ($subQueries as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::info('Migrating collections');
        $this->migrateCollections();

        Console::info('Migrating documents');
        $this->forEachDocument($this->migrateDocument(...));
    }

    /**
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $projectInternalId = $this->project->getSequence();

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];

        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if (empty($id)) {
                continue;
            }

            Console::log("Migrating collection \"{$id}\"");

            // Clear cache to ensure new $sequence is used
            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case 'databases':
                    $attributes = [
                        'type',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (\Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                case 'schedules':
                    try {
                        $this->dbForProject->updateAttribute($id, 'resourceInternalId', required: false);
                    } catch (Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     * @throws Conflict
     * @throws Structure
     * @throws Timeout
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Authorization
     * @throws \Utopia\Database\Exception\Query
     */
    private function migrateDocument(Document $document): Document
    {
        switch ($document->getCollection()) {
            case 'databases':
                $document->getAttribute('type', $document->getAttribute('type', 'tablesdb'));
                break;
            default:
                break;
        }
        return $document;
    }
}
