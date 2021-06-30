<?php

use Appwrite\Resque\Worker;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Adapter\MariaDB;

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
        $projectId = $this->args['projectId'] ?? '';
        $type = $this->args['type'] ?? '';
        
        switch (strval($type)) {
            case CREATE_TYPE_ATTRIBUTE:
                $attribute = $this->args['document'] ?? '';
                $attribute = new Document($attribute);
                $this->createAttribute($attribute, $projectId);
                break;
            case DELETE_TYPE_ATTRIBUTE:
                $attribute = $this->args['document'] ?? '';
                $attribute = new Document($attribute);
                $this->deleteAttribute($attribute, $projectId);
                break;
            case CREATE_TYPE_INDEX:
                $index = $this->args['document'] ?? '';
                $index = new Document($index);
                $this->createIndex($index, $projectId);
                break;
            case DELETE_TYPE_INDEX:
                $index = $this->args['document'] ?? '';
                $index = new Document($index);
                $this->deleteIndex($index, $projectId);
                break;

            default:
                Console::error('No database operation for type: '.$type);
                break;
            }

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

        $collectionId = $attribute->getCollection();
        $id = $attribute->getAttribute('$id', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $filters = $attribute->getAttribute('filters', []);

        $success = $dbForExternal->createAttribute($collectionId, $id, $type, $size, $required, $signed, $array, $filters);
        if ($success) {
            $removed = $dbForExternal->removeAttributeInQueue($collectionId, $id);
        }
    }

    /**
     * @param Document $attribute
     * @param string $projectId
     */
    protected function deleteAttribute($attribute, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $attribute->getCollection();
        $id = $attribute->getAttribute('$id');

        $success = $dbForExternal->deleteAttribute($collectionId, $id);
    }

    /**
     * @param Document $index
     * @param string $projectId
     */
    protected function createIndex($index, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $index->getCollection();
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
     * @param Document $index
     * @param string $projectId
     */
    protected function deleteIndex($index, $projectId): void
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $index->getCollection();
        $id = $index->getAttribute('$id');

        $success = $dbForExternal->deleteIndex($collectionId, $id);
    }

    /**
     * @param string $projectId
     *
     * @return Database
     */
    protected function getInternalDB($projectId): Database
    {
        global $register;
        
        $cache = new Cache(new RedisCache($register->get('cache')));
        $dbForInternal = new Database(new MariaDB($register->get('db')), $cache);
        $dbForInternal->setNamespace('project_'.$projectId.'_internal'); // Main DB

        return $dbForInternal;
    }

    /**
     * @param string $projectId
     *
     * @return Database
     */
    protected function getExternalDB($projectId): Database
    {
        global $register;
        
        $cache = new Cache(new RedisCache($register->get('cache')));
        $dbForExternal = new Database(new MariaDB($register->get('db')), $cache);
        $dbForExternal->setNamespace('project_'.$projectId.'_external'); // Main DB

        return $dbForExternal;
    }
}
