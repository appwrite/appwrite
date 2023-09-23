<?php

use Appwrite\Auth\Auth;
use Executor\Executor;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Appwrite\Resque\Worker;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Audit\Audit;
use Utopia\Database\DateTime;
use Utopia\Storage\Device;

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
                        if ($project->getId() === 'console') {
                            $this->deleteProjectsByTeam($document);
                        }
                        break;
                    case DELETE_TYPE_BUCKETS:
                        $this->deleteBucket($document, $project);
                        break;
                    case DELETE_TYPE_INSTALLATIONS:
                        $this->deleteInstallation($document, $project);
                        break;
                    case DELETE_TYPE_RULES:
                        $this->deleteRule($document);
                        break;
                    default:
                        if (\str_starts_with($document->getCollection(), 'database_')) {
                            $this->deleteCollection($document, $project);
                            break;
                        }
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
                $this->deleteCacheByResource($project, $this->args['resource']);
                break;
            case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                $this->deleteCacheByDate($this->args['datetime']);
                break;
            case DELETE_TYPE_SCHEDULES:
                $this->deleteSchedules($this->args['datetime']);
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
                    $this->getConsoleDB()->deleteDocument('schedules', $document->getId());
                    Console::success('Deleted schedule for deleted project ' . $document->getAttribute('projectId'));
                    return;
                }

                $function = $this->getProjectDB($project)->getDocument('functions', $document->getAttribute('resourceId'));

                if ($function->isEmpty()) {
                    $this->getConsoleDB()->deleteDocument('schedules', $document->getId());
                    Console::success('Deleted schedule for function ' . $document->getAttribute('resourceId'));
                }
            }
        );
    }

    /**
     * @param Document $project
     * @param string $resource
     * @throws Exception
     */
    protected function deleteCacheByResource(Document $project, string $resource): void
    {
        $projectId = $project->getId();
        $dbForProject = $this->getProjectDB($project);
        $document = $dbForProject->findOne('cache', [Query::equal('resource', [$resource])]);

        if ($document) {
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
            );

            $this->deleteById(
                $document,
                $dbForProject,
                function ($document) use ($cache, $projectId) {
                    $path = APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId . DIRECTORY_SEPARATOR . $document->getId();

                    if ($cache->purge($document->getId())) {
                        Console::success('Deleting cache file: ' . $path);
                    } else {
                        Console::error('Failed to delete cache file: ' . $path);
                    }
                }
            );
        }
    }

    /**
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteCacheByDate(string $datetime): void
    {
        $this->deleteForProjectIds(function (Document $project) use ($datetime) {
            $projectId = $project->getId();
            $dbForProject = $this->getProjectDB($project);
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
            );

            $query = [
                Query::lessThan('accessedAt', $datetime),
            ];

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
     * @param Document $project
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
        $collectionInternalId = $document->getInternalId();
        $databaseId = $document->getAttribute('databaseId');
        $databaseInternalId = $document->getAttribute('databaseInternalId');

        $dbForProject = $this->getProjectDB($project);

        $relationships = \array_filter(
            $document->getAttribute('attributes'),
            fn ($attribute) => $attribute['type'] === Database::VAR_RELATIONSHIP
        );

        foreach ($relationships as $relationship) {
            if (!$relationship['twoWay']) {
                continue;
            }
            $relatedCollection = $dbForProject->getDocument('database_' . $databaseInternalId, $relationship['relatedCollection']);
            $dbForProject->deleteDocument('attributes', $databaseInternalId . '_' . $relatedCollection->getInternalId() . '_' . $relationship['twoWayKey']);
            $dbForProject->deleteCachedDocument('database_' . $databaseInternalId, $relatedCollection->getId());
            $dbForProject->deleteCachedCollection('database_' . $databaseInternalId . '_collection_' . $relatedCollection->getInternalId());
        }

        $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $document->getInternalId());

        $this->deleteByGroup('attributes', [
            Query::equal('databaseInternalId', [$databaseInternalId]),
            Query::equal('collectionInternalId', [$collectionInternalId])
        ], $dbForProject);

        $this->deleteByGroup('indexes', [
            Query::equal('databaseInternalId', [$databaseInternalId]),
            Query::equal('collectionInternalId', [$collectionInternalId])
        ], $dbForProject);

        $this->deleteAuditLogsByResource('database/' . $databaseId . '/collection/' . $collectionId, $project);
    }

    /**
     * @param string $hourlyUsageRetentionDatetime
     * @throws Exception
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
        $dbForProject = $this->getProjectDB($project);
        $teamInternalId = $document->getInternalId();

        // Delete Memberships
        $this->deleteByGroup(
            'memberships',
            [
                Query::equal('teamInternalId', [$teamInternalId])
            ],
            $dbForProject,
            function (Document $membership) use ($dbForProject) {
                $userId = $membership->getAttribute('userId');
                $dbForProject->deleteCachedDocument('users', $userId);
            }
        );
    }

    /**
     * @param \Utopia\Database\Document $document
     * @return void
     * @throws \Exception
     */
    protected function deleteProjectsByTeam(Document $document): void
    {
        $dbForConsole = $this->getConsoleDB();

        $projects = $dbForConsole->find('projects', [
            Query::equal('teamInternalId', [$document->getInternalId()])
        ]);

        foreach ($projects as $project) {
            $this->deleteProject($project);
            $dbForConsole->deleteDocument('projects', $project->getId());
        }
    }

    /**
     * @param Document $document project document
     * @throws Exception
     */
    protected function deleteProject(Document $document): void
    {
        $projectId = $document->getId();
        $projectInternalId = $document->getInternalId();

        $dbForConsole = $this->getConsoleDB();

        // Delete project tables
        $dbForProject = $this->getProjectDB($document);

        while (true) {
            $collections = $dbForProject->listCollections();

            if (empty($collections)) {
                break;
            }

            foreach ($collections as $collection) {
                $dbForProject->deleteCollection($collection->getId());
            }
        }

        // Delete Platforms
        $this->deleteByGroup('platforms', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete project and function rules
        $this->deleteByGroup('rules', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole, function (Document $document) {
            $this->deleteRule($document);
        });

        // Delete Keys
        $this->deleteByGroup('keys', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete Webhooks
        $this->deleteByGroup('webhooks', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete metadata tables
        try {
            $dbForProject->deleteCollection('_metadata');
        } catch (Exception) {
            // Ignore: deleteCollection tries to delete a metadata entry after the collection is deleted,
            // which will throw an exception here because the metadata collection is already deleted.
        }

        // Delete all storage directories
        $uploads = $this->getFilesDevice($projectId);
        $functions = $this->getFunctionsDevice($projectId);
        $builds = $this->getBuildsDevice($projectId);
        $cache = $this->getCacheDevice($projectId);

        $uploads->delete($uploads->getRoot(), true);
        $functions->delete($functions->getRoot(), true);
        $builds->delete($builds->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    /**
     * @param Document $document user document
     * @param Document $project
     */
    protected function deleteUser(Document $document, Document $project): void
    {
        $userId = $document->getId();
        $userInternalId = $document->getInternalId();

        $dbForProject = $this->getProjectDB($project);

        // Delete all sessions of this user from the sessions table and update the sessions field of the user record
        $this->deleteByGroup('sessions', [
            Query::equal('userInternalId', [$userInternalId])
        ], $dbForProject);

        $dbForProject->deleteCachedDocument('users', $userId);

        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup('memberships', [
            Query::equal('userInternalId', [$userInternalId])
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
            Query::equal('userInternalId', [$userInternalId])
        ], $dbForProject);

        // Delete identities
        $this->deleteByGroup('identities', [
            Query::equal('userInternalId', [$userInternalId])
        ], $dbForProject);
    }

    /**
     * @param string $datetime
     * @throws Exception
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
     * @throws Exception
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
        $functionInternalId = $document->getInternalId();

        /**
         * Delete rules
         */
        Console::info("Deleting rules for function " . $functionId);
        $this->deleteByGroup('rules', [
            Query::equal('resourceType', ['function']),
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::equal('projectInternalId', [$project->getInternalId()])
        ], $dbForConsole, function (Document $document) use ($project) {
            $this->deleteRule($document, $project);
        });

        /**
         * Delete Variables
         */
        Console::info("Deleting variables for function " . $functionId);
        $this->deleteByGroup('variables', [
            Query::equal('resourceType', ['function']),
            Query::equal('resourceInternalId', [$functionInternalId])
        ], $dbForProject);

        /**
         * Delete Deployments
         */
        Console::info("Deleting deployments for function " . $functionId);
        $deviceFunctions = $this->getFunctionsDevice($projectId);
        $deploymentInternalIds = [];
        $this->deleteByGroup('deployments', [
            Query::equal('resourceInternalId', [$functionInternalId])
        ], $dbForProject, function (Document $document) use ($deviceFunctions, &$deploymentInternalIds) {
            $deploymentInternalIds[] = $document->getInternalId();
            $this->deleteDeploymentFiles($deviceFunctions, $document);
        });

        /**
         * Delete builds
         */
        Console::info("Deleting builds for function " . $functionId);
        $deviceBuilds = $this->getBuildsDevice($projectId);
        foreach ($deploymentInternalIds as $deploymentInternalId) {
            $this->deleteByGroup('builds', [
                Query::equal('deploymentInternalId', [$deploymentInternalId])
            ], $dbForProject, function (Document $document) use ($deviceBuilds) {
                $this->deleteBuildFiles($deviceBuilds, $document);
            });
        }

        /**
         * Delete Executions
         */
        Console::info("Deleting executions for function " . $functionId);
        $this->deleteByGroup('executions', [
            Query::equal('functionInternalId', [$functionInternalId])
        ], $dbForProject);

        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete all deployment containers for function " . $functionId);
        $this->deleteRuntimes($document, $project);
    }

    protected function deleteDeploymentFiles(Device $device, Document $deployment)
    {
        $deploymentId = $deployment->getId();
        $deploymentPath = $deployment->getAttribute('path', '');

        if (empty($deploymentPath)) {
            Console::info("No deployment files for deployment " . $deploymentId);
            return;
        }

        Console::info("Deleting deployment files for deployment " . $deploymentId);

        try {
            if ($device->delete($deploymentPath, true)) {
                Console::success('Deleted deployment files: ' . $deploymentPath);
            } else {
                Console::error('Failed to delete deployment files: ' . $deploymentPath);
            }
        } catch (\Throwable $th) {
            Console::error('Failed to delete deployment files: ' . $deploymentPath);
            Console::error('[Error] Type: ' . get_class($th));
            Console::error('[Error] Message: ' . $th->getMessage());
            Console::error('[Error] File: ' . $th->getFile());
            Console::error('[Error] Line: ' . $th->getLine());
        }
    }

    protected function deleteBuildFiles(Device $device, Document $build)
    {
        $buildId = $build->getId();
        $buildPath = $build->getAttribute('path', '');

        if (empty($buildPath)) {
            Console::info("No build files for build " . $buildId);
            return;
        }

        try {
            if ($device->delete($buildPath, true)) {
                Console::success('Deleted build files: ' . $buildPath);
            } else {
                Console::error('Failed to delete build files: ' . $buildPath);
            }
        } catch (\Throwable $th) {
            Console::error('Failed to delete deployment files: ' . $buildPath);
            Console::error('[Error] Type: ' . get_class($th));
            Console::error('[Error] Message: ' . $th->getMessage());
            Console::error('[Error] File: ' . $th->getFile());
            Console::error('[Error] Line: ' . $th->getLine());
        }
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
        $deploymentInternalId = $document->getInternalId();

        /**
         * Delete deployment files
         */
        $deviceFunctions = $this->getFunctionsDevice($projectId);
        $this->deleteDeploymentFiles($deviceFunctions, $document);

        /**
         * Delete builds
         */
        Console::info("Deleting builds for deployment " . $deploymentId);
        $deviceBuilds = $this->getBuildsDevice($projectId);
        $this->deleteByGroup('builds', [
            Query::equal('deploymentInternalId', [$deploymentInternalId])
        ], $dbForProject, function (Document $document) use ($deviceBuilds) {
            $this->deleteBuildFiles($deviceBuilds, $document);
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
     * @param callable|null $callback to perform after document is deleted
     *
     * @return bool
     * @throws \Utopia\Database\Exception\Authorization
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
     * @throws Exception
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
     * @param array $queries
     * @param Database $database
     * @param callable|null $callback
     * @throws Exception
     */
    protected function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        try {
            while ($sum === $limit) {
                $chunk++;

                $results = $database->find($collection, \array_merge([Query::limit($limit)], $queries));

                $sum = count($results);

                Console::info('Deleting chunk #' . $chunk . '. Found ' . $sum . ' documents in collection ' . $database->getNamespace() . '_' . $collection);

                foreach ($results as $document) {
                    $this->deleteById($document, $database, $callback);
                    $count++;
                }
            }
        } catch (\Exception $e) {
            Console::error($e->getMessage());
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
        $cursor = null;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            $mergedQueries = \array_merge([Query::limit($limit)], $queries);
            if ($cursor instanceof Document) {
                $mergedQueries[] = Query::cursorAfter($cursor);
            }

            $results = $database->find($collection, $mergedQueries);

            $sum = count($results);

            if ($sum > 0) {
                $cursor = $results[$sum - 1];
            }

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
    protected function deleteRule(Document $document): void
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

    protected function deleteInstallation(Document $document, Document $project)
    {
        $dbForProject = $this->getProjectDB($project);
        $dbForConsole = $this->getConsoleDB();

        $this->listByGroup('functions', [
            Query::equal('installationInternalId', [$document->getInternalId()])
        ], $dbForProject, function ($function) use ($dbForProject, $dbForConsole) {
            $dbForConsole->deleteDocument('repositories', $function->getAttribute('repositoryId'));

            $function = $function
                ->setAttribute('installationId', '')
                ->setAttribute('installationInternalId', '')
                ->setAttribute('providerRepositoryId', '')
                ->setAttribute('providerBranch', '')
                ->setAttribute('providerSilentMode', false)
                ->setAttribute('providerRootDirectory', '')
                ->setAttribute('repositoryId', '')
                ->setAttribute('repositoryInternalId', '');
            $dbForProject->updateDocument('functions', $function->getId(), $function);
        });
    }

    protected function deleteRuntimes(?Document $function, Document $project)
    {
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));

        $deleteByFunction = function (Document $function) use ($project, $executor) {
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

        if ($function !== null) {
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
