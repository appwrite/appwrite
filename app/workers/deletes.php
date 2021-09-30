<?php

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Appwrite\Resque\Worker;
use Utopia\Storage\Device\Local;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Audit\Audit;

require_once __DIR__.'/../init.php';

Console::title('Deletes V1 Worker');
Console::success(APP_NAME . ' deletes worker v1 has started' . "\n");

class DeletesV1 extends Worker
{
    /**
     * @var array
     */
    public $args = [];

    /**
     * @var Database
     */
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
                $document = $this->args['document'] ?? [];
                $document = new Document($document);
                
                switch ($document->getCollection()) {
                    // TODO@kodumbeats define these as constants somewhere
                    case 'collections':
                        $this->deleteCollection($document, $projectId);
                        break;
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
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
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

            case DELETE_TYPE_REALTIME:
                //$this->deleteRealtimeUsage($this->args['timestamp']);
                //TODO: implement this
                break;

            case DELETE_TYPE_CERTIFICATES:
                $document = new Document($this->args['document']);
                $this->deleteCertificates($document);
                break;

            case DELETE_TYPE_USAGE:
                $this->deleteUsageStats($this->args['timestamp1d'], $this->args['timestamp30m']);
                break;
            default:
                Console::error('No delete operation for type: ' . $type);
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
    protected function deleteCollection(Document $document, string $projectId): void
    {
        $collectionId = $document->getId();

        $dbForInternal = $this->getInternalDB($projectId);
        $dbForExternal = $this->getExternalDB($projectId);

        $this->deleteByGroup('attributes', [
            new Query('collectionId', Query::TYPE_EQUAL, [$collectionId])
        ], $dbForInternal);

        $this->deleteByGroup('indexes', [
            new Query('collectionId', Query::TYPE_EQUAL, [$collectionId])
        ], $dbForInternal);

        $dbForExternal->deleteCollection($collectionId);
    }

    /**
     * @param int $timestamp1d
     * @param int $timestamp30m
     */
    protected function deleteUsageStats(int $timestamp1d, int $timestamp30m) {
        $this->deleteForProjectIds(function($projectId) use ($timestamp1d, $timestamp30m) {
            if (!($dbForInternal = $this->getInternalDB($projectId))) {
                throw new Exception('Failed to get projectDB for project '.$projectId);
            }

            // Delete Usage stats
            $this->deleteByGroup('stats', [
                new Query('time', Query::TYPE_LESSER, [$timestamp1d]),
                new Query('period', Query::TYPE_EQUAL, ['1d']),
            ], $dbForInternal);

            $this->deleteByGroup('stats', [
                new Query('time', Query::TYPE_LESSER, [$timestamp30m]),
                new Query('period', Query::TYPE_EQUAL, ['30m']),
            ], $dbForInternal);
        });
    }
    
    /**
     * @param Document $document teams document
     * @param string $projectId
     */
    protected function deleteMemberships(Document $document, string $projectId): void
    {
        $teamId = $document->getAttribute('teamId', '');

        // Delete Memberships
        $this->deleteByGroup('memberships', [
            new Query('teamId', Query::TYPE_EQUAL, [$teamId])
        ], $this->getInternalDB($projectId));
    }

    /**
     * @param Document $document project document
     */
    protected function deleteProject(Document $document): void
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
    protected function deleteUser(Document $document, string $projectId): void
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
    protected function deleteExecutionLogs(int $timestamp): void
    {
        $this->deleteForProjectIds(function(string $projectId) use ($timestamp) {
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
    protected function deleteAbuseLogs(int $timestamp): void
    {
        if($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }

        $this->deleteForProjectIds(function($projectId) use ($timestamp){
            $timeLimit = new TimeLimit("", 0, 1, $this->getInternalDB($projectId));
            $abuse = new Abuse($timeLimit); 

            $status = $abuse->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Abuse logs for project ' . $projectId);
            }
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteAuditLogs(int $timestamp): void
    {
        if($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }
        $this->deleteForProjectIds(function($projectId) use ($timestamp){
            $audit = new Audit($this->getInternalDB($projectId));
            $status = $audit->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Audit logs for project' . $projectId);
            }
        });
    }

    /**
     * @param Document $document function document
     * @param string $projectId
     */
    protected function deleteFunction(Document $document, string $projectId): void
    {
        $dbForInternal = $this->getInternalDB($projectId);
        $device = new Local(APP_STORAGE_FUNCTIONS.'/app-'.$projectId);

        // Delete Tags
        $this->deleteByGroup('tags', [
            new Query('functionId', Query::TYPE_EQUAL, [$document->getId()])
        ], $dbForInternal, function(Document $document) use ($device) {

            if ($device->delete($document->getAttribute('path', ''))) {
                Console::success('Delete code tag: ' . $document->getAttribute('path', ''));
            } else {
                Console::error('Failed to delete code tag: ' . $document->getAttribute('path', ''));
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

            if (is_callable($callback)) {
                $callback($document);
            }

            return true;
        } else {
            Console::error('Failed to delete document: ' . $document->getId());
            return false;
        }

        Authorization::reset();
    }

    /**
     * @param callable $callback
     */
    protected function deleteForProjectIds(callable $callback): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $projects = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while($sum === $limit) {
            Authorization::disable();
            $projects = $this->getConsoleDB()->find('projects', [], $limit, ($chunk * $limit));
            Authorization::reset();

            $chunk++;

            $projectIds = array_map (function ($project) { 
                return $project->getId(); 
            }, $projects);

            $sum = count($projects);

            Console::info('Executing delete function for chunk #' . $chunk . '. Found ' . $sum . ' projects');
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
    protected function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
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

            Console::info('Deleting chunk #' . $chunk . '. Found ' . $sum . ' documents');

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
     */
    protected function deleteCertificates(Document $document): void
    {
        $domain = $document->getAttribute('domain');
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;
        $checkTraversal = realpath($directory) === $directory;

        if ($domain && $checkTraversal && is_dir($directory)) {
            array_map('unlink', glob($directory . '/*.*'));
            rmdir($directory);
            Console::info("Deleted certificate files for {$domain}");
        } else {
            Console::info("No certificate files found for {$domain}");
        }
    }
}
