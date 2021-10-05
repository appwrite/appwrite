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
            if(!$dbForExternal->deleteAttribute($collectionId, $key)) {
                throw new Exception('Failed to delete Attribute');
            }

            $dbForInternal->deleteDocument('attributes', $attribute->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'failed'));
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
            if(!$dbForExternal->deleteIndex($collectionId, $key)) {
                throw new Exception('Failed to delete Attribute');
            }

            $dbForInternal->deleteDocument('indexes', $index->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'failed'));
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);
    }
}
