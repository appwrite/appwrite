<?php

use Appwrite\Database\Validator\Authorization;
use Appwrite\Resque\Worker;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Storage\Device\Local;

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
            case CREATE_TYPE_INDEX:
                $index = $this->args['document'] ?? '';
                $index = new Document($index);
                $this->createIndex($index, $projectId);
                break;

            // case DELETE_TYPE_DOCUMENT:
            //     $document = $this->args['document'] ?? '';
            //     $document = new Document($document);
                
                // switch ($document->getCollection()) {
                //     case Database::SYSTEM_COLLECTION_PROJECTS:
                //         $this->deleteProject($document);
                //         break;
                //     case Database::SYSTEM_COLLECTION_FUNCTIONS:
                //         $this->deleteFunction($document, $projectId);
                //         break;
                //     case Database::SYSTEM_COLLECTION_USERS:
                //         $this->deleteUser($document, $projectId);
                //         break;
                //     case Database::SYSTEM_COLLECTION_COLLECTIONS:
                //         $this->deleteDocuments($document, $projectId);
                //         break;
                //     default:
                //         Console::error('No lazy delete operation available for document of type: '.$document->getCollection());
                //         break;
                // }
                // break;

            // case DELETE_TYPE_EXECUTIONS:
            //     $this->deleteExecutionLogs($this->args['timestamp']);
            //     break;

            // case DELETE_TYPE_AUDIT:
            //     $this->deleteAuditLogs($this->args['timestamp']);
            //     break;

            // case DELETE_TYPE_ABUSE:
            //     $this->deleteAbuseLogs($this->args['timestamp']);
            //     break;

            // case DELETE_TYPE_CERTIFICATES:
            //     $document = new Document($this->args['document']);
            //     $this->deleteCertificates($document);
            //     break;
                        
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
     */
    protected function createAttribute($attribute, $projectId)
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $attribute->getCollection();
        $id = $attribute->getAttribute('$id');
        $type = $attribute->getAttribute('type');
        $size = $attribute->getAttribute('size');
        $required = $attribute->getAttribute('required');
        $signed = $attribute->getAttribute('signed');
        $array = $attribute->getAttribute('array');
        $filters = $attribute->getAttribute('filters');

        $success = $dbForExternal->createAttribute($collectionId, $id, $type, $size, $required, $signed, $array, $filters);
        if ($success) {
            $removed = $dbForExternal->removeAttributeInQueue($collectionId, $id);
        }
    }

    /**
     * @param Document $index
     */
    protected function createIndex($index, $projectId)
    {
        $dbForExternal = $this->getExternalDB($projectId);

        $collectionId = $index->getCollection();
        $id = $index->getAttribute('$id');
        $type = $index->getAttribute('type');
        $attributes = $index->getAttribute('attributes');
        $lengths = $index->getAttribute('lengths');
        $orders = $index->getAttribute('orders');

        $success = $dbForExternal->createIndex($collectionId, $id, $type, $attributes, $lengths, $orders);
        if ($success) {
            $dbForExternal->removeIndexInQueue($collectionId, $id);
        }
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
