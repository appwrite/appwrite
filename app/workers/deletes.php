<?php

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Validator\Authorization;
use Appwrite\Resque\Worker;
use Utopia\Storage\Device\Local;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;

require_once __DIR__.'/../workers.php';

Console::title('Deletes V1 Worker');
Console::success(APP_NAME.' deletes worker v1 has started'."\n");

class DeletesV1 extends Worker
{
    public $args = [];

    protected $consoleDB = null;

    public function init(): void
    {
    }

    public function run(): void
    {
        $projectId = $this->args['projectId'] ?? '';
        $type = $this->args['type'] ?? '';
        
        switch (strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                $document = $this->args['document'] ?? '';
                $document = new Document($document);
                
                switch ($document->getCollection()) {
                    // TODO@kodumbeats define these as constants somewhere
                    case 'projects':
                        $this->deleteProject($document);
                        break;
                    case 'functions':
                        $this->deleteFunction($document, $projectId);
                        break;
                    case 'users':
                        $this->deleteUser($document, $projectId);
                        break;
                    case 'teams':
                        $this->deleteMemberships($document, $projectId);
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

    public function shutdown(): void
    {
    }
    
    /**
     * @param Document $document teams document
     * @param string $projectId
     */
    protected function deleteMemberships(Document $document, $projectId) {
        $teamId = $document->getAttribute('teamId', '');

        // Delete Memberships
        $this->deleteByGroup('memberships', [
            new Query('teamId', Query::TYPE_EQUAL, [$teamId])
        ], $this->getInternalDB($projectId));
    }

    /**
     * @param Document $document project document
     */
    protected function deleteProject(Document $document)
    {
        $projectId = $document->getId();
        // Delete all DBs
        $this->getExternalDB($projectId)->delete();
        $this->getInternalDB($projectId)->delete();

        // Delete all storage directories
        $uploads = new Local(APP_STORAGE_UPLOADS.'/app-'.$document->getId());
        $cache = new Local(APP_STORAGE_CACHE.'/app-'.$document->getId());

        $uploads->delete($uploads->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    /**
     * @param Document $document user document
     * @param string $projectId
     */
    protected function deleteUser(Document $document, $projectId)
    {
        $userId = $document->getId();

        // Tokens and Sessions removed with user document
        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup('memberships', [
            new Query('userId', Query::TYPE_EQUAL, [$userId])
        ], $this->getInternalDB($projectId), function(Document $document) use ($projectId, $userId) {

            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $this->getInternalDB($projectId)->getDocument('teams', $teamId);
                if(!$team->isEmpty()) {
                    $team = $this->getInternalDB($projectId)->updateDocument('teams', $teamId, new Document(\array_merge($team->getArrayCopy(), [
                        'sum' => \max($team->getAttribute('sum', 0) - 1, 0), // Ensure that sum >= 0
                    ])));
                }
            }
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteExecutionLogs($timestamp) 
    {
        $this->deleteForProjectIds(function($projectId) use ($timestamp) {
            if (!($dbForInternal = $this->getInternalDB($projectId))) {
                throw new Exception('Failed to get projectDB for project '.$projectId);
            }

            // Delete Executions
            $this->deleteByGroup('executions', [
                new Query('dateCreated', Query::TYPE_LESSER, [$timestamp])
            ], $dbForInternal);
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteAbuseLogs($timestamp) 
    {
        if($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }

        $this->deleteForProjectIds(function($projectId) use ($timestamp){
            $timeLimit = new TimeLimit("", 0, 1, $this->getInternalDB($projectId));
            $abuse = new Abuse($timeLimit); 

            $status = $abuse->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Abuse logs for project '.$projectId);
            }
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteAuditLogs($timestamp)
    {
        if($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }
        $this->deleteForProjectIds(function($projectId) use ($timestamp){
            $audit = new Audit($this->getInternalDB($projectId));
            $status = $audit->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Audit logs for project'.$projectId);
            }
        });
    }

    /**
     * @param Document $document function document
     * @param string $projectId
     */
    protected function deleteFunction(Document $document, $projectId)
    {
        $dbForInternal = $this->getInternalDB($projectId);
        $device = new Local(APP_STORAGE_FUNCTIONS.'/app-'.$projectId);

        // Delete Tags
        $this->deleteByGroup('tags', [
            new Query('functionId', Query::TYPE_EQUAL, [$document->getId()])
        ], $dbForInternal, function(Document $document) use ($device) {

            if ($device->delete($document->getAttribute('path', ''))) {
                Console::success('Delete code tag: '.$document->getAttribute('path', ''));
            }
            else {
                Console::error('Failed to delete code tag: '.$document->getAttribute('path', ''));
            }
        });

        // Delete Executions
        $this->deleteByGroup('executions', [
            new Query('functionId', Query::TYPE_EQUAL, [$document->getId()])
        ], $dbForInternal);
    }


    /**
     * @param Document $document to be deleted
     * @param Database $database to delete it from
     * @param callable $callback to perform after document is deleted
     *
     * @return bool
     */
    protected function deleteById(Document $document, Database $database, callable $callback = null): bool
    {
        Authorization::disable();

        // TODO@kodumbeats is it better to pass objects or ID strings?
        if($database->deleteDocument($document->getCollection(), $document->getId())) {
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

    /**
     * @param callable $callback
     */
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
            $projects = $this->getConsoleDB()->find('projects', [], $limit);
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

    /**
     * @param string $collection collectionID
     * @param Query[] $queries
     * @param Database $database
     * @param callable $callback
     */
    protected function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null)
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

            $results = $database->find($collection, $queries, $limit, 0);

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

    /**
     * @param Document $document certificates document 
     * @return Database
     */
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
     * @param string $projectId
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

    /**
     * @return Database
     */
    protected function getConsoleDB(): Database
    {
        global $register;

        // wait for database to be ready
        $attempts = 0;
        $max = 5;
        $sleep = 5;

        do {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $dbForConsole = new Database(new MariaDB($register->get('db')), $cache);
                $dbForConsole->setNamespace('project_console_internal'); // Main DB
                break; // leave the do-while if successful
            } catch(\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: '. $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        return $dbForConsole;
    }
}
