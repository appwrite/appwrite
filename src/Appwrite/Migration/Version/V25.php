<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

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
        ];
        try {
            $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
        } catch (Throwable $th) {
            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
        }

        try {
            $this->dbForProject->deleteIndex($id, '_key_resource_id');
        } catch (Throwable $th) {
            Console::warning("Failed to delete index \"_key_resource_id\" from {$id}: {$th->getMessage()}");
        }

        $indexes = [
            '_key_resourceId',
            '_key_resourceType',
            '_key_resourceInternalId',
            '_key_parentResourceId',
            '_key_parentResourceType',
            '_key_parentResourceInternalId',
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

    private function migrateDocument(Document $document): Document
    {
        if ($document->getCollection() !== 'migrations') {
            return $document;
        }

        if (!empty($document->getAttribute('parentResourceId'))) {
            return $document;
        }

        $resourceId = $document->getAttribute('resourceId');
        if (empty($resourceId) || !\str_contains($resourceId, ':')) {
            return $document;
        }

        [$parentId, $childId] = \explode(':', $resourceId, 2);
        $parentResourceType = $document->getAttribute('resourceType');

        $parentResourceInternalId = '';
        $resourceInternalId = '';

        try {
            $database = $this->dbForProject->getDocument('databases', $parentId);
            if (!$database->isEmpty()) {
                $parentResourceInternalId = (string) $database->getSequence();

                $collection = $this->dbForProject->getDocument('database_' . $database->getSequence(), $childId);
                if (!$collection->isEmpty()) {
                    $resourceInternalId = (string) $collection->getSequence();
                }
            }
        } catch (Throwable $th) {
            Console::warning("Failed to backfill internal IDs for migration {$document->getId()}: {$th->getMessage()}");
            // Lookup failed — leave the document untouched so the original
            // composite resourceId is preserved and the doc can be retried.
            return $document;
        }

        $document
            ->setAttribute('resourceId', $childId)
            ->setAttribute('resourceInternalId', $resourceInternalId)
            ->setAttribute('resourceType', 'collection')
            ->setAttribute('parentResourceId', $parentId)
            ->setAttribute('parentResourceInternalId', $parentResourceInternalId)
            ->setAttribute('parentResourceType', $parentResourceType);

        return $document;
    }
}
