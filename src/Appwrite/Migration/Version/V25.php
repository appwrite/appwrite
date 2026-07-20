<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Migration\Resource;

class V25 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
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

            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case 'projects':
                    if ($collectionType === 'console') {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, '_key_accessedAt');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"_key_accessedAt\" from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'installations':
                    if ($collectionType === 'console') {
                        foreach (['personalAccessToken', 'personalRefreshToken'] as $attribute) {
                            try {
                                $this->dbForProject->updateAttribute($id, $attribute, size: 2048);
                            } catch (Throwable $th) {
                                Console::warning("Failed to resize attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                            }
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'databases':
                    if ($collectionType === 'projects') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'status');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"status\" in collection {$id}: {$th->getMessage()}");
                        }
                        $this->dbForProject->purgeCachedCollection($id);

                        // Backfill existing databases so the stored value matches the intended default.
                        // Materialize the matched documents before updating any of them: updating
                        // "status" removes rows from the isNull() set, and the offset-based iterator
                        // would otherwise skip un-processed rows as the filtered set shrinks mid-scan.
                        $databases = \iterator_to_array($this->documentsIterator($id, [Query::isNull('status')]));
                        foreach ($databases as $database) {
                            try {
                                $this->dbForProject->updateDocument($id, $database->getId(), new Document([
                                    'status' => 'ready',
                                ]));
                            } catch (Throwable $th) {
                                Console::warning("Failed to backfill \"status\" for database {$database->getId()}: {$th->getMessage()}");
                            }
                        }
                    }
                    break;

                case 'migrations':
                    if ($collectionType === 'projects') {
                        $attributes = [
                            'resourceInternalId',
                            'parentResourceId',
                            'parentResourceInternalId',
                            'parentResourceType',
                            'destinationResourceId',
                            'destinationResourceInternalId',
                            'destinationResourceType',
                        ];
                        try {
                            $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                        } catch (Throwable $th) {
                            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                        }

                        $indexes = [
                            '_key_resourceType',
                            '_key_resourceInternalId',
                            '_key_parentResourceId',
                            '_key_parentResourceType',
                            '_key_parentResourceInternalId',
                            '_key_destinationResourceId',
                            '_key_destinationResourceInternalId',
                            '_key_destinationResourceType',
                        ];
                        foreach ($indexes as $index) {
                            try {
                                $this->createIndexFromCollection($this->dbForProject, $id, $index);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create index \"{$index}\" from {$id}: {$th->getMessage()}");
                            }
                        }

                        $this->dbForProject->purgeCachedCollection($id);
                    }
                    break;
            }
        }
    }

    protected function migrateDocument(Document $document): Document
    {
        if ($document->getCollection() !== 'migrations') {
            return $document;
        }

        $resourceId = (string) $document->getAttribute('resourceId', '');
        $parentResourceId = (string) $document->getAttribute('parentResourceId', '');
        $parentResourceType = '';
        $split = false;

        if ($parentResourceId === '') {
            if (!\str_contains($resourceId, ':')) {
                return $document;
            }

            [$parentResourceId, $resourceId] = \explode(':', $resourceId, 2);
            if ($parentResourceId === '' || $resourceId === '') {
                return $document;
            }

            $parentResourceType = (string) $document->getAttribute('resourceType', '');
            $split = true;
        }

        if ($resourceId === '') {
            return $document;
        }

        $internalIds = $this->resolveInternalIds($parentResourceId, $resourceId, $document);
        if (
            $split
            && (!isset($internalIds['parentResourceInternalId']) || !isset($internalIds['resourceInternalId']))
        ) {
            return $document;
        }

        if ($split) {
            $document
                ->setAttribute('resourceId', $resourceId)
                ->setAttribute('resourceType', Resource::TYPE_COLLECTION)
                ->setAttribute('parentResourceId', $parentResourceId);

            if ($parentResourceType !== '') {
                $document->setAttribute('parentResourceType', $parentResourceType);
            }
        }

        if (
            $document->getAttribute('parentResourceInternalId', '') === ''
            && isset($internalIds['parentResourceInternalId'])
        ) {
            $document->setAttribute('parentResourceInternalId', $internalIds['parentResourceInternalId']);
        }
        if (
            $document->getAttribute('resourceInternalId', '') === ''
            && isset($internalIds['resourceInternalId'])
        ) {
            $document->setAttribute('resourceInternalId', $internalIds['resourceInternalId']);
        }

        return $document;
    }

    /**
     * @return array{parentResourceInternalId?: string, resourceInternalId?: string}
     */
    protected function resolveInternalIds(string $parentResourceId, string $resourceId, Document $migration): array
    {
        try {
            $database = $this->dbForProject->getDocument('databases', $parentResourceId);
            if (
                $database->isEmpty()
                || $database->getSequence() === ''
                || !$this->predatesMigration($database, $migration)
            ) {
                return [];
            }
        } catch (Throwable $th) {
            Console::warning("Failed to resolve parent internal ID for migration {$migration->getId()}: {$th->getMessage()}");
            return [];
        }

        $internalIds = [
            'parentResourceInternalId' => (string) $database->getSequence(),
        ];

        try {
            $resource = $this->dbForProject->getDocument('database_' . $database->getSequence(), $resourceId);
            if (
                !$resource->isEmpty()
                && $resource->getSequence() !== ''
                && $this->predatesMigration($resource, $migration)
            ) {
                $internalIds['resourceInternalId'] = (string) $resource->getSequence();
            }
        } catch (Throwable $th) {
            Console::warning("Failed to resolve resource internal ID for migration {$migration->getId()}: {$th->getMessage()}");
        }

        return $internalIds;
    }

    /**
     * A resource created after the migration is a reused public ID, not the
     * generation the historical migration operated on.
     */
    protected function predatesMigration(Document $resource, Document $migration): bool
    {
        $resourceCreatedAt = \strtotime($resource->getCreatedAt());
        $migrationCreatedAt = \strtotime($migration->getCreatedAt());

        if ($resourceCreatedAt === false || $migrationCreatedAt === false) {
            return false;
        }

        return $resourceCreatedAt <= $migrationCreatedAt;
    }
}
