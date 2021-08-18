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
        $projectId = $this->args['projectId'] ?? '';
        $type = $this->args['type'] ?? '';

        Authorization::disable();
        
        switch (strval($type)) {
            case DATABASE_TYPE_CREATE_ATTRIBUTE:
                $attribute = $this->args['document'] ?? '';
                $attribute = new Document($attribute);
                $this->createAttribute($attribute, $projectId);
                break;
            case DATABASE_TYPE_DELETE_ATTRIBUTE:
                $attribute = $this->args['document'] ?? '';
                $attribute = new Document($attribute);
                $this->deleteAttribute($attribute, $projectId);
                break;
            case DATABASE_TYPE_CREATE_INDEX:
                $index = $this->args['document'] ?? '';
                $index = new Document($index);
                $this->createIndex($index, $projectId);
                break;
            case DATABASE_TYPE_DELETE_INDEX:
                $index = $this->args['document'] ?? '';
                $index = new Document($index);
                $this->deleteIndex($index, $projectId);
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
     * @param Document $attribute
     * @param string $projectId
     */
    protected function createAttribute($attribute, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);
        $dbForInternal = $this->getInternalDB($projectId);

        $collectionId = $attribute->getCollection();
        $id = $attribute->getAttribute('$id', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $default = $attribute->getAttribute('default', null);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $format = $attribute->getAttribute('format', null);
        $filters = $attribute->getAttribute('filters', []);

        $success = $dbForExternal->createAttribute($collectionId, $id, $type, $size, $required, $default, $signed, $array, $format, $filters);
        if ($success) {
            $removed = $dbForExternal->removeAttributeInQueue($collectionId, $id);

            // Update internal collection
            $collection = $dbForInternal->getDocument('collections', $collectionId);

            $collection->setAttribute('attributes', new Document([
                '$id' => $id,
                'type' => $type,
                'size' => $size,
                'required' => $required,
                'default' => $default,
                'signed' => $signed,
                'array' => $array,
                'format' => $format,
                'filters' => $filters,
            ]), Document::SET_TYPE_APPEND);

            $dbForInternal->updateDocument('collections', $collection->getId(), $collection);
        }
    }

    /**
     * @param Document $attribute
     * @param string $projectId
     */
    protected function deleteAttribute($attribute, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);
        $dbForInternal = $this->getInternalDB($projectId);

        $collectionId = $attribute->getCollection();
        $id = $attribute->getAttribute('$id');

        $success = $dbForExternal->deleteAttribute($collectionId, $id);
        if ($success) {
            // Update internal collection
            $collection = $dbForInternal->getDocument('collections', $collectionId);

            $collection->findAndRemove('$id', $id, 'attributes');

            $dbForInternal->updateDocument('collections', $collection->getId(), $collection);
        }
    }

    /**
     * @param Document $index
     * @param string $projectId
     */
    protected function createIndex($index, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);
        $dbForInternal = $this->getInternalDB($projectId);

        $collectionId = $index->getCollection();
        $id = $index->getAttribute('$id', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);

        $success = $dbForExternal->createIndex($collectionId, $id, $type, $attributes, $lengths, $orders);
        if ($success) {
            $dbForExternal->removeIndexInQueue($collectionId, $id);

            // Update internal collection
            $collection = $dbForInternal->getDocument('collections', $collectionId);

            $collection->setAttribute('indexes', new Document([
                '$id' => $id,
                'type' => $type,
                'attributes' => $attributes,
                'lengths' => $lengths,
                'order' => $orders,
            ]), Document::SET_TYPE_APPEND);

            $dbForInternal->updateDocument('collections', $collectionId, $collection);
        }
    }

    /**
     * @param Document $index
     * @param string $projectId
     */
    protected function deleteIndex($index, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);
        $dbForInternal = $this->getInternalDB($projectId);

        $collectionId = $index->getCollection();
        $id = $index->getAttribute('$id');

        $success = $dbForExternal->deleteIndex($collectionId, $id);
        if ($success) {
            // Update internal collection
            $collection = $dbForInternal->getDocument('collections', $collectionId);

            $collection->findAndRemove('$id', $id, 'indexes');

            $dbForInternal->updateDocument('collections', $collection->getId(), $collection);
        }
    }
}
