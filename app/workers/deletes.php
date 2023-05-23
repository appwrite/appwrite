<?php

require_once __DIR__ . '/../worker.php';

use Appwrite\Auth\Auth;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Queue\Server;
use Utopia\Storage\Device\Local;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Audit\Audit;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;

Authorization::disable();
Authorization::setDefaultStatus(false);

    /**
     * @throws Exception
     */
Server::setResource('deleteSchedules', function ($deleteCacheFiles, $dbForConsole, $getProjectDB, $listByGroup) {
    return function (string $datetime) use ($deleteCacheFiles, $dbForConsole, $getProjectDB, $listByGroup) {

        $listByGroup(
            'schedules',
            [
                Query::equal('region', [App::getEnv('_APP_REGION', 'default')]),
                Query::equal('resourceType', ['function']),
                Query::lessThanEqual('resourceUpdatedAt', $datetime),
                Query::equal('active', [false]),
            ],
            $dbForConsole,
            function (Document $document) use ($getProjectDB, $dbForConsole) {
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
    };
}, ['deleteCacheFiles', 'dbForConsole', 'getProjectDB', 'listByGroup']);

Server::setResource('deleteCacheByResource', function ($deleteCacheFiles) {
    return function (array $payload, Document $project) use ($deleteCacheFiles) {
        $deleteCacheFiles([
        Query::equal('resource', [$payload['resource']]),
        ], $project);
    };
}, ['deleteCacheFiles']);

Server::setResource('deleteCacheByDate', function ($deleteCacheFiles) {
    return function (array $payload, Document $project) use ($deleteCacheFiles) {
        $deleteCacheFiles([
        Query::lessThan('accessedAt', $payload['datetime']),
        ], $project);
    };
}, ['deleteCacheFiles']);

Server::setResource('deleteCacheFiles', function ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {
    return function (string $query, Document $project) use ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {

        $deleteForProjectIds(function () use ($deleteByGroup, $getProjectDB, $query, $project) {
            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
            );

            $deleteByGroup(
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
    };
}, ['getProjectDB', 'deleteForProjectIds', 'deleteByGroup']);


/**
     * @param Document $document database document
     * @param Document $projectId
     */
    Server::setResource('deleteDatabase', function ($getProjectDB, $deleteAuditLogsByResource, $deleteByGroup, $deleteCollection) {
        return function (Document $document, Document $project) use ($getProjectDB, $deleteAuditLogsByResource, $deleteByGroup, $deleteCollection) {

            $databaseId = $document->getId();
            $dbForProject = getProjectDB($project);

            $deleteByGroup('database_' . $document->getInternalId(), [], $dbForProject, function ($document) use ($deleteCollection, $project) {
                $deleteCollection($document, $project);
            });

            $dbForProject->deleteCollection('database_' . $document->getInternalId());

            $deleteAuditLogsByResource('database/' . $databaseId, $project);
        };
    }, ['getProjectDB', 'deleteAuditLogsByResource', 'deleteByGroup', 'deleteCollection']);

    /**
     * @param Document $document teams document
     * @param Document $project
     */
    Server::setResource('deleteCollection', function ($getProjectDB, $deleteAuditLogsByResource, $deleteByGroup) {
        return function (Document $document, Document $project) use ($getProjectDB, $deleteAuditLogsByResource, $deleteByGroup) {

            $collectionId = $document->getId();
            $databaseId = $document->getAttribute('databaseId');
            $databaseInternalId = $document->getAttribute('databaseInternalId');

            $dbForProject = getProjectDB($project);

            $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $document->getInternalId());

            $deleteByGroup('attributes', [
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId])
            ], $dbForProject);

            $deleteByGroup('indexes', [
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$collectionId])
            ], $dbForProject);

            $deleteAuditLogsByResource('database/' . $databaseId . '/collection/' . $collectionId, $project);
        };
    }, ['getProjectDB', 'deleteAuditLogsByResource', 'deleteByGroup']);

/**
 * @param string $hourlyUsageRetentionDatetime
 */
    Server::setResource('deleteUsageStats', function ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {
        return function (string $hourlyUsageRetentionDatetime) use ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {

            $deleteForProjectIds(function (Document $project) use ($getProjectDB, $deleteByGroup, $hourlyUsageRetentionDatetime) {
                $dbForProject = $getProjectDB($project);
                // Delete Usage stats
                $deleteByGroup('stats', [
                Query::lessThan('time', $hourlyUsageRetentionDatetime),
                Query::equal('period', ['1h']),
                ], $dbForProject);
            });
        };
    }, ['getProjectDB', 'deleteForProjectIds', 'deleteByGroup']);

/**
 * @param Document $document teams document
 * @param Document $project
 */
    Server::setResource('deleteMemberships', function ($getProjectDB, $deleteByGroup) {
        return function (Document $document, Document $project) use ($getProjectDB, $deleteByGroup) {

            $teamId = $document->getAttribute('teamId', '');

        // Delete Memberships
            $deleteByGroup('memberships', [
            Query::equal('teamId', [$teamId])
            ], getProjectDB($project));
        };
    }, ['getProjectDB', 'deleteByGroup']);


/**
 * @param Document $document project document
 */
    Server::setResource('deleteProject', function ($getProjectDB, $getFilesDevice) {
        return function (Document $document) use ($getProjectDB, $getFilesDevice) {

            $projectId = $document->getId();

            // Delete all DBs
            $getProjectDB($document)->delete($projectId);

            // Delete all storage directories
            $uploads = $getFilesDevice($document->getId());
            $cache = new Local(APP_STORAGE_CACHE . '/app-' . $document->getId());

            $uploads->delete($uploads->getRoot(), true);
            $cache->delete($cache->getRoot(), true);
        };
    }, ['getProjectDB', 'getFilesDevice']);

/**
 * @param Document $document user document
 * @param Document $project
 */
    Server::setResource('deleteUser', function ($getProjectDB, $deleteByGroup) {
        return function (Document $document, Document $project) use ($getProjectDB, $deleteByGroup) {

            $userId = $document->getId();
            $dbForProject = getProjectDB($project);

        // Delete all sessions of this user from the sessions table and update the sessions field of the user record
            $deleteByGroup('sessions', [
            Query::equal('userId', [$userId])
            ], $dbForProject);

            $dbForProject->deleteCachedDocument('users', $userId);

        // Delete Memberships and decrement team membership counts
            $deleteByGroup('memberships', [
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
            $deleteByGroup('tokens', [
            Query::equal('userId', [$userId])
            ], $dbForProject);
        };
    }, ['getProjectDB', 'deleteByGroup']);

/**
 * @param string $datetime
 */
    Server::setResource('deleteExecutionLogs', function ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {
        return function (string $datetime) use ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {

            $deleteForProjectIds(function (Document $project) use ($deleteByGroup, $datetime) {
                $dbForProject = getProjectDB($project);
                // Delete Executions
                $deleteByGroup('executions', [
                Query::lessThan('$createdAt', $datetime)
                ], $dbForProject);
            });
        };
    }, ['getProjectDB', 'deleteForProjectIds', 'deleteByGroup']);


    Server::setResource('deleteExpiredSessions', function ($getProjectDB, $deleteForProjectIds, $deleteByGroup, $dbForConsole) {
        return function () use ($getProjectDB, $deleteForProjectIds, $deleteByGroup, $dbForConsole) {

            $deleteForProjectIds(function (Document $project) use ($deleteByGroup, $getProjectDB, $dbForConsole) {
                $dbForProject = $getProjectDB($project);

                $project = $dbForConsole->getDocument('projects', $project->getId());
                $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
                $expired = DateTime::addSeconds(new \DateTime(), -1 * $duration);

                // Delete Sessions
                $deleteByGroup('sessions', [
                Query::lessThan('$createdAt', $expired)
                ], $dbForProject);
            });
        };
    }, ['getProjectDB', 'deleteForProjectIds', 'deleteByGroup', 'dbForConsole']);


/**
 * @param string $datetime
 */
    Server::setResource('deleteRealtimeUsage', function ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {
        return function (string $datetime) use ($getProjectDB, $deleteForProjectIds, $deleteByGroup) {
            $deleteForProjectIds(function (Document $project) use ($deleteByGroup, $getProjectDB, $datetime) {
                $dbForProject = $getProjectDB($project);
                // Delete Dead Realtime Logs
                $deleteByGroup('realtime', [
                Query::lessThan('timestamp', $datetime)
                ], $dbForProject);
            });
        };
    }, ['getProjectDB', 'deleteForProjectIds', 'deleteByGroup']);

/**
 * @param string $datetime
 * @throws Exception
 */
    Server::setResource('deleteAbuseLogs', function ($getProjectDB, $deleteForProjectIds) {
        return function (string $datetime) use ($getProjectDB, $deleteForProjectIds) {

            if (empty($datetime)) {
                throw new Exception('Failed to delete audit logs. No datetime provided');
            }

            $deleteForProjectIds(function (Document $project) use ($datetime) {
                $projectId = $project->getId();
                $dbForProject = getProjectDB($project);
                $timeLimit = new TimeLimit("", 0, 1, $dbForProject);
                $abuse = new Abuse($timeLimit);
                $status = $abuse->cleanup($datetime);
                if (!$status) {
                    throw new Exception('Failed to delete Abuse logs for project ' . $projectId);
                }
            });
        };
    }, ['getProjectDB', 'deleteForProjectIds']);

/**
 * @param string $datetime
 * @throws Exception
 */
    Server::setResource('deleteAuditLogs', function ($getProjectDB, $deleteForProjectIds) {
        return function (string $datetime) use ($getProjectDB, $deleteForProjectIds) {

            if (empty($datetime)) {
                throw new Exception('Failed to delete audit logs. No datetime provided');
            }

            $deleteForProjectIds(function (Document $project) use ($datetime) {
                $projectId = $project->getId();
                $dbForProject = getProjectDB($project);
                $audit = new Audit($dbForProject);
                $status = $audit->cleanup($datetime);
                if (!$status) {
                    throw new Exception('Failed to delete Audit logs for project' . $projectId);
                }
            });
        };
    }, ['getProjectDB', 'deleteForProjectIds']);

/**
 * @param string $resource
 * @param Document $project
 */
    Server::setResource('deleteAuditLogsByResource', function ($getProjectDB, $deleteByGroup) {
        return function (string $resource, Document $project) use ($getProjectDB, $deleteByGroup) {
            $dbForProject = getProjectDB($project);

            $deleteByGroup(Audit::COLLECTION, [
            Query::equal('resource', [$resource])
            ], $dbForProject);
        };
    }, ['getProjectDB', 'deleteByGroup']);

/**
 * @param Document $document function document
 * @param Document $project
 */
    Server::setResource('deleteFunction', function ($getProjectDB, $deleteByGroup, $getFunctionsDevice, $getBuildsDevice) {
        return function (Document $document, Document $project) use ($getProjectDB, $deleteByGroup, $getFunctionsDevice, $getBuildsDevice) {

            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
            $functionId = $document->getId();

            /**
             * Delete Variables
             */
            Console::info("Deleting variables for function " . $functionId);
            $deleteByGroup('variables', [
            Query::equal('functionId', [$functionId])
            ], $dbForProject);

            /**
             * Delete Deployments
             */
            Console::info("Deleting deployments for function " . $functionId);
            $storageFunctions = $getFunctionsDevice($projectId);
            $deploymentIds = [];
            $deleteByGroup('deployments', [
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
            $storageBuilds = $getBuildsDevice($projectId);
            foreach ($deploymentIds as $deploymentId) {
                $deleteByGroup('builds', [
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
            $deleteByGroup('executions', [
            Query::equal('functionId', [$functionId])
            ], $dbForProject);

            // TODO: Request executor to delete runtime
        };
    }, ['getProjectDB' ,'$deleteByGroup', '$getFunctionsDevice', '$getBuildsDevice']);

/**
 * @param Document $document deployment document
 * @param Document $project
 */
    Server::setResource('deleteDeployment', function ($getProjectDB, $getBuildsDevice, $getFunctionsDevice, $deleteByGroup) {
        return function (Document $document, Document $project) use ($getProjectDB, $getBuildsDevice, $getFunctionsDevice, $deleteByGroup) {

            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
            $deploymentId = $document->getId();
            $functionId = $document->getAttribute('resourceId');

            /**
             * Delete deployment files
             */
            Console::info("Deleting deployment files for deployment " . $deploymentId);
            $storageFunctions = $getFunctionsDevice($projectId);
            if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
                Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
            } else {
                Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
            }

            /**
             * Delete builds
             */
            Console::info("Deleting builds for deployment " . $deploymentId);
            $storageBuilds = $getBuildsDevice($projectId);
            $deleteByGroup('builds', [
            Query::equal('deploymentId', [$deploymentId])
            ], $dbForProject, function (Document $document) use ($storageBuilds) {
                if ($storageBuilds->delete($document->getAttribute('path', ''), true)) {
                    Console::success('Deleted build files: ' . $document->getAttribute('path', ''));
                } else {
                    Console::error('Failed to delete build files: ' . $document->getAttribute('path', ''));
                }
            });

            // TODO: Request executor to delete runtime
        };
    }, ['getProjectDB' ,'getBuildsDevice', 'getFunctionsDevice', 'deleteByGroup']);


/**
 * @param Document $document to be deleted
 * @param Database $database to delete it from
 * @param callable $callback to perform after document is deleted
 * @return bool
 */
    Server::setResource('deleteById', function () {
        return function (
            Document $document,
            Database $database,
            callable $callback = null
        ) {
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
        };
    });

/**
 * @param callable $callback
 */
    Server::setResource('deleteForProjectIds', function () {
        return function (
            callable $callback = null
        ) {

            // TODO: @Meldiron name of this method no longer matches. It does not delete, and it gives whole document
            $count = 0;
            $chunk = 0;
            $limit = 50;
            $projects = [];
            $sum = $limit;

            $executionStart = \microtime(true);

            while ($sum === $limit) {
                $projects = getConsoleDB()->find('projects', [Query::limit($limit), Query::offset($chunk * $limit)]);

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
        };
    });

/**
 * @param string $collection collectionID
 * @param Query[] $queries
 * @param Database $database
 * @param callable $callback
 */
    Server::setResource('deleteByGroup', function () {
        return function (
            string $collection,
            array $queries,
            Database $database,
            callable $callback = null
        ) {
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
                    deleteById($document, $database, $callback);
                    $count++;
                }
            }

            $executionEnd = \microtime(true);
            Console::info("Deleted {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
        };
    });

/**
 * @param string $collection collectionID
 * @param Query[] $queries
 * @param Database $database
 * @param callable|null $callback
 * @throws Exception
 */
    Server::setResource('listByGroup', function () {
        return function (
            string $collection,
            array $queries,
            Database $database,
            callable $callback = null
        ) {

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
        };
    });

/**
 * @param Document $document certificates document
 */
    Server::setResource('deleteCertificates', function (Database $dbForConsole) {
        return function (Document $document) use ($dbForConsole) {

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
        };
    }, ['dbForConsole']);

    Server::setResource('deleteBucket', function (callable $getProjectDB, callable $getFilesDevice) {
        return function (
            Document $document,
            Document $project,
        ) use (
            $getProjectDB,
            $getFilesDevice
        ) {
            $projectId = $project->getId();
            $dbForProject = $getProjectDB($project);
            $dbForProject->deleteCollection('bucket_' . $document->getInternalId());

            $device = $getFilesDevice($projectId);

            $device->deletePath($document->getId());
        };
    }, ['getProjectDB', 'getFilesDevice']);

    $server->job()
        ->inject('message')
        ->inject('deleteDatabase')
        ->inject('deleteCollection')
        ->inject('deleteProject')
        ->inject('deleteFunction')
        ->inject('deleteDeployment')
        ->inject('deleteUser')
        ->inject('deleteMemberships')
        ->inject('deleteBucket')
        ->inject('deleteExecutionLogs')
        ->inject('deleteAuditLogs')
        ->inject('deleteAuditLogsByResource')
        ->inject('deleteAbuseLogs')
        ->inject('deleteRealtimeUsage')
        ->inject('deleteExpiredSessions')
        ->inject('deleteCertificates')
        ->inject('deleteUsageStats')
        ->inject('deleteCacheByResource')
        ->inject('deleteCacheByDate')
        ->inject('deleteSchedules')
        ->action(function (
            Message $message,
            $deleteDatabase,
            $deleteCollection,
            $deleteProject,
            $deleteFunction,
            $deleteDeployment,
            $deleteUser,
            $deleteMemberships,
            $deleteBucket,
            $deleteExecutionLogs,
            $deleteAuditLogs,
            $deleteAuditLogsByResource,
            $deleteAbuseLogs,
            $deleteRealtimeUsage,
            $deleteExpiredSessions,
            $deleteCertificates,
            $deleteUsageStats,
            $deleteCacheByResource,
            $deleteCacheByDate,
            $deleteSchedules
        ) {
            $payload = $message->getPayload() ?? [];

            if (empty($payload)) {
                throw new Exception('Missing payload');
            }

            $project = new Document($payload['project'] ?? []);
            $type = $payload['type'] ?? '';
            switch (strval($type)) {
                case DELETE_TYPE_DOCUMENT:
                    $document = new Document($payload['document'] ?? []);

                    switch ($document->getCollection()) {
                        case DELETE_TYPE_DATABASES:
                            $deleteDatabase($document, $project);
                            break;
                        case DELETE_TYPE_COLLECTIONS:
                            $deleteCollection($document, $project);
                            break;
                        case DELETE_TYPE_PROJECTS:
                            $deleteProject($document);
                            break;
                        case DELETE_TYPE_FUNCTIONS:
                            $deleteFunction($document, $project);
                            break;
                        case DELETE_TYPE_DEPLOYMENTS:
                            $deleteDeployment($document, $project);
                            break;
                        case DELETE_TYPE_USERS:
                            $deleteUser($document, $project);
                            break;
                        case DELETE_TYPE_TEAMS:
                            $deleteMemberships($document, $project);
                            break;
                        case DELETE_TYPE_BUCKETS:
                            $deleteBucket($document, $project);
                            break;
                        default:
                            Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                            break;
                    }
                    break;

                case DELETE_TYPE_EXECUTIONS:
                    $deleteExecutionLogs($payload['datetime']);
                    break;

                case DELETE_TYPE_AUDIT:
                    $datetime = $payload['datetime'] ?? null;
                    if (!empty($datetime)) {
                        $deleteAuditLogs($datetime);
                    }

                    $document = new Document($payload['document'] ?? []);

                    if (!$document->isEmpty()) {
                        $deleteAuditLogsByResource('document/' . $document->getId(), $project);
                    }

                    break;

                case DELETE_TYPE_ABUSE:
                    $deleteAbuseLogs($payload['datetime']);
                    break;

                case DELETE_TYPE_REALTIME:
                    $deleteRealtimeUsage($payload['datetime']);
                    break;

                case DELETE_TYPE_SESSIONS:
                    $deleteExpiredSessions();
                    break;

                case DELETE_TYPE_CERTIFICATES:
                    $document = new Document($payload['document']);
                    $deleteCertificates($document);
                    break;

                case DELETE_TYPE_USAGE:
                    $deleteUsageStats($payload['hourlyUsageRetentionDatetime']);
                    break;

                case DELETE_TYPE_CACHE_BY_RESOURCE:
                    $deleteCacheByResource($payload, $project);
                    break;
                case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                    $deleteCacheByDate($payload, $project);
                    break;
                case DELETE_TYPE_SCHEDULES:
                    $deleteSchedules($payload['datetime']);
                    break;
                default:
                    Console::error('No delete operation for type: ' . $type);
                    break;
            };
        }, [
        'deleteDatabase',
        'deleteCollection',
        'deleteProject',
        'deleteFunction',
        'deleteDeployment',
        'deleteUser',
        'deleteMemberships',
        'deleteBucket',
        'deleteExecutionLogs',
        'deleteAuditLogs',
        'deleteAuditLogsByResource',
        'deleteAbuseLogs',
        'deleteRealtimeUsage',
        'deleteExpiredSessions',
        'deleteCertificates',
        'deleteUsageStats',
        'deleteCacheByResource',
        'deleteCacheByDate',
        'deleteSchedules'
        ]);

    $server->workerStart();
    $server->start();
