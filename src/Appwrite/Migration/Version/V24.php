<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;

class V24 extends Migration
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
            'subQueryAccountKeys',
            'subQueryAttributes',
            'subQueryAuthenticators',
            'subQueryChallenges',
            'subQueryDevKeys',
            'subQueryIndexes',
            'subQueryKeys',
            'subQueryMemberships',
            'subQueryOrganizationKeys',
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

        if ($this->project->getSequence() != 'console') {
            Console::info('Migrating Databases');
            $this->migrateDatabases();
        }

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        Console::info('Migrating documents');
        $this->forEachDocument($this->migrateDocument(...));

        Console::info('Cleaning up old attributes');
        $this->cleanupOldAttributes();
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

            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case 'projects':
                    if ($collectionType === 'console') {
                        $attributes = [
                            'labels',
                            'status',
                        ];
                        try {
                            $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                        } catch (Throwable $th) {
                            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'keys':
                    if ($collectionType === 'console') {
                        $attributes = [
                            'resourceType',
                            'resourceId',
                            'resourceInternalId',
                        ];
                        try {
                            $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                        } catch (Throwable $th) {
                            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                        }

                        try {
                            $this->dbForProject->deleteIndex($id, '_key_project');
                        } catch (Throwable $th) {
                            Console::warning("Failed to delete index \"_key_project\" from {$id}: {$th->getMessage()}");
                        }

                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, '_key_resource');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"_key_resource\" from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'rules':
                    if ($collectionType === 'console') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'logs');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"logs\" in collection {$id}: {$th->getMessage()}");
                        }

                        $indexesToDelete = [
                            '_key_type',
                            '_key_trigger',
                            '_key_deploymentResourceType',
                            '_key_owner',
                            '_key_region',
                            '_key_piid_riid_rt',
                        ];
                        foreach ($indexesToDelete as $index) {
                            try {
                                $this->dbForProject->deleteIndex($id, $index);
                            } catch (Throwable $th) {
                                Console::warning("Failed to delete index \"$index\" from {$id}: {$th->getMessage()}");
                            }
                        }

                        $indexesToCreate = [
                            '_key_type',
                            '_key_trigger',
                            '_key_deploymentResourceType',
                            '_key_owner',
                            '_key_piid_diid_drt',
                            '_key_region_status_createdAt',
                        ];
                        foreach ($indexesToCreate as $index) {
                            try {
                                $this->createIndexFromCollection($this->dbForProject, $id, $index);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create index \"$index\" from {$id}: {$th->getMessage()}");
                            }
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'users':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'impersonator');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"impersonator\" in collection {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, 'impersonator');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create index \"impersonator\" from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'teams':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'labels');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"labels\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'databases':
                    if ($collectionType === 'projects') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'database');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"database\" in collection {$id}: {$th->getMessage()}");
                        }
                        $this->dbForProject->purgeCachedCollection($id);
                    }
                    break;

                case 'functions':
                    $attributes = [
                        'deploymentRetention',
                        'startCommand',
                        'buildSpecification',
                        'runtimeSpecification',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'sites':
                    $attributes = [
                        'startCommand',
                        'deploymentRetention',
                        'buildSpecification',
                        'runtimeSpecification',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'deployments':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'startCommand');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"startCommand\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'executions':
                    try {
                        $this->dbForProject->deleteIndex($id, '_key_function_internal_id');
                    } catch (Throwable $th) {
                        Console::warning("Failed to delete index \"_key_function_internal_id\" from {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_resourceType');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create index \"_key_resourceType\" from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'buckets':
                    try {
                        $this->dbForProject->deleteIndex($id, '_fulltext_name');
                    } catch (Throwable $th) {
                        Console::warning("Failed to delete index \"_fulltext_name\" from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'providers':
                    try {
                        $this->dbForProject->deleteIndex($id, '_key_name');
                    } catch (Throwable $th) {
                        Console::warning("Failed to delete index \"_key_name\" from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'topics':
                    try {
                        $this->dbForProject->deleteIndex($id, '_key_name');
                    } catch (Throwable $th) {
                        Console::warning("Failed to delete index \"_key_name\" from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                default:
                    break;
            }
        }
    }

    /**
     * Migrate all Database tables
     *
     * @return void
     * @throws Exception
     */
    private function migrateDatabases(): void
    {
        $this->dbForProject->foreach('databases', function (Document $database) {
            Console::log("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $databaseTable = "database_{$database->getSequence()}";
            $this->dbForProject->purgeCachedCollection($databaseTable);

            $this->dbForProject->foreach($databaseTable, function (Document $collection) use ($databaseTable) {
                Console::log("Migrating Collection of {$collection->getId()} ({$collection->getAttribute('name')})");

                $collectionTable = "{$databaseTable}_collection_{$collection->getSequence()}";
                $this->dbForProject->purgeCachedCollection($collectionTable);
            });
        });
    }

    /**
     * Migrate all Bucket tables
     *
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function migrateBuckets(): void
    {
        $this->dbForProject->foreach('buckets', function (Document $bucket) {
            Console::log("Migrating Bucket {$bucket->getId()} ({$bucket->getAttribute('name')})");

            $bucketTable = "bucket_{$bucket->getSequence()}";
            $this->dbForProject->purgeCachedCollection($bucketTable);
        });
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
            case 'keys':
                $projectInternalId = $document->getAttribute('projectInternalId');
                $projectId = $document->getAttribute('projectId');

                if (!empty($projectInternalId) && empty($document->getAttribute('resourceInternalId'))) {
                    $document->setAttribute('resourceType', 'projects');
                    $document->setAttribute('resourceId', $projectId);
                    $document->setAttribute('resourceInternalId', $projectInternalId);
                }
                break;
            default:
                break;
        }
        return $document;
    }

    /**
     * Clean up old attributes after document migration is complete.
     *
     * @return void
     */
    private function cleanupOldAttributes(): void
    {
        $collectionType = match ($this->project->getSequence()) {
            'console' => 'console',
            default => 'projects',
        };

        if ($collectionType === 'console') {
            $attributesToDelete = [
                'keys' => ['projectInternalId', 'projectId'],
            ];

            foreach ($attributesToDelete as $collectionId => $attributes) {
                foreach ($attributes as $attribute) {
                    try {
                        $this->dbForProject->deleteAttribute($collectionId, $attribute);
                    } catch (Throwable $th) {
                        Console::warning("Failed to delete attribute \"{$attribute}\" from {$collectionId}: {$th->getMessage()}");
                    }
                }
                $this->dbForProject->purgeCachedCollection($collectionId);
            }
        }
    }
}
