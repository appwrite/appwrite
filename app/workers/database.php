<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__.'/../init.php';

Console::title('Database V1 Worker');
Console::success(APP_NAME.' database worker v1 has started'."\n");

class DatabaseV1 extends Worker
{
    public $args = [];

    public function init(): void
    {
    }

    public function run(): void
    {
        Authorization::disable();
        $projectId = $this->args['projectId'] ?? '';
        $type = $this->args['type'] ?? '';
        $collection = $this->args['collection'] ?? [];
        $collection = new Document($collection);
        $document = $this->args['document'] ?? [];
        $document = new Document($document);

        if($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }

        if($document->isEmpty()) {
            throw new Exception('Missing document');
        }
        
        switch (strval($type)) {
            case DATABASE_TYPE_CREATE_ATTRIBUTE:
                $this->createAttribute($collection, $document, $projectId);
                break;
            case DATABASE_TYPE_DELETE_ATTRIBUTE:
                $this->deleteAttribute($collection, $document, $projectId);
                break;
            case DATABASE_TYPE_CREATE_INDEX:
                $this->createIndex($collection, $document, $projectId);
                break;
            case DATABASE_TYPE_DELETE_INDEX:
                $this->deleteIndex($collection, $document, $projectId);
                break;

            default:
                Console::error('No database operation for type: '.$type);
                break;
            }

            Authorization::reset();
    }

    public function shutdown(): void
    {
    }

    /**
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function createAttribute(Document $collection, Document $attribute, string $projectId): void
    {
        $dbForInternal = $this->getInternalDB($projectId);
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $default = $attribute->getAttribute('default', null);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $format = $attribute->getAttribute('format', '');
        $formatOptions = $attribute->getAttribute('formatOptions', []);
        $filters = $attribute->getAttribute('filters', []);

        try {
            if(!$dbForExternal->createAttribute($collectionId, $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
                throw new Exception('Failed to create Attribute');
            }
            $dbForInternal->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'available'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'failed'));
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);
    }

    /**
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function deleteAttribute(Document $collection, Document $attribute, string $projectId): void
    {
        $dbForInternal = $this->getInternalDB($projectId);
        $dbForExternal = $this->getExternalDB($projectId);
        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');

        try {
            if(!$dbForExternal->deleteAttribute($collectionId, $key) && $attribute->getAttribute('status') !== 'failed') {
                throw new Exception('Failed to delete Attribute');
            }

            $dbForInternal->deleteDocument('attributes', $attribute->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'stuck'));
        }

        // The underlying database removes/rebuilds indexes when attribute is removed
        // Update indexes table with changes
        /** @var Document[] $indexes */
        $indexes = $collection->getAttribute('indexes', []);

        foreach ($indexes as $index) {
            /** @var string[] $attributes */
            $attributes = $index->getAttribute('attributes');
            $lengths = $index->getAttribute('lengths');
            $orders = $index->getAttribute('orders');

            $found = \array_search($key, $attributes);

            if ($found !== false) {
                // If found, remove entry from attributes, lengths, and orders
                // array_values wraps array_diff to reindex array keys 
                // when found attribute is removed from array
                $attributes = \array_values(\array_diff($attributes, [$attributes[$found]]));
                $lengths = \array_values(\array_diff($lengths, [$lengths[$found]]));
                $orders = \array_values(\array_diff($orders, [$orders[$found]]));

                if (empty($attributes)) {
                    $dbForInternal->deleteDocument('indexes', $index->getId());
                } else {
                    $index
                        ->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('lengths', $lengths, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('orders', $orders, Document::SET_TYPE_ASSIGN)
                    ;

                    // Check if an index exists with the same attributes and orders
                    $exists = false;
                    foreach ($indexes as $existing) {
                        if ($existing->getAttribute('key') !== $index->getAttribute('key') // Ignore itself
                            && $existing->getAttribute('attributes') === $index->getAttribute('attributes')
                            && $existing->getAttribute('orders') === $index->getAttribute('orders')
                        ) {
                            $exists = true;
                            break;
                        }
                    }

                    if ($exists) { // Delete the duplicate if created, else update in db 
                        $this->deleteIndex($collection, $index, $projectId);
                    } else {
                        $dbForInternal->updateDocument('indexes', $index->getId(), $index);
                    }
                }
            }
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);
    }

    /**
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function createIndex(Document $collection, Document $index, string $projectId): void
    {
        $dbForInternal = $this->getInternalDB($projectId);
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $collection->getId();
        $key = $index->getAttribute('key', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);

        try {
            if(!$dbForExternal->createIndex($collectionId, $key, $type, $attributes, $lengths, $orders)) {
                throw new Exception('Failed to create Index');
            }
            $dbForInternal->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'available'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'failed'));
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);
    }

    /**
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function deleteIndex(Document $collection, Document $index, string $projectId): void
    {
        $dbForInternal = $this->getInternalDB($projectId);
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $collection->getId();
        $key = $index->getAttribute('key');

        try {
            if(!$dbForExternal->deleteIndex($collectionId, $key) && $index->getAttribute('status') !== 'failed') {
                throw new Exception('Failed to delete index');
            }

            $dbForInternal->deleteDocument('indexes', $index->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'stuck'));
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);
    }
}
