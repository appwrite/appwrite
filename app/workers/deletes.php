<?php

use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use Utopia\Storage\Device\Local;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;

require_once __DIR__.'/../init.php';

Console::title('Deletes V1 Worker');

Console::success(APP_NAME.' deletes worker v1 has started'."\n");

class DeletesV1
{

    public $args = [];

    protected $consoleDB = null;

    public function setUp(): void
    {
    }

    public function perform()
    { 
        $projectId = isset($this->args['projectId']) ? $this->args['projectId'] : '';
        $type = $this->args['type'];
        
        switch (strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                $document = $this->args['document'];
                $document = new Document($document);
                switch (strval($document->getCollection())) {
                    case Database::SYSTEM_COLLECTION_PROJECTS:
                        $this->deleteProject($document);
                        break;
                    case Database::SYSTEM_COLLECTION_FUNCTIONS:
                        $this->deleteFunction($document, $projectId);
                        break;
                    case Database::SYSTEM_COLLECTION_USERS:
                        $this->deleteUser($document, $projectId);
                        break;
                    case Database::SYSTEM_COLLECTION_COLLECTIONS:
                        $this->deleteDocuments($document, $projectId);
                        break;
                    default:
                        Console::error('No lazy delete operation available for document of type: '.$document->getCollection());
                        break;
                }
                break;

            case DELETE_TYPE_EXECUTIONS:
                $this->deleteExecutionLogs($this->args['timestamp']);
                break;

            case DELETE_TYPE_AUDIT:
                $this->deleteAuditLogs($this->args['timestamp']);
                break;

            case DELETE_TYPE_ABUSE:
                $this->deleteAbuseLogs($this->args['timestamp']);
                break;

            case DELETE_TYPE_CERTIFICATES:
                $document = new Document($this->args['document']);
                $this->deleteCertificates($document);
                break;
                        
            default:
                Console::error('No delete operation for type: '.$type);
                break;
            
            }
    }

    public function tearDown(): void
    {
        // ... Remove environment for this job
    }
    
    protected function deleteDocuments(Document $document, $projectId) 
    {
        $collectionId = $document->getId();
        
        // Delete Documents in the deleted collection 
        $this->deleteByGroup([
            '$collection='.$collectionId
        ], $this->getProjectDB($projectId));   
    }

    protected function deleteProject(Document $document)
    {
        // Delete all DBs
        $this->getConsoleDB()->deleteNamespace($document->getId());
        $uploads = new Local(APP_STORAGE_UPLOADS.'/app-'.$document->getId());
        $cache = new Local(APP_STORAGE_CACHE.'/app-'.$document->getId());

        // Delete all storage directories
        $uploads->delete($uploads->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    protected function deleteUser(Document $document, $projectId)
    {
        $tokens = $document->getAttribute('tokens', []);
        
        foreach ($tokens as $token) {
            if (!$this->getProjectDB($projectId)->deleteDocument($token->getId())) {
                throw new Exception('Failed to remove token from DB');
            }
        }

        $sessions = $document->getAttribute('sessions', []);

        foreach ($sessions as $session) {
            if (!$this->getProjectDB($projectId)->deleteDocument($session->getId())) {
                throw new Exception('Failed to remove session from DB');
            }
        }

        var_dump("Hi there! Gonna delete memberships");
        // Delete Memberships and update the team membership counts
        $this->deleteByGroup([
            '$collection='.Database::SYSTEM_COLLECTION_MEMBERSHIPS,
            'userId='.$document->getId(),
        ], $this->getProjectDB($projectId), function(Document $document) use ($projectId,){
            var_dump("In call back ");
            print_r($document);

            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $this->getProjectDB($projectId)->getDocument($teamId);
                print_r($team);
                // $team = $this->getProjectDB($projectId)->updateDocument(\array_merge($team->getArrayCopy(), [
                //     'sum' => $team->getAttribute('sum', 0) - 1,
                // ]));
            }

        });
    }

    protected function deleteExecutionLogs($timestamp) 
    {
        $this->deleteForProjectIds(function($projectId) use ($timestamp) {
            if (!($projectDB = $this->getProjectDB($projectId))) {
                throw new Exception('Failed to get projectDB for project '.$projectId);
            }

            // Delete Executions
            $this->deleteByGroup([
                '$collection='.Database::SYSTEM_COLLECTION_EXECUTIONS,
                'dateCreated<'.$timestamp
            ], $projectDB);
        });
    }

    protected function deleteAbuseLogs($timestamp) 
    {
        global $register;
        if($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }

        $timeLimit = new TimeLimit("", 0, 1, function () use ($register) {
            return $register->get('db');
        });

        $this->deleteForProjectIds(function($projectId) use ($timeLimit, $timestamp){
            $timeLimit->setNamespace('app_'.$projectId);
            $abuse = new Abuse($timeLimit); 

            $status = $abuse->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Abuse logs for project '.$projectId);
            }
        });
    }

    protected function deleteAuditLogs($timestamp)
    {
        global $register;
        if($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }
        $this->deleteForProjectIds(function($projectId) use ($register, $timestamp){
            $adapter = new AuditAdapter($register->get('db'));
            $adapter->setNamespace('app_'.$projectId);
            $audit = new Audit($adapter);
            $status = $audit->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Audit logs for project'.$projectId);
            }
        });
    }

    protected function deleteFunction(Document $document, $projectId)
    {
        $projectDB = $this->getProjectDB($projectId);
        $device = new Local(APP_STORAGE_FUNCTIONS.'/app-'.$projectId);

        // Delete Tags
        $this->deleteByGroup([
            '$collection='.Database::SYSTEM_COLLECTION_TAGS,
            'functionId='.$document->getId(),
        ], $projectDB, function(Document $document) use ($device) {

            if ($device->delete($document->getAttribute('path', ''))) {
                Console::success('Delete code tag: '.$document->getAttribute('path', ''));
            }
            else {
                Console::error('Dailed to delete code tag: '.$document->getAttribute('path', ''));
            }
        });

        // Delete Executions
        $this->deleteByGroup([
            '$collection='.Database::SYSTEM_COLLECTION_EXECUTIONS,
            'functionId='.$document->getId(),
        ], $projectDB);
    }

    protected function deleteById(Document $document, Database $database, callable $callback = null): bool
    {
        Authorization::disable();

        if($database->deleteDocument($document->getId())) {
            Console::success('Deleted document "'.$document->getId().'" successfully');

            if(is_callable($callback)) {
                $callback($document);
            }

            return true;
        }
        else {
            Console::error('Failed to delete document: '.$document->getId());
            return false;
        }

        Authorization::reset();
    }

    protected function deleteForProjectIds(callable $callback)
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $projects = [];
        $sum = $limit;

        $executionStart = \microtime(true);
        
        while($sum === $limit) {
            $chunk++;

            Authorization::disable();
            $projects = $this->getConsoleDB()->getCollection([
                'limit' => $limit,
                'offset' => $count,
                'orderType' => 'ASC',
                'orderCast' => 'string',
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_PROJECTS,
                ],
            ]);
            Authorization::reset();

            $projectIds = array_map (function ($project) { 
                return $project->getId(); 
            }, $projects);

            $sum = count($projects);

            Console::info('Executing delete function for chunk #'.$chunk.'. Found '.$sum.' projects');
            foreach ($projectIds as $projectId) {
                $callback($projectId);
                $count++;
            }
        }

        $executionEnd = \microtime(true);
        Console::info("Found {$count} projects " . ($executionEnd - $executionStart) . " seconds");
    }

    protected function deleteByGroup(array $filters, Database $database, callable $callback = null)
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);
        
        while($sum === $limit) {
            $chunk++;

            Authorization::disable();

            $results = $database->getCollection([
                'limit' => $limit,
                'offset' => $count,
                'orderField' => '$id',
                'orderType' => 'ASC',
                'orderCast' => 'string',
                'filters' => $filters,
            ]);

            Authorization::reset();

            $sum = count($results);

            Console::info('Deleting chunk #'.$chunk.'. Found '.$sum.' documents');

            foreach ($results as $document) {
                $this->deleteById($document, $database, $callback);
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::info("Deleted {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    protected function deleteCertificates(Document $document)
    {
        $domain = $document->getAttribute('domain');
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;
        $checkTraversal = realpath($directory) === $directory;

        if($domain && $checkTraversal && is_dir($directory)) {
            array_map('unlink', glob($directory.'/*.*'));
            rmdir($directory);
            Console::info("Deleted certificate files for {$domain}");
        } else {
            Console::info("No certificate files found for {$domain}");
        }
    }

    /**
     * @return Database;
     */
    protected function getConsoleDB(): Database
    {
        global $register;

        if($this->consoleDB === null) {
            $this->consoleDB = new Database();
            $this->consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
            $this->consoleDB->setNamespace('app_console'); // Main DB
            $this->consoleDB->setMocks(Config::getParam('collections', []));
        }

        return $this->consoleDB;
    }

    /**
     * @return Database;
     */
    protected function getProjectDB($projectId): Database
    {
        global $register;
        
        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $projectDB->setNamespace('app_'.$projectId); // Main DB
        $projectDB->setMocks(Config::getParam('collections', []));

        return $projectDB;
    }
}