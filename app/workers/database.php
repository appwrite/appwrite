<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__.'/../workers.php';

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
        $id = $attribute->getAttribute('$id', '');
        $key = $attribute->getAttribute('key', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $default = $attribute->getAttribute('default', null);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $format = $attribute->getAttribute('format', null);
        $filters = $attribute->getAttribute('filters', []);

        try {
            $success = $dbForExternal->createAttribute($collectionId, $key, $type, $size, $required, $default, $signed, $array, $format, $filters);
        
            $dbForInternal->updateDocument('attributes', $id, $attribute->setAttribute('status', ($success) ? 'available' : 'failed'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForInternal->updateDocument('attributes', $id, $attribute->setAttribute('status', 'failed'));
        }

        if (!$dbForInternal->purgeDocument('collections', $collectionId)) {
            throw new Exception('Failed to remove collection from the cache', 500);
        }
    }

    /**
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function deleteAttribute(Document $collection, Document $attribute, string $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $collection->getId();
        $id = $attribute->getAttribute('$id');

        $success = $dbForExternal->deleteAttribute($collectionId, $id);
    }

    /**
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function createIndex(Document $collection, Document $index, string $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $collection->getId();
        $id = $index->getAttribute('$id', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);

        $success = $dbForExternal->createIndex($collectionId, $id, $type, $attributes, $lengths, $orders);
        if ($success) {
            $dbForExternal->removeIndexInQueue($collectionId, $id);
        }
    }

    /**
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function deleteIndex(Document $collection, Document $index, string $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $collection->getId();
        $id = $index->getAttribute('$id');

        $success = $dbForExternal->deleteIndex($collectionId, $id);
    }
}
