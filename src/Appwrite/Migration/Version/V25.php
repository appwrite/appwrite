<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
     * @throws Throwable
     */
    private function migrateCollections(): void
    {
        if ($this->project->getSequence() === 'console') {
            return;
        }

        $id = 'migrations';

        $this->dbForProject->purgeCachedCollection($id);
        $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

        $attributes = [
            'resourceInternalId',
            'parentResourceId',
            'parentResourceInternalId',
            'parentResourceType',
            'destinationResourceId',
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

    protected function migrateDocument(Document $document): Document
    {
        if ($document->getCollection() !== 'migrations') {
            return $document;
        }

        $resourceId = (string) $document->getAttribute('resourceId', '');
        $parentResourceId = (string) $document->getAttribute('parentResourceId', '');

        if ($parentResourceId === '') {
            if (!\str_contains($resourceId, ':')) {
                return $document;
            }

            [$parentResourceId, $resourceId] = \explode(':', $resourceId, 2);
            if ($parentResourceId === '' || $resourceId === '') {
                return $document;
            }

            $parentResourceType = (string) $document->getAttribute('resourceType', '');
            $document
                ->setAttribute('resourceId', $resourceId)
                ->setAttribute('resourceType', Resource::TYPE_COLLECTION)
                ->setAttribute('parentResourceId', $parentResourceId);

            if ($parentResourceType !== '') {
                $document->setAttribute('parentResourceType', $parentResourceType);
            }
        }

        if ($resourceId === '') {
            return $document;
        }

        $internalIds = $this->resolveInternalIds($parentResourceId, $resourceId, $document->getId());
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
    protected function resolveInternalIds(string $parentResourceId, string $resourceId, string $migrationId): array
    {
        try {
            $database = $this->dbForProject->getDocument('databases', $parentResourceId);
            if ($database->isEmpty() || $database->getSequence() === '') {
                return [];
            }
        } catch (Throwable $th) {
            Console::warning("Failed to resolve parent internal ID for migration {$migrationId}: {$th->getMessage()}");
            return [];
        }

        $internalIds = [
            'parentResourceInternalId' => (string) $database->getSequence(),
        ];

        try {
            $resource = $this->dbForProject->getDocument('database_' . $database->getSequence(), $resourceId);
            if (!$resource->isEmpty() && $resource->getSequence() !== '') {
                $internalIds['resourceInternalId'] = (string) $resource->getSequence();
            }
        } catch (Throwable $th) {
            Console::warning("Failed to resolve resource internal ID for migration {$migrationId}: {$th->getMessage()}");
        }

        return $internalIds;
    }
}
