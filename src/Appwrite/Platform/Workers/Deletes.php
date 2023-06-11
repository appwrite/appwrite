<?php

namespace Appwrite\Platform\Workers;

use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Deletes extends Action
{
    public static function getName(): string
    {
        return 'deletes';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Deletes worker')
            ->inject('message')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->inject('deviceFiles')
            ->inject('deviceFunctions')
            ->inject('deviceBuilds')
            ->inject('deviceCache')
            ->callback(fn($message, $dbForConsole, $getProjectDB, $deviceFiles, $deviceFunctions, $deviceBuilds, $deviceCache) => $this->action($message, $dbForConsole, $getProjectDB, $deviceFiles, $deviceFunctions, $deviceBuilds, $deviceCache));
    }

    /**
     * @throws Exception
     * @throws \Throwable
     */
    public function action(Message $message, Database $dbForConsole, callable $getProjectDB, callable $deviceFiles, callable $deviceFunctions, callable $deviceBuilds, callable $deviceCache): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type     = $payload['type'] ?? '';
        $datetime = $payload['datetime'] ?? null;
        $hourlyUsageRetentionDatetime = $payload['hourlyUsageRetentionDatetime'] ?? null;
        $resource = $payload['resource'] ?? null;
        $document = new Document($payload['document'] ?? []);
        $project = new Document($payload['project'] ?? []);

        switch (strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                switch ($document->getCollection()) {
                    case DELETE_TYPE_DATABASES:
                        $this->deleteDatabase($getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_COLLECTIONS:
                        $this->deleteCollection($getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_PROJECTS:
                        $this->deleteProject($dbForConsole, $getProjectDB, $deviceFiles, $deviceFunctions, $deviceBuilds, $deviceCache, $document);
                        break;
                    case DELETE_TYPE_FUNCTIONS:
                        $this->deleteFunction($getProjectDB, $deviceFunctions, $deviceBuilds, $document, $project);
                        break;
                    case DELETE_TYPE_DEPLOYMENTS:
                        $this->deleteDeployment($getProjectDB, $deviceFunctions, $deviceBuilds, $document, $project);
                        break;
                    case DELETE_TYPE_USERS:
                        $this->deleteUser($getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_TEAMS:
                        $this->deleteMemberships($getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_BUCKETS:
                        $this->deleteBucket($getProjectDB, $deviceFiles, $document, $project);
                        break;
                    default:
                        if (\str_starts_with($document->getCollection(), 'database_')) {
                            $this->deleteCollection($getProjectDB, $document, $project);
                            break;
                        }
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                        break;
                }
                break;

            case DELETE_TYPE_EXECUTIONS:
                $this->deleteExecutionLogs($dbForConsole, $getProjectDB, $datetime);
                break;

            case DELETE_TYPE_AUDIT:
                if (!empty($datetime)) {
                    $this->deleteAuditLogs($dbForConsole, $getProjectDB, $datetime);
                }

                if (!$document->isEmpty()) {
                    $this->deleteAuditLogsByResource($getProjectDB, 'document/' . $document->getId(), $project);
                }

                break;

            case DELETE_TYPE_ABUSE:
                $this->deleteAbuseLogs($dbForConsole, $getProjectDB, $datetime);
                break;

            case DELETE_TYPE_REALTIME:
                $this->deleteRealtimeUsage($dbForConsole, $getProjectDB, $datetime);
                break;

            case DELETE_TYPE_SESSIONS:
                $this->deleteExpiredSessions($dbForConsole, $getProjectDB);
                break;

            case DELETE_TYPE_CERTIFICATES:
                $this->deleteCertificates($dbForConsole, $document);
                break;

            case DELETE_TYPE_USAGE:
                $this->deleteUsageStats($dbForConsole, $getProjectDB, $hourlyUsageRetentionDatetime);
                break;

            case DELETE_TYPE_CACHE_BY_RESOURCE:
                $this->deleteCacheByResource($dbForConsole, $getProjectDB, $resource);
                break;
            case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                $this->deleteCacheByDate($dbForConsole, $getProjectDB, $datetime);
                break;
            case DELETE_TYPE_SCHEDULES:
                $this->deleteSchedules($dbForConsole, $getProjectDB, $datetime);
                break;
            default:
                Console::error('No delete operation for type: ' . $type);
                break;
        }
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @return void
     * @throws Authorization
     * @throws \Throwable
     */
    protected function deleteSchedules(Database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        $this->listByGroup(
            'schedules',
            [
                Query::equal('region', [App::getEnv('_APP_REGION', 'default')]),
                Query::equal('resourceType', ['function']),
                Query::lessThanEqual('resourceUpdatedAt', $datetime),
                Query::equal('active', [false]),
            ],
            $dbForConsole,
            function (Document $document) use ($dbForConsole, $getProjectDB) {
                $project = $dbForConsole->getDocument('projects', $document->getAttribute('projectId'));

                if ($project->isEmpty()) {
                    Console::warning('Unable to delete schedule for function ' . $document->getAttribute('resourceId'));
                    return;
                }

                $function = $getProjectDB($project)->getDocument('functions', $document->getAttribute('resourceId'));

                if ($function->isEmpty()) {
                    $dbForConsole->deleteDocument('schedules', $document->getId());
                    Console::success('Deleting schedule for function ' . $document->getAttribute('resourceId'));
                }
            }
        );
    }

    /**
     * @param callable $getProjectDB
     * @param string $resource
     * @throws Exception
     */
    protected function deleteCacheByResource(Database $dbForConsole, callable $getProjectDB, string $resource): void
    {
        $this->deleteCacheFiles($dbForConsole, $getProjectDB, [
            Query::equal('resource', [$resource]),
        ]);
    }

    /**
     * @throws Exception
     */
    protected function deleteCacheByDate(Database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        $this->deleteCacheFiles($dbForConsole, $getProjectDB, [
            Query::lessThan('accessedAt', $datetime),
        ]);
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param array $query
     * @return void
     * @throws Exception
     */
    protected function deleteCacheFiles(Database $dbForConsole, callable $getProjectDB, array $query): void
    {
        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($query, $getProjectDB) {
            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
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
     * @param callable $getProjectDB
     * @param Document $document database document
     * @param Document $project
     * @throws Exception
     */
    protected function deleteDatabase(callable $getProjectDB, Document $document, Document $project): void
    {
        $databaseId = $document->getId();
        $dbForProject = $getProjectDB($project);

        $this->deleteByGroup('database_' . $document->getInternalId(), [], $dbForProject, function ($document) use ($getProjectDB, $project) {
            $this->deleteCollection($getProjectDB, $document, $project);
        });

        $dbForProject->deleteCollection('database_' . $document->getInternalId());
        $this->deleteAuditLogsByResource($getProjectDB, 'database/' . $databaseId, $project);
    }

    /**
     * @param callable $getProjectDB
     * @param Document $document teams document
     * @param Document $project
     * @throws Exception
     */
    protected function deleteCollection(callable $getProjectDB, Document $document, Document $project): void
    {
        $collectionId = $document->getId();
        $databaseId = $document->getAttribute('databaseId');
        $databaseInternalId = $document->getAttribute('databaseInternalId');
        $dbForProject = $getProjectDB($project);

        $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $document->getInternalId());
        $this->deleteByGroup('attributes', [
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId])
        ], $dbForProject);

        $this->deleteByGroup('indexes', [
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId])
        ], $dbForProject);

        $this->deleteAuditLogsByResource($getProjectDB, 'database/' . $databaseId . '/collection/' . $collectionId, $project);
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $hourlyUsageRetentionDatetime
     * @throws Exception
     */
    protected function deleteUsageStats(Database $dbForConsole, callable $getProjectDB, string $hourlyUsageRetentionDatetime)
    {
        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($getProjectDB, $hourlyUsageRetentionDatetime) {
            $dbForProject = $getProjectDB($project);
            // Delete Usage stats
            $this->deleteByGroup('stats', [
                Query::lessThan('time', $hourlyUsageRetentionDatetime),
                Query::equal('period', ['1h']),
            ], $dbForProject);
        });
    }

    /**
     * @param callable $getProjectDB
     * @param Document $document teams document
     * @param Document $project
     * @throws Exception
     */
    protected function deleteMemberships(callable $getProjectDB, Document $document, Document $project): void
    {
        $teamId = $document->getAttribute('teamId', '');

        // Delete Memberships
        $this->deleteByGroup('memberships', [
            Query::equal('teamId', [$teamId])
        ], $getProjectDB($project));
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param callable $deviceFiles
     * @param callable $deviceFunctions
     * @param callable $deviceBuilds
     * @param callable $deviceCache
     * @param Document $document project document
     * @throws Authorization
     */
    protected function deleteProject(Database $dbForConsole, callable $getProjectDB, callable $deviceFiles, callable $deviceFunctions, callable $deviceBuilds, callable $deviceCache, Document $document): void
    {
        $projectId = $document->getId();

        // Delete project domains and certificates
        $domains = $dbForConsole->find('domains', [
            Query::equal('projectInternalId', [$document->getInternalId()])
        ]);

        foreach ($domains as $domain) {
            $this->deleteCertificates($dbForConsole, $domain);
        }

        // Delete project tables
        $dbForProject = $getProjectDB($document);

        while (true) {
            $collections = $dbForProject->listCollections();

            if (empty($collections)) {
                break;
            }

            foreach ($collections as $collection) {
                $dbForProject->deleteCollection($collection->getId());
            }
        }

        // Delete metadata tables
        try {
            $dbForProject->deleteCollection('_metadata');
        } catch (Exception) {
            // Ignore: deleteCollection tries to delete a metadata entry after the collection is deleted,
            // which will throw an exception here because the metadata collection is already deleted.
        }

        // Delete all storage directories
        $uploads = $deviceFiles($projectId);
        $functions = $deviceFunctions($projectId);
        $builds = $deviceBuilds($projectId);
        $cache = $deviceCache($projectId);

        $uploads->delete($uploads->getRoot(), true);
        $functions->delete($functions->getRoot(), true);
        $builds->delete($builds->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    /**
     * @param callable $getProjectDB
     * @param Document $document user document
     * @param Document $project
     * @throws Exception
     */
    protected function deleteUser(callable $getProjectDB, Document $document, Document $project): void
    {
        $userId = $document->getId();
        $dbForProject = $getProjectDB($project);

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
     * @param database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteExecutionLogs(database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($getProjectDB, $datetime) {
            $dbForProject = $getProjectDB($project);
            // Delete Executions
            $this->deleteByGroup('executions', [
                Query::lessThan('$createdAt', $datetime)
            ], $dbForProject);
        });
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @return void
     * @throws Exception|\Throwable
     */
    protected function deleteExpiredSessions(Database $dbForConsole, callable $getProjectDB): void
    {

        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($dbForConsole, $getProjectDB) {
            $dbForProject = $getProjectDB($project);
            $project = $dbForConsole->getDocument('projects', $project->getId());
            $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $expired = DateTime::addSeconds(new \DateTime(), -1 * $duration);

            // Delete Sessions
            $this->deleteByGroup('sessions', [
                Query::lessThan('$createdAt', $expired)
            ], $dbForProject);
        });
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteRealtimeUsage(Database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($datetime, $getProjectDB) {
            $dbForProject = $getProjectDB($project);
            // Delete Dead Realtime Logs
            $this->deleteByGroup('realtime', [
                Query::lessThan('timestamp', $datetime)
            ], $dbForProject);
        });
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteAbuseLogs(Database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        if (empty($datetime)) {
            throw new Exception('Failed to delete audit logs. No datetime provided');
        }

        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($getProjectDB, $datetime) {
            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
            $timeLimit = new TimeLimit("", 0, 1, $dbForProject);
            $abuse = new Abuse($timeLimit);
            $status = $abuse->cleanup($datetime);
            if (!$status) {
                throw new Exception('Failed to delete Abuse logs for project ' . $projectId);
            }
        });
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @throws Exception
     */
    protected function deleteAuditLogs(Database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        if (empty($datetime)) {
            throw new Exception('Failed to delete audit logs. No datetime provided');
        }

        $this->deleteForProjectIds($dbForConsole, function (Document $project) use ($getProjectDB, $datetime) {
            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
            $audit = new Audit($dbForProject);
            $status = $audit->cleanup($datetime);
            if (!$status) {
                throw new Exception('Failed to delete Audit logs for project' . $projectId);
            }
        });
    }

    /**
     * @param callable $getProjectDB
     * @param string $resource
     * @param Document $project
     * @throws Exception
     */
    protected function deleteAuditLogsByResource(callable $getProjectDB, string $resource, Document $project): void
    {
        $dbForProject = $getProjectDB($project);

        $this->deleteByGroup(Audit::COLLECTION, [
            Query::equal('resource', [$resource])
        ], $dbForProject);
    }

    /**
     * @param callable $getProjectDB
     * @param callable $deviceFunctions
     * @param callable $deviceBuilds
     * @param Document $document function document
     * @param Document $project
     * @throws Exception
     */
    protected function deleteFunction(callable $getProjectDB, callable $deviceFunctions, callable $deviceBuilds, Document $document, Document $project): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);
        $functionId = $document->getId();

        /**
         * Delete Variables
         */
        Console::info("Deleting variables for function " . $functionId);
        $this->deleteByGroup('variables', [
            Query::equal('functionId', [$functionId])
        ], $dbForProject);

        /**
         * Delete Deployments
         */
        Console::info("Deleting deployments for function " . $functionId);
        $storageFunctions = $deviceFunctions($projectId);
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
        $storageBuilds = $deviceBuilds($projectId);
        foreach ($deploymentIds as $deploymentId) {
            $this->deleteByGroup('builds', [
                Query::equal('deploymentId', [$deploymentId])
            ], $dbForProject, function (Document $document) use ($storageBuilds, $deploymentId) {
                if ($storageBuilds->delete($document->getAttribute('outputPath', ''), true)) {
                    Console::success('Deleted build files: ' . $document->getAttribute('outputPath', ''));
                } else {
                    Console::error('Failed to delete build files: ' . $document->getAttribute('outputPath', ''));
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

        // TODO: Request executor to delete runtime
    }

    /**
     * @param callable $getProjectDB
     * @param callable $deviceFunctions
     * @param callable $deviceBuilds
     * @param Document $document deployment document
     * @param Document $project
     * @throws Exception
     */
    protected function deleteDeployment(callable $getProjectDB, callable $deviceFunctions, callable $deviceBuilds, Document $document, Document $project): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);
        $deploymentId = $document->getId();
        $functionId = $document->getAttribute('resourceId');

        /**
         * Delete deployment files
         */
        Console::info("Deleting deployment files for deployment " . $deploymentId);
        $storageFunctions = $deviceFunctions($projectId);
        if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
            Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
        } else {
            Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
        }

        /**
         * Delete builds
         */
        Console::info("Deleting builds for deployment " . $deploymentId);
        $storageBuilds = $deviceBuilds($projectId);
        $this->deleteByGroup('builds', [
            Query::equal('deploymentId', [$deploymentId])
        ], $dbForProject, function (Document $document) use ($storageBuilds) {
            if ($storageBuilds->delete($document->getAttribute('outputPath', ''), true)) {
                Console::success('Deleted build files: ' . $document->getAttribute('outputPath', ''));
            } else {
                Console::error('Failed to delete build files: ' . $document->getAttribute('outputPath', ''));
            }
        });

        // TODO: Request executor to delete runtime
    }


    /**
     * @param Document $document to be deleted
     * @param Database $database to delete it from
     * @param callable|null $callback to perform after document is deleted
     * @return bool
     * @throws Authorization
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
     * @param Database $dbForConsole
     * @param callable $callback
     * @throws Exception
     */
    protected function deleteForProjectIds(database $dbForConsole, callable $callback): void
    {
        // TODO: @Meldiron name of this method no longer matches. It does not delete, and it gives whole document
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $projects = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $projects = $dbForConsole->find('projects', [Query::limit($limit), Query::offset($chunk * $limit)]);

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
     * @param callable|null $callback
     * @throws Exception
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
     * @param Database $dbForConsole
     * @param Document $document certificates document
     * @throws Authorization
     */
    protected function deleteCertificates(Database $dbForConsole, Document $document): void
    {
        // If domain has certificate generated
        if (isset($document['certificateId'])) {
            $domainUsingCertificate = $dbForConsole->findOne('domains', [
                Query::equal('certificateId', [$document['certificateId']])
            ]);

            if (!$domainUsingCertificate) {
                $mainDomain = App::getEnv('_APP_DOMAIN_TARGET', '');
                if ($mainDomain === $document->getAttribute('domain')) {
                    $domainUsingCertificate = $mainDomain;
                }
            }

            // If certificate is still used by some domain, mark we can't delete.
            // Current domain should not be found, because we only have copy. Original domain is already deleted from database.
            if ($domainUsingCertificate) {
                Console::warning("Skipping certificate deletion, because a domain is still using it.");
                return;
            }
        }

        $domain = $document->getAttribute('domain');
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;
        $checkTraversal = realpath($directory) === $directory;

        if ($domain && $checkTraversal && is_dir($directory)) {
            // Delete certificate document, so Appwrite is aware of change
            if (isset($document['certificateId'])) {
                $dbForConsole->deleteDocument('certificates', $document['certificateId']);
            }

            // Delete files, so Traefik is aware of change
            array_map('unlink', glob($directory . '/*.*'));
            rmdir($directory);
            Console::info("Deleted certificate files for {$domain}");
        } else {
            Console::info("No certificate files found for {$domain}");
        }
    }

    /**
     * @param callable $getProjectDB
     * @param callable $deviceFiles
     * @param Document $document
     * @param Document $project
     * @return void
     */
    protected function deleteBucket(callable $getProjectDB, callable $deviceFiles, Document $document, Document $project): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);

        $dbForProject->deleteCollection('bucket_' . $document->getInternalId());

        $device = $deviceFiles($projectId);

        $device->deletePath($document->getId());
    }
}
