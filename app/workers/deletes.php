<?php

use Appwrite\Database\Database;
use Utopia\Database\Database as Database2;
use Utopia\Database\Document as Document2;
use Utopia\Database\Query;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
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
                    case Database::SYSTEM_COLLECTION_PROJECTS:
                        $this->deleteProject($document);
                        break;
                    case Database::SYSTEM_COLLECTION_FUNCTIONS:
                        $this->deleteFunction($document, $projectId);
                        break;
                    case Database::SYSTEM_COLLECTION_USERS:
                        $this->deleteUser2($document, $projectId);
                        break;
                    case Database::SYSTEM_COLLECTION_TEAMS:
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
    
    protected function deleteMemberships(Document $document, $projectId) {
        // Delete Memberships
        $this->deleteByGroup([
            '$collection='.Database::SYSTEM_COLLECTION_MEMBERSHIPS,
            'teamId='.$document->getId(),
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

        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup([
            '$collection='.Database::SYSTEM_COLLECTION_MEMBERSHIPS,
            'userId='.$document->getId(),
        ], $this->getProjectDB($projectId), function(Document $document) use ($projectId) {

            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $this->getProjectDB($projectId)->getDocument($teamId);
                if(!$team->isEmpty()) {
                    $team = $this->getProjectDB($projectId)->updateDocument(\array_merge($team->getArrayCopy(), [
                        'sum' => \max($team->getAttribute('sum', 0) - 1, 0), // Ensure that sum >= 0
                    ]));
                }
            }
        });
    }

    protected function deleteUser2(Document $document, $projectId)
    {
        $userId = $document->getId();

        // Tokens and Sessions removed with user document
        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup2('memberships', [
            new Query('userId', Query::TYPE_EQUAL, [$userId])
        ], $this->getInternalDB($projectId), function(Document $document) use ($projectId, $userId) {

            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $this->getInternalDB($projectId)->getDocument('teams', $teamId);
                if(!$team->isEmpty()) {
                    $team = $this->getInternalDB($projectId)->updateDocument('teams', $teamId, new Document2(\array_merge($team->getArrayCopy(), [
                        'sum' => \max($team->getAttribute('sum', 0) - 1, 0), // Ensure that sum >= 0
                    ])));
                }
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
                Console::error('Failed to delete code tag: '.$document->getAttribute('path', ''));
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

    protected function deleteById2(Document2 $document, Database2 $database, callable $callback = null): bool
    {
        // TODO@kodumbeats this doesnt seem to work - getting the following error:
        // "Write scopes ['role:all'] given, only ["user:{$userId}", "team:{$teamId}/owner"] allowed
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

    /**
     * @param string $collection collectionID
     * @param Query[] $queries
     * @param Database2 $database
     * @param callable $callback
     */
    protected function deleteByGroup2(string $collection, array $queries, Database2 $database, callable $callback = null)
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
                $this->deleteById2($document, $database, $callback);
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

        $db = $register->get('db');
        $cache = $register->get('cache');

        if($this->consoleDB === null) {
            $this->consoleDB = new Database();
            $this->consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));;
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

        $db = $register->get('db');
        $cache = $register->get('cache');

        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $projectDB->setNamespace('app_'.$projectId); // Main DB
        $projectDB->setMocks(Config::getParam('collections', []));

        return $projectDB;
    }
    
    /**
     * @return Database2
     */
    protected function getInternalDB($projectId): Database2
    {
        global $register;
        
        $cache = new Cache(new RedisCache($register->get('cache')));
        $dbForInternal = new Database2(new MariaDB($register->get('db')), $cache);
        $dbForInternal->setNamespace('project_'.$projectId.'_internal'); // Main DB

        return $dbForInternal;
    }
}
