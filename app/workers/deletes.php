<?php

require_once __DIR__ . '/../worker.php';

use Appwrite\Auth\Auth;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Executor\Executor;
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

$server->job()
    ->inject('message')
    ->action(function (Message $message) {
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
                        deleteDatabase($document, $project);
                        break;
                    case DELETE_TYPE_COLLECTIONS:
                        deleteCollection($document, $project);
                        break;
                    case DELETE_TYPE_PROJECTS:
                        deleteProject($document);
                        break;
                    case DELETE_TYPE_FUNCTIONS:
                        deleteFunction($document, $project);
                        break;
                    case DELETE_TYPE_DEPLOYMENTS:
                        deleteDeployment($document, $project);
                        break;
                    case DELETE_TYPE_USERS:
                        deleteUser($document, $project);
                        break;
                    case DELETE_TYPE_TEAMS:
                        deleteMemberships($document, $project);
                        break;
                    case DELETE_TYPE_BUCKETS:
                        deleteBucket($document, $project);
                        break;
                    default:
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                        break;
                }
                break;

            case DELETE_TYPE_EXECUTIONS:
                deleteExecutionLogs($payload['datetime']);
                break;

            case DELETE_TYPE_AUDIT:
                $datetime = $payload['datetime'] ?? null;
                if (!empty($datetime)) {
                    deleteAuditLogs($datetime);
                }

                $document = new Document($payload['document'] ?? []);

                if (!$document->isEmpty()) {
                    deleteAuditLogsByResource('document/' . $document->getId(), $project);
                }

                break;

            case DELETE_TYPE_ABUSE:
                deleteAbuseLogs($payload['datetime']);
                break;

            case DELETE_TYPE_REALTIME:
                deleteRealtimeUsage($payload['datetime']);
                break;

            case DELETE_TYPE_SESSIONS:
                deleteExpiredSessions();
                break;

            case DELETE_TYPE_CERTIFICATES:
                $document = new Document($payload['document']);
                deleteCertificates($document);
                break;

            case DELETE_TYPE_USAGE:
                deleteUsageStats($payload['hourlyUsageRetentionDatetime']);
                break;

            case DELETE_TYPE_CACHE_BY_RESOURCE:
                deleteCacheByResource($payload['resource']);
                break;
            case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                deleteCacheByDate($payload);
                break;
            case DELETE_TYPE_SCHEDULES:
                deleteSchedules($payload['datetime']);
                break;
            default:
                Console::error('No delete operation for type: ' . $type);
                break;
        }
    });


/**
 * @throws Exception
 */
function deleteSchedules(string $datetime): void
{
    listByGroup(
        'schedules',
        [
            Query::equal('region', [App::getEnv('_APP_REGION', 'default')]),
            Query::equal('resourceType', ['function']),
            Query::lessThanEqual('resourceUpdatedAt', $datetime),
            Query::equal('active', [false]),
        ],
        getConsoleDB(),
        function (Document $document) {
            $project = getConsoleDB()->getDocument('projects', $document->getAttribute('projectId'));

            if ($project->isEmpty()) {
                Console::warning('Unable to delete schedule for function ' . $document->getAttribute('resourceId'));
                return;
            }

            $function = getProjectDB($project)->getDocument('functions', $document->getAttribute('resourceId'));

            if ($function->isEmpty()) {
                getConsoleDB()->deleteDocument('schedules', $document->getId());
                Console::success('Deleting schedule for function ' . $document->getAttribute('resourceId'));
            }
        }
    );
}

/**
 * @param string $resource
 */
function deleteCacheByResource(string $resource): void
{
    deleteCacheFiles([
        Query::equal('resource', [$resource]),
    ]);
}

function deleteCacheByDate($payload): void
{
    deleteCacheFiles([
        Query::lessThan('accessedAt', $payload['datetime']),
    ]);
}

function deleteCacheFiles($query): void
{
    deleteForProjectIds(function (Document $project) use ($query) {

        $projectId = $project->getId();
        $dbForProject = getProjectDB($project);
        $cache = new Cache(
            new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
        );

        deleteByGroup(
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
function deleteDatabase(Document $document, Document $project): void
{
    $databaseId = $document->getId();
    $projectId = $project->getId();

    $dbForProject = getProjectDB($project);

    deleteByGroup('database_' . $document->getInternalId(), [], $dbForProject, function ($document) use ($project) {
        deleteCollection($document, $project);
    });

    $dbForProject->deleteCollection('database_' . $document->getInternalId());

    deleteAuditLogsByResource('database/' . $databaseId, $project);
}

/**
 * @param Document $document teams document
 * @param Document $project
 */
function deleteCollection(Document $document, Document $project): void
{
    $collectionId = $document->getId();
    $databaseId = $document->getAttribute('databaseId');
    $databaseInternalId = $document->getAttribute('databaseInternalId');

    $dbForProject = getProjectDB($project);

    $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $document->getInternalId());

    deleteByGroup('attributes', [
        Query::equal('databaseId', [$databaseId]),
        Query::equal('collectionId', [$collectionId])
    ], $dbForProject);

    deleteByGroup('indexes', [
        Query::equal('databaseId', [$databaseId]),
        Query::equal('collectionId', [$collectionId])
    ], $dbForProject);

    deleteAuditLogsByResource('database/' . $databaseId . '/collection/' . $collectionId, $project);
}

/**
 * @param string $hourlyUsageRetentionDatetime
 */
function deleteUsageStats(string $hourlyUsageRetentionDatetime)
{
    deleteForProjectIds(function (Document $project) use ($hourlyUsageRetentionDatetime) {
        $dbForProject = getProjectDB($project);
        // Delete Usage stats
        deleteByGroup('stats', [
            Query::lessThan('time', $hourlyUsageRetentionDatetime),
            Query::equal('period', ['1h']),
        ], $dbForProject);
    });
}

/**
 * @param Document $document teams document
 * @param Document $project
 */
function deleteMemberships(Document $document, Document $project): void
{
    $teamId = $document->getAttribute('teamId', '');

    // Delete Memberships
    deleteByGroup('memberships', [
        Query::equal('teamId', [$teamId])
    ], getProjectDB($project));
}

/**
 * @param Document $document project document
 */
function deleteProject(Document $document): void
{
    $projectId = $document->getId();

    // Delete all DBs
    getProjectDB($document)->delete($projectId);

    // Delete all storage directories
    $uploads = getFilesDevice($document->getId());
    $cache = new Local(APP_STORAGE_CACHE . '/app-' . $document->getId());

    $uploads->delete($uploads->getRoot(), true);
    $cache->delete($cache->getRoot(), true);
}

/**
 * @param Document $document user document
 * @param Document $project
 */
function deleteUser(Document $document, Document $project): void
{
    $userId = $document->getId();

    $dbForProject = getProjectDB($project);

    // Delete all sessions of this user from the sessions table and update the sessions field of the user record
    deleteByGroup('sessions', [
        Query::equal('userId', [$userId])
    ], $dbForProject);

    $dbForProject->deleteCachedDocument('users', $userId);

    // Delete Memberships and decrement team membership counts
    deleteByGroup('memberships', [
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
    deleteByGroup('tokens', [
        Query::equal('userId', [$userId])
    ], $dbForProject);
}

/**
 * @param string $datetime
 */
function deleteExecutionLogs(string $datetime): void
{
    deleteForProjectIds(function (Document $project) use ($datetime) {
        $dbForProject = getProjectDB($project);
        // Delete Executions
        deleteByGroup('executions', [
            Query::lessThan('$createdAt', $datetime)
        ], $dbForProject);
    });
}

function deleteExpiredSessions(): void
{
    $consoleDB = getConsoleDB();

    deleteForProjectIds(function (Document $project) use ($consoleDB) {
        $dbForProject = getProjectDB($project);

        $project = $consoleDB->getDocument('projects', $project->getId());
        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $expired = DateTime::addSeconds(new \DateTime(), -1 * $duration);

        // Delete Sessions
        deleteByGroup('sessions', [
            Query::lessThan('$createdAt', $expired)
        ], $dbForProject);
    });
}

/**
 * @param string $datetime
 */
function deleteRealtimeUsage(string $datetime): void
{
    deleteForProjectIds(function (Document $project) use ($datetime) {
        $dbForProject = getProjectDB($project);
        // Delete Dead Realtime Logs
        deleteByGroup('realtime', [
            Query::lessThan('timestamp', $datetime)
        ], $dbForProject);
    });
}

/**
 * @param string $datetime
 * @throws Exception
 */
function deleteAbuseLogs(string $datetime): void
{
    if (empty($datetime)) {
        throw new Exception('Failed to delete audit logs. No datetime provided');
    }

    deleteForProjectIds(function (Document $project) use ($datetime) {
        $projectId = $project->getId();
        $dbForProject = getProjectDB($project);
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
function deleteAuditLogs(string $datetime): void
{
    if (empty($datetime)) {
        throw new Exception('Failed to delete audit logs. No datetime provided');
    }

    deleteForProjectIds(function (Document $project) use ($datetime) {
        $projectId = $project->getId();
        $dbForProject = getProjectDB($project);
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
function deleteAuditLogsByResource(string $resource, Document $project): void
{
    $dbForProject = getProjectDB($project);

    deleteByGroup(Audit::COLLECTION, [
        Query::equal('resource', [$resource])
    ], $dbForProject);
}

/**
 * @param Document $document function document
 * @param Document $project
 */
function deleteFunction(Document $document, Document $project): void
{
    $projectId = $project->getId();
    $dbForProject = getProjectDB($project);
    $functionId = $document->getId();

    /**
     * Delete Variables
     */
    Console::info("Deleting variables for function " . $functionId);
    deleteByGroup('variables', [
        Query::equal('functionId', [$functionId])
    ], $dbForProject);

    /**
     * Delete Deployments
     */
    Console::info("Deleting deployments for function " . $functionId);
    $storageFunctions = getFunctionsDevice($projectId);
    $deploymentIds = [];
    deleteByGroup('deployments', [
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
    $storageBuilds = getBuildsDevice($projectId);
    foreach ($deploymentIds as $deploymentId) {
        deleteByGroup('builds', [
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
    deleteByGroup('executions', [
        Query::equal('functionId', [$functionId])
    ], $dbForProject);

    // TODO: Request executor to delete runtime
}

/**
 * @param Document $document deployment document
 * @param Document $project
 */
function deleteDeployment(Document $document, Document $project): void
{
    $projectId = $project->getId();
    $dbForProject = getProjectDB($project);
    $deploymentId = $document->getId();
    $functionId = $document->getAttribute('resourceId');

    /**
     * Delete deployment files
     */
    Console::info("Deleting deployment files for deployment " . $deploymentId);
    $storageFunctions = getFunctionsDevice($projectId);
    if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
        Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
    } else {
        Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
    }

    /**
     * Delete builds
     */
    Console::info("Deleting builds for deployment " . $deploymentId);
    $storageBuilds = getBuildsDevice($projectId);
    deleteByGroup('builds', [
        Query::equal('deploymentId', [$deploymentId])
    ], $dbForProject, function (Document $document) use ($storageBuilds) {
        if ($storageBuilds->delete($document->getAttribute('path', ''), true)) {
            Console::success('Deleted build files: ' . $document->getAttribute('path', ''));
        } else {
            Console::error('Failed to delete build files: ' . $document->getAttribute('path', ''));
        }
    });

    // TODO: Request executor to delete runtime
}


/**
 * @param Document $document to be deleted
 * @param Database $database to delete it from
 * @param callable $callback to perform after document is deleted
 *
 * @return bool
 */
function deleteById(Document $document, Database $database, callable $callback = null): bool
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
function deleteForProjectIds(callable $callback): void
{
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
}

/**
 * @param string $collection collectionID
 * @param Query[] $queries
 * @param Database $database
 * @param callable $callback
 */
function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
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
            deleteById($document, $database, $callback);
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
function listByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
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
 * @param Document $document certificates document
 */
function deleteCertificates(Document $document): void
{
    $consoleDB = getConsoleDB();

    // If domain has certificate generated
    if (isset($document['certificateId'])) {
        $domainUsingCertificate = $consoleDB->findOne('domains', [
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
            $consoleDB->deleteDocument('certificates', $document['certificateId']);
        }

        // Delete files, so Traefik is aware of change
        array_map('unlink', glob($directory . '/*.*'));
        rmdir($directory);
        Console::info("Deleted certificate files for {$domain}");
    } else {
        Console::info("No certificate files found for {$domain}");
    }
}

function deleteBucket(Document $document, Document $project)
{
    $projectId = $project->getId();
    $dbForProject = getProjectDB($project);
    $dbForProject->deleteCollection('bucket_' . $document->getInternalId());

    $device = getFilesDevice($projectId);

    $device->deletePath($document->getId());
}

$server->workerStart();
$server->start();
