<?php

use Appwrite\Auth\Auth;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Appwrite\Resque\Worker;
use Executor\Executor;
use Utopia\Storage\Device\Local;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Audit\Audit;
use Utopia\Database\DateTime;

require_once __DIR__ . '/../init.php';

Console::title('Deletes V1 Worker');
Console::success(APP_NAME . ' deletes worker v1 has started' . "\n");

class DeletesV1 extends Worker
{
    public function getName(): string
    {
        return "deletes";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $project = new Document($this->args['project'] ?? []);
        $type = $this->args['type'] ?? '';
        switch (strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                $document = new Document($this->args['document'] ?? []);

                switch ($document->getCollection()) {
                    case DELETE_TYPE_DATABASES:
                        $this->deleteDatabase($document, $project);
                        break;
                    case DELETE_TYPE_COLLECTIONS:
                        $this->deleteCollection($document, $project);
                        break;
                    case DELETE_TYPE_PROJECTS:
                        $this->deleteProject($document);
                        break;
                    case DELETE_TYPE_FUNCTIONS:
                        $this->deleteFunction($document, $project);
                        break;
                    case DELETE_TYPE_DEPLOYMENTS:
                        $this->deleteDeployment($document, $project);
                        break;
                    case DELETE_TYPE_USERS:
                        $this->deleteUser($document, $project);
                        break;
                    case DELETE_TYPE_TEAMS:
                        $this->deleteMemberships($document, $project);
                        break;
                    case DELETE_TYPE_BUCKETS:
                        $this->deleteBucket($document, $project);
                    case DELETE_TYPE_RULES:
                        $this->deleteRule($document, $project);
                        break;
                    default:
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                        break;
                }
                break;

            case DELETE_TYPE_EXECUTIONS:
                $this->deleteExecutionLogs($this->args['datetime']);
                break;

            case DELETE_TYPE_AUDIT:
                $datetime = $this->args['datetime'] ?? null;
                if (!empty($datetime)) {
                    $this->deleteAuditLogs($datetime);
                }

                $document = new Document($this->args['document'] ?? []);

                if (!$document->isEmpty()) {
                    $this->deleteAuditLogsByResource('document/' . $document->getId(), $project);
                }

                break;

            case DELETE_TYPE_ABUSE:
                $this->deleteAbuseLogs($this->args['datetime']);
                break;

            case DELETE_TYPE_REALTIME:
                $this->deleteRealtimeUsage($this->args['datetime']);
                break;

            case DELETE_TYPE_SESSIONS:
                $this->deleteExpiredSessions();
                break;

            case DELETE_TYPE_USAGE:
                $this->deleteUsageStats($this->args['hourlyUsageRetentionDatetime']);
                break;

            case DELETE_TYPE_CACHE_BY_RESOURCE:
                $this->deleteCacheByResource($this->args['resource']);
                break;
            case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                $this->deleteCacheByDate();
                break;
            case DELETE_TYPE_SCHEDULES:
                $this->deleteSchedules($this->args['datetime']);
                break;
            case DELETE_TYPE_RUNTIMES:
                $function = $this->args['function'] == null ? null : new Document($this->args['function']);
                $this->deleteRuntimes($function, $project);
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
     * @throws Exception
     */
    protected function deleteSchedules(string $datetime): void
    {
        $this->listByGroup(
            'schedules',
            [
                Query::equal('region', [App::getEnv('_APP_REGION', 'default')]),
                Query::equal('resourceType', ['function']),
                Query::lessThanEqual('resourceUpdatedAt', $datetime),
                Query::equal('active', [false]),
            ],
            $this->getConsoleDB(),
            function (Document $document) {
                $project = $this->getConsoleDB()->getDocument('projects', $document->getAttribute('projectId'));

                if ($project->isEmpty()) {
                    Console::warning('Unable to delete schedule for function ' . $document->getAttribute('resourceId'));
                    return;
                }

                $function = $this->getProjectDB($project)->getDocument('functions', $document->getAttribute('resourceId'));

                if ($function->isEmpty()) {
                    $this->getConsoleDB()->deleteDocument('schedules', $document->getId());
                    Console::success('Deleting schedule for function ' . $document->getAttribute('resourceId'));
                }
            }
        );
    }

    /**
     * @param string $resource
     */
    protected function deleteCacheByResource(string $resource): void
    {
        $this->deleteCacheFiles([
            Query::equal('resource', [$resource]),
        ]);
    }

    protected function deleteCacheByDate(): void
    {
        $this->deleteCacheFiles([
            Query::lessThan('accessedAt', $this->args['datetime']),
        ]);
    }

    protected function deleteCacheFiles($query): void
    {
        $this->deleteForProjectIds(function (Document $project) use ($query) {

            $projectId = $project->getId();
            $dbForProject = $this->getProjectDB($project);
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
            );

            $this->deleteByGroup(
                'cache',
                $query,
                $dbForProject,
                function (Document $document) use ($cache, $projectId) {
                    $path = APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId . DIRECTORY_SEPARATOR . $document->getId();

                    if ($cache->purge($document->getId())) {
                        Console::success('Deleting cache file: ' . $path);
                    } else {
                        Console::error('Failed to delete cache file: ' . $path);
                    }
                }
            );
        });
    }

    /**
     * @param Document $document database document
     * @param Document $projectId
     */
    protected function deleteDatabase(Document $document, Document $project): void
    {
        $databaseId = $document->getId();
        $projectId = $project->getId();

        $dbForProject = $this->getProjectDB($project);

        $this->deleteByGroup('database_' . $document->getInternalId(), [], $dbForProject, function ($document) use ($project) {
            $this->deleteCollection($document, $project);
        });

        $dbForProject->deleteCollection('database_' . $document->getInternalId());

        $this->deleteAuditLogsByResource('database/' . $databaseId, $project);
    }

    /**
     * @param Document $document teams document
     * @param Document $project
     */
    protected function deleteCollection(Document $document, Document $project): void
    {
        $collectionId = $document->getId();
        $databaseId = $document->getAttribute('databaseId');
        $databaseInternalId = $document->getAttribute('databaseInternalId');

        $dbForProject = $this->getProjectDB($project);

        $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $document->getInternalId());

        $this->deleteByGroup('attributes', [
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId])
        ], $dbForProject);

        $this->deleteByGroup('indexes', [
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId])
        ], $dbForProject);

        $this->deleteAuditLogsByResource('database/' . $databaseId . '/collection/' . $collectionId, $project);
    }

    /**
     * @param string $hourlyUsageRetentionDatetime
     */
    protected function deleteUsageStats(string $hourlyUsageRetentionDatetime)
    {
        $this->deleteForProjectIds(function (Document $project) use ($hourlyUsageRetentionDatetime) {
            $dbForProject = $this->getProjectDB($project);
            // Delete Usage stats
            $this->deleteByGroup('stats', [
                Query::lessThan('time', $hourlyUsageRetentionDatetime),
                Query::equal('period', ['1h']),
            ], $dbForProject);
        });
    }

    /**
     * @param Document $document teams document
     * @param Document $project
     */
    protected function deleteMemberships(Document $document, Document $project): void
    {
        $teamId = $document->getAttribute('teamId', '');

        // Delete Memberships
        $this->deleteByGroup('memberships', [
            Query::equal('teamId', [$teamId])
        ], $this->getProjectDB($project));
    }

    /**
     * @param Document $document project document
     */
    protected function deleteProject(Document $document): void
    {
        $projectId = $document->getId();

        // Delete all DBs
        $this->getProjectDB($document)->delete($projectId);

        // Delete all storage directories
        $uploads = $this->getFilesDevice($document->getId());
        $cache = new Local(APP_STORAGE_CACHE . '/app-' . $document->getId());

        $uploads->delete($uploads->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    /**
     * @param Document $document user document
     * @param Document $project
     */
    protected function deleteUser(Document $document, Document $project): void
    {
        $userId = $document->getId();

        $dbForProject = $this->getProjectDB($project);

        // Delete all sessions of this user from the sessions table and update the sessions field of the user record
        $this->deleteByGroup('sessions', [
            Query::equal('userId', [$userId])
        ], $dbForProject);

        $dbForProject->deleteCachedDocument('users', $userId);

        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup('memberships', [
            Query::equal('userId', [$userId])
        ], $dbForProject, function (Document $document) use ($dbForProject) {
            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $dbForProject->getDocument('teams', $teamId);
                if (!$team->isEmpty()) {
                    $team = $dbForProject->updateDocument(
                        'teams',
                        $teamId,
                        // Ensure that total >= 0
                            $team->setAttribute('total', \max($team->getAttribute('total', 0) - 1, 0))
                    );
                }
            }
        });

        // Delete tokens
        $this->deleteByGroup('tokens', [
            Query::equal('userId', [$userId])
        ], $dbForProject);
    }

    /**
     * @param string $datetime
     */
    protected function deleteExecutionLogs(string $datetime): void
    {
        $this->deleteForProjectIds(function (Document $project) use ($datetime) {
            $dbForProject = $this->getProjectDB($project);
            // Delete Executions
            $this->deleteByGroup('executions', [
                Query::lessThan('$createdAt', $datetime)
            ], $dbForProject);
        });
    }

    protected function deleteExpiredSessions(): void
    {
        $consoleDB = $this->getConsoleDB();

        $this->deleteForProjectIds(function (Document $project) use ($consoleDB) {
            $dbForProject = $this->getProjectDB($project);

            $project = $consoleDB->getDocument('projects', $project->getId());
            $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $expired = DateTime::addSeconds(new \DateTime(), -1 * $duration);

            // Delete Sessions
            $this->deleteByGroup('sessions', [
                Query::lessThan('$createdAt', $expired)
            ], $dbForProject);
        });
    }

    /**
     * @param string $datetime
     */
    protected function deleteRealtimeUsage(string $datetime): void
    {
        $this->deleteForProjectIds(function (Document $project) use ($datetime) {
            $dbForProject = $this->getProjectDB($project);
            // Delete Dead Realtime Logs
            $this->deleteByGroup('realtime', [
                Query::lessThan('timestamp', $datetime)
            ], $dbForProject);
        });
    }

    /**
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteAbuseLogs(string $datetime): void
    {
        if (empty($datetime)) {
            throw new Exception('Failed to delete audit logs. No datetime provided');
        }

        $this->deleteForProjectIds(function (Document $project) use ($datetime) {
            $projectId = $project->getId();
            $dbForProject = $this->getProjectDB($project);
            $timeLimit = new TimeLimit("", 0, 1, $dbForProject);
            $abuse = new Abuse($timeLimit);
            $status = $abuse->cleanup($datetime);
            if (!$status) {
                throw new Exception('Failed to delete Abuse logs for project ' . $projectId);
            }
        });
    }

    /**
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteAuditLogs(string $datetime): void
    {
        if (empty($datetime)) {
            throw new Exception('Failed to delete audit logs. No datetime provided');
        }

        $this->deleteForProjectIds(function (Document $project) use ($datetime) {
            $projectId = $project->getId();
            $dbForProject = $this->getProjectDB($project);
            $audit = new Audit($dbForProject);
            $status = $audit->cleanup($datetime);
            if (!$status) {
                throw new Exception('Failed to delete Audit logs for project' . $projectId);
            }
        });
    }

    /**
     * @param string $resource
     * @param Document $project
     */
    protected function deleteAuditLogsByResource(string $resource, Document $project): void
    {
        $dbForProject = $this->getProjectDB($project);

        $this->deleteByGroup(Audit::COLLECTION, [
            Query::equal('resource', [$resource])
        ], $dbForProject);
    }

    /**
     * @param Document $document function document
     * @param Document $project
     */
    protected function deleteFunction(Document $document, Document $project): void
    {
        $projectId = $project->getId();
        $dbForProject = $this->getProjectDB($project);
        $dbForConsole = $this->getConsoleDB();
        $functionId = $document->getId();

        /**
         * Delete routes
         */
        Console::info("Deleting routes for function " . $functionId);
        $this->deleteByGroup('rules', [
            Query::equal('resourceType', ['function']),
            Query::equal('resourceInternalId', [$document->getInternalId()]),
            Query::equal('projectInternalId', [$project->getInternalId()])
        ], $dbForConsole, function(Document $document) use ($project) {
            $this->deleteRule($document, $project);
        });

        /**
         * Delete Variables
         */
        Console::info("Deleting variables for function " . $functionId);
        $this->deleteByGroup('variables', [
            Query::equal('resourceType', ['function']),
            Query::equal('resourceInternalId', [$document->getInternalId()])
        ], $dbForProject);

        /**
         * Delete Deployments
         */
        Console::info("Deleting deployments for function " . $functionId);
        $storageFunctions = $this->getFunctionsDevice($projectId);
        $deploymentIds = [];
        $this->deleteByGroup('deployments', [
            Query::equal('resourceId', [$functionId])
        ], $dbForProject, function (Document $document) use ($storageFunctions, &$deploymentIds) {
            $deploymentIds[] = $document->getId();
            if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
                Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
            } else {
                Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
            }
        });

        /**
         * Delete builds
         */
        Console::info("Deleting builds for function " . $functionId);
        $storageBuilds = $this->getBuildsDevice($projectId);
        foreach ($deploymentIds as $deploymentId) {
            $this->deleteByGroup('builds', [
                Query::equal('deploymentId', [$deploymentId])
            ], $dbForProject, function (Document $document) use ($storageBuilds, $deploymentId) {
                if ($storageBuilds->delete($document->getAttribute('path', ''), true)) {
                    Console::success('Deleted build files: ' . $document->getAttribute('path', ''));
                } else {
                    Console::error('Failed to delete build files: ' . $document->getAttribute('path', ''));
                }
            });
        }

        /**
         * Delete Executions
         */
        Console::info("Deleting executions for function " . $functionId);
        $this->deleteByGroup('executions', [
            Query::equal('functionId', [$functionId])
        ], $dbForProject);

        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete all deployment containers for function " . $functionId);
        $this->deleteRuntimes($document, $project);
    }

    /**
     * @param Document $document deployment document
     * @param Document $project
     */
    protected function deleteDeployment(Document $document, Document $project): void
    {
        $projectId = $project->getId();
        $dbForProject = $this->getProjectDB($project);
        $deploymentId = $document->getId();
        $functionId = $document->getAttribute('resourceId');

        /**
         * Delete deployment files
         */
        Console::info("Deleting deployment files for deployment " . $deploymentId);
        $storageFunctions = $this->getFunctionsDevice($projectId);
        if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
            Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
        } else {
            Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
        }

        /**
         * Delete builds
         */
        Console::info("Deleting builds for deployment " . $deploymentId);
        $storageBuilds = $this->getBuildsDevice($projectId);
        $this->deleteByGroup('builds', [
            Query::equal('deploymentId', [$deploymentId])
        ], $dbForProject, function (Document $document) use ($storageBuilds) {
            if ($storageBuilds->delete($document->getAttribute('path', ''), true)) {
                Console::success('Deleted build files: ' . $document->getAttribute('path', ''));
            } else {
                Console::error('Failed to delete build files: ' . $document->getAttribute('path', ''));
            }
        });


        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete deployment container for deployment " . $deploymentId);
        $this->deleteRuntimes($document, $project);
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
        if ($database->deleteDocument($document->getCollection(), $document->getId())) {
            Console::success('Deleted document "' . $document->getId() . '" successfully');

            if (is_callable($callback)) {
                $callback($document);
            }

            return true;
        } else {
            Console::error('Failed to delete document: ' . $document->getId());
            return false;
        }
    }

    /**
     * @param callable $callback
     */
    protected function deleteForProjectIds(callable $callback): void
    {
        // TODO: @Meldiron name of this method no longer matches. It does not delete, and it gives whole document
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $projects = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $projects = $this->getConsoleDB()->find('projects', [Query::limit($limit), Query::offset($chunk * $limit)]);

            $chunk++;

            /** @var string[] $projectIds */
            $sum = count($projects);

            Console::info('Executing delete function for chunk #' . $chunk . '. Found ' . $sum . ' projects');
            foreach ($projects as $project) {
                $callback($project);
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

        while ($sum === $limit) {
            $chunk++;

            $results = $database->find($collection, \array_merge([Query::limit($limit)], $queries));

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
     * @param string $collection collectionID
     * @param Query[] $queries
     * @param Database $database
     * @param callable $callback
     */
    protected function listByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            $results = $database->find($collection, \array_merge([Query::limit($limit)], $queries));

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }

                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::info("Listed {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    /**
     * @param Document $document rule document
     * @param Document $project project document
     */
    protected function deleteRule(Document $document, Document $project): void
    {
        $consoleDB = $this->getConsoleDB();

        $domain = $document->getAttribute('domain');
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;
        $checkTraversal = realpath($directory) === $directory;

        if ($checkTraversal && is_dir($directory)) {
            // Delete files, so Traefik is aware of change
            array_map('unlink', glob($directory . '/*.*'));
            rmdir($directory);
            Console::info("Deleted certificate files for {$domain}");
        } else {
            Console::info("No certificate files found for {$domain}");
        }

        // Delete certificate document, so Appwrite is aware of change
        if (isset($document['certificateId'])) {
            $consoleDB->deleteDocument('certificates', $document['certificateId']);
        }
    }

    protected function deleteBucket(Document $document, Document $project)
    {
        $projectId = $project->getId();
        $dbForProject = $this->getProjectDB($project);
        $dbForProject->deleteCollection('bucket_' . $document->getInternalId());

        $device = $this->getFilesDevice($projectId);

        $device->deletePath($document->getId());
    }

    protected function deleteRuntimes(?Document $function, Document $project) {
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));

        $deleteByFunction = function(Document $function) use ($project, $executor) {
            $this->listByGroup(
                'deployments',
                [
                    Query::equal('resourceInternalId', [$function->getInternalId()]),
                    Query::equal('resourceType', ['functions']),
                ],
                $this->getProjectDB($project),
                function (Document $deployment) use ($project, $executor) {
                    $deploymentId = $deployment->getId();

                    try {
                        $executor->deleteRuntime($project->getId(), $deploymentId);
                        Console::info("Runtime for deployment {$deploymentId} deleted.");
                    } catch (Throwable $th) {
                        Console::warning("Runtime for deployment {$deploymentId} skipped:");
                        Console::error('[Error] Type: ' . get_class($th));
                        Console::error('[Error] Message: ' . $th->getMessage());
                        Console::error('[Error] File: ' . $th->getFile());
                        Console::error('[Error] Line: ' . $th->getLine());
                    }
                }
            );
        };

        if($function !== null) {
            // Delete function runtimes
            $deleteByFunction($function);
        } else {
            // Delete all project runtimes
            $this->listByGroup(
                'functions',
                [],
                $this->getProjectDB($project),
                function (Document $function) use ($deleteByFunction) {
                    $deleteByFunction($function);
                }
            );
        }
    }
}
