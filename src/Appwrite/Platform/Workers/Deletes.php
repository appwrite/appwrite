<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Auth\Auth;
use Appwrite\Certificates\Adapter as CertificatesAdapter;
use Appwrite\Deletes\Identities;
use Appwrite\Deletes\Targets;
use Appwrite\Extend\Exception;
use Executor\Executor;
use Throwable;
use Utopia\Abuse\Adapters\TimeLimit\Database as AbuseDatabase;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;

class Deletes extends Action
{
    protected array $selects = ['$internalId', '$id', '$collection', '$permissions', '$updatedAt'];

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
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('getLogsDB')
            ->inject('deviceForFiles')
            ->inject('deviceForFunctions')
            ->inject('deviceForBuilds')
            ->inject('deviceForCache')
            ->inject('certificates')
            ->inject('executor')
            ->inject('executionRetention')
            ->inject('auditRetention')
            ->inject('log')
            ->callback(
                fn ($message, Document $project, Database $dbForPlatform, callable $getProjectDB, callable $getLogsDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, CertificatesAdapter $certificates, Executor $executor, string $executionRetention, string $auditRetention, Log $log) =>
                    $this->action($message, $project, $dbForPlatform, $getProjectDB, $getLogsDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $certificates, $executor, $executionRetention, $auditRetention, $log)
            );
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function action(Message $message, Document $project, Database $dbForPlatform, callable $getProjectDB, callable $getLogsDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, CertificatesAdapter $certificates, Executor $executor, string $executionRetention, string $auditRetention, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type     = $payload['type'] ?? '';
        $datetime = $payload['datetime'] ?? null;
        $hourlyUsageRetentionDatetime = $payload['hourlyUsageRetentionDatetime'] ?? null;
        $resource = $payload['resource'] ?? null;
        $resourceType = $payload['resourceType'] ?? null;
        $document = new Document($payload['document'] ?? []);

        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        switch (\strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                switch ($document->getCollection()) {
                    case DELETE_TYPE_PROJECTS:
                        $this->deleteProject($dbForPlatform, $getProjectDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $certificates, $document);
                        break;
                    case DELETE_TYPE_FUNCTIONS:
                        $this->deleteFunction($dbForPlatform, $getProjectDB, $deviceForFunctions, $deviceForBuilds, $certificates, $document, $project, $executor);
                        break;
                    case DELETE_TYPE_DEPLOYMENTS:
                        $this->deleteDeployment($getProjectDB, $deviceForFunctions, $deviceForBuilds, $document, $project, $executor);
                        break;
                    case DELETE_TYPE_USERS:
                        $this->deleteUser($getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_BUCKETS:
                        $this->deleteBucket($getProjectDB, $deviceForFiles, $document, $project);
                        break;
                    case DELETE_TYPE_INSTALLATIONS:
                        $this->deleteInstallation($dbForPlatform, $getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_RULES:
                        $this->deleteRule($dbForPlatform, $document, $certificates);
                        break;
                    default:
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                        break;
                }
                break;
            case DELETE_TYPE_TEAM_PROJECTS:
                $this->deleteProjectsByTeam($dbForPlatform, $getProjectDB, $certificates, $document);
                break;
            case DELETE_TYPE_EXECUTIONS:
                $this->deleteExecutionLogs($project, $getProjectDB, $executionRetention);
                break;
            case DELETE_TYPE_AUDIT:
                if (!$project->isEmpty()) {
                    $this->deleteAuditLogs($project, $getProjectDB, $auditRetention);
                }
                break;
            case DELETE_TYPE_REALTIME:
                $this->deleteRealtimeUsage($dbForPlatform, $datetime);
                break;
            case DELETE_TYPE_SESSIONS:
                $this->deleteExpiredSessions($project, $getProjectDB);
                break;
            case DELETE_TYPE_USAGE:
                $this->deleteUsageStats($project, $getProjectDB, $getLogsDB, $hourlyUsageRetentionDatetime);
                break;
            case DELETE_TYPE_CACHE_BY_RESOURCE:
                $this->deleteCacheByResource($project, $getProjectDB, $resource, $resourceType);
                break;
            case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                $this->deleteCacheByDate($project, $getProjectDB, $datetime);
                break;
            case DELETE_TYPE_SCHEDULES:
                $this->deleteSchedules($dbForPlatform, $getProjectDB, $datetime);
                break;
            case DELETE_TYPE_TOPIC:
                $this->deleteTopic($project, $getProjectDB, $document);
                break;
            case DELETE_TYPE_TARGET:
                Targets::deleteSubscribers($getProjectDB($project), $document);
                break;
            case DELETE_TYPE_EXPIRED_TARGETS:
                $this->deleteExpiredTargets($project, $getProjectDB);
                break;
            case DELETE_TYPE_SESSION_TARGETS:
                $this->deleteSessionTargets($project, $getProjectDB, $document);
                break;
            case DELETE_TYPE_MAINTENANCE:
                $this->deleteExpiredTargets($project, $getProjectDB);
                $this->deleteExecutionLogs($project, $getProjectDB, $executionRetention);
                $this->deleteAuditLogs($project, $getProjectDB, $auditRetention);
                $this->deleteUsageStats($project, $getProjectDB, $getLogsDB, $hourlyUsageRetentionDatetime);
                $this->deleteExpiredSessions($project, $getProjectDB);
                break;
            default:
                throw new \Exception('No delete operation for type: ' . \strval($type));
        }
    }

    /**
     * @param Database $dbForPlatform
     * @param callable $getProjectDB
     * @param string $datetime
     * @param Document|null $document
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     * @throws DatabaseException
     */
    private function deleteSchedules(Database $dbForPlatform, callable $getProjectDB, string $datetime): void
    {
        // Temporarly accepting both 'fra' and 'default'
        // When all migrated, only use _APP_REGION with 'default' as default value
        $regions = [System::getEnv('_APP_REGION', 'default')];
        if (!in_array('default', $regions)) {
            $regions[] = 'default';
        }

        $this->listByGroup(
            'schedules',
            [
                Query::equal('region', $regions),
                Query::lessThanEqual('resourceUpdatedAt', $datetime),
                Query::equal('active', [false]),
            ],
            $dbForPlatform,
            function (Document $document) use ($dbForPlatform, $getProjectDB) {
                $project = $dbForPlatform->getDocument('projects', $document->getAttribute('projectId'));

                if ($project->isEmpty()) {
                    $dbForPlatform->deleteDocument('schedules', $document->getId());
                    Console::success('Deleted schedule for deleted project ' . $document->getAttribute('projectId'));
                    return;
                }

                $collectionId = match ($document->getAttribute('resourceType')) {
                    'function' => 'functions',
                    'execution' => 'executions',
                    'message' => 'messages'
                };

                try {
                    $resource = $getProjectDB($project)->getDocument(
                        $collectionId,
                        $document->getAttribute('resourceId')
                    );
                } catch (Throwable $e) {
                    Console::error('Failed to get resource for schedule ' . $document->getId() . ' ' . $e->getMessage());
                    return;
                }

                $delete = true;

                switch ($document->getAttribute('resourceType')) {
                    case 'function':
                        $delete = $resource->isEmpty();
                        break;
                    case 'execution':
                        $delete = false;
                        break;
                }

                if ($delete) {
                    $dbForPlatform->deleteDocument('schedules', $document->getId());
                    Console::success('Deleting schedule for ' . $document->getAttribute('resourceType') . ' ' . $document->getAttribute('resourceId'));
                }
            }
        );
    }

    /**
     * @param Document $project
     * @param callable $getProjectDB
     * @param Document $topic
     * @throws Exception
     */
    private function deleteTopic(Document $project, callable $getProjectDB, Document $topic)
    {
        if ($topic->isEmpty()) {
            Console::error('Failed to delete subscribers. Topic not found');
            return;
        }

        $this->deleteByGroup(
            'subscribers',
            [
                Query::equal('topicInternalId', [$topic->getInternalId()]),
                Query::orderAsc(),
            ],
            $getProjectDB($project)
        );
    }

    /**
     * @param Document $project
     * @param callable $getProjectDB
     * @param Document $target
     * @return void
     * @throws Exception
     */
    private function deleteExpiredTargets(Document $project, callable $getProjectDB): void
    {
        Targets::delete($getProjectDB($project), Query::equal('expired', [true]));
    }

    private function deleteSessionTargets(Document $project, callable $getProjectDB, Document $session): void
    {
        Targets::delete($getProjectDB($project), Query::equal('sessionInternalId', [$session->getInternalId()]));
    }

    /**
     * @param Document $project
     * @param callable $getProjectDB
     * @param string $resource
     * @return void
     * @throws Authorization
     * @param string|null $resourceType
     * @throws Exception
     */
    private function deleteCacheByResource(Document $project, callable $getProjectDB, string $resource, string $resourceType = null): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);

        $cache = new Cache(
            new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
        );

        $queries = [
            Query::equal('resource', [$resource])
        ];

        if (!empty($resourceType)) {
            $queries[] = Query::equal('resourceType', [$resourceType]);
        }

        $queries[] = Query::select($this->selects);
        $queries[] = Query::orderAsc();

        $this->deleteByGroup(
            'cache',
            $queries,
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
    }

    /**
     * Document $project
     * @param Document $project
     * @param callable $getProjectDB
     * @param string $datetime
     * @return void
     * @throws Exception
     */
    private function deleteCacheByDate(Document $project, callable $getProjectDB, string $datetime): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);

        $cache = new Cache(
            new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
        );

        $queries = [
            Query::select([...$this->selects, 'accessedAt']),
            Query::lessThan('accessedAt', $datetime),
            Query::orderDesc('accessedAt'),
            Query::orderDesc(),
        ];

        $this->deleteByGroup(
            'cache',
            $queries,
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
    }

    /**
     * @param Database $dbForPlatform
     * @param callable $getProjectDB
     * @param string $hourlyUsageRetentionDatetime
     * @return void
     * @throws Exception
     */
    private function deleteUsageStats(Document $project, callable $getProjectDB, callable $getLogsDB, string $hourlyUsageRetentionDatetime): void
    {
        /** @var Database $dbForProject*/
        $dbForProject = $getProjectDB($project);

        $selects = [...$this->selects, 'time'];

        // Delete Usage stats from projectDB
        $this->deleteByGroup('stats', [
            Query::select($selects),
            Query::equal('period', ['1h']),
            Query::lessThan('time', $hourlyUsageRetentionDatetime),
            Query::orderDesc('time'),
            Query::orderDesc(),
        ], $dbForProject);

        if ($project->getId() !== 'console') {
            /** @var Database $dbForLogs*/
            $dbForLogs = call_user_func($getLogsDB, $project);

            // Delete Usage stats from logsDB
            $this->deleteByGroup('stats', [
                Query::select($selects),
                Query::equal('period', ['1h']),
                Query::lessThan('time', $hourlyUsageRetentionDatetime),
                Query::orderDesc('time'),
                Query::orderDesc(),
            ], $dbForLogs);
        }
    }

    /**
     * @param callable $getProjectDB
     * @param Document $document teams document
     * @param Document $project
     * @return void
     * @throws Exception
     */
    public function deleteMemberships(callable $getProjectDB, Document $document, Document $project): void
    {
        $dbForProject = $getProjectDB($project);
        $teamInternalId = $document->getInternalId();

        // Delete Memberships
        $this->deleteByGroup(
            'memberships',
            [
                Query::equal('teamInternalId', [$teamInternalId]),
                Query::orderAsc()
            ],
            $dbForProject,
            function (Document $membership) use ($dbForProject) {
                $userId = $membership->getAttribute('userId');
                $dbForProject->purgeCachedDocument('users', $userId);
            }
        );
    }

    /**
    * @param Database $dbForPlatform
    * @param Document $document
    * @return void
    * @throws Authorization
    * @throws DatabaseException
    * @throws Conflict
    * @throws Restricted
    * @throws Structure
    * @throws Exception
    */
    protected function deleteProjectsByTeam(Database $dbForPlatform, callable $getProjectDB, CertificatesAdapter $certificates, Document $document): void
    {

        $projects = $dbForPlatform->find('projects', [
            Query::equal('teamInternalId', [$document->getInternalId()]),
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')])
        ]);

        foreach ($projects as $project) {
            $deviceForFiles = getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
            $deviceForFunctions = getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
            $deviceForBuilds = getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
            $deviceForCache = getDevice(APP_STORAGE_CACHE . '/app-' . $project->getId());

            $this->deleteProject($dbForPlatform, $getProjectDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $certificates, $project);
            $dbForPlatform->deleteDocument('projects', $project->getId());
        }
    }

    /**
     * @param Database $dbForPlatform
     * @param callable $getProjectDB
     * @param Device $deviceForFiles
     * @param Device $deviceForFunctions
     * @param Device $deviceForBuilds
     * @param Device $deviceForCache
     * @param Document $document
     * @return void
     * @throws Exception
     * @throws Authorization
     * @throws DatabaseException
     */
    private function deleteProject(Database $dbForPlatform, callable $getProjectDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, CertificatesAdapter $certificates, Document $document): void
    {
        $projectInternalId = $document->getInternalId();
        $projectId = $document->getId();

        try {
            $dsn = new DSN($document->getAttribute('database', 'console'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $document->getAttribute('database', 'console'));
        }

        $dbForProject = $getProjectDB($document);

        $projectCollectionIds = [
            ...\array_keys(Config::getParam('collections', [])['projects']),
            Audit::COLLECTION,
            AbuseDatabase::COLLECTION,
        ];

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
        $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES_V1', ''));

        $projectTables = !\in_array($dsn->getHost(), $sharedTables);
        $sharedTablesV1 = \in_array($dsn->getHost(), $sharedTablesV1);
        $sharedTablesV2 = !$projectTables && !$sharedTablesV1;

        /**
         * @var $dbForProject Database
         */
        var_dump($projectTables);
        var_dump($projectCollectionIds);
        $dbForProject->foreach(Database::METADATA, function (Document $collection) use ($dbForProject, $projectTables, $projectCollectionIds) {
            try {
                if ($projectTables || !\in_array($collection->getId(), $projectCollectionIds)) {
                    $dbForProject->deleteCollection($collection->getId());
                } else {
                    $this->deleteByGroup(
                        $collection->getId(),
                        [
                            Query::orderAsc()
                        ],
                        database: $dbForProject
                    );
                }
            } catch (Throwable $e) {
                Console::error('Error deleting '.$collection->getId().' '.$e->getMessage());
            }
        });

        // $collections = $dbForProject->listCollections(PHP_INT_MAX);
//        foreach ($collections as $collection) {
//            try {
//                if ($projectTables || !\in_array($collection->getId(), $projectCollectionIds)) {
//                    $dbForProject->deleteCollection($collection->getId());
//                } else {
//                    $this->deleteByGroup(
//                        $collection->getId(),
//                        [
//                            Query::orderAsc()
//                        ],
//                        database: $dbForProject
//                    );
//                }
//            } catch (Throwable $e) {
//                Console::error('Error deleting '.$collection->getId().' '.$e->getMessage());
//            }
//        }

        // Delete Platforms
        $this->deleteByGroup('platforms', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete project and function rules
        $this->deleteByGroup('rules', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform, function (Document $document) use ($dbForPlatform, $certificates) {
            $this->deleteRule($dbForPlatform, $document, $certificates);
        });

        // Delete Keys
        $this->deleteByGroup('keys', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete Webhooks
        $this->deleteByGroup('webhooks', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete VCS Installations
        $this->deleteByGroup('installations', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete VCS Repositories
        $this->deleteByGroup('repositories', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete VCS comments
        $this->deleteByGroup('vcsComments', [
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete Schedules
        $this->deleteByGroup('schedules', [
            Query::equal('projectId', [$projectId]),
            Query::orderAsc()
        ], $dbForPlatform);

        // Delete metadata table
        if ($projectTables) {
            $dbForProject->deleteCollection(Database::METADATA);
        } elseif ($sharedTablesV1) {
            $this->deleteByGroup(
                Database::METADATA,
                [
                    Query::orderAsc()
                ],
                $dbForProject
            );
        } elseif ($sharedTablesV2) {
            $queries = \array_map(
                fn ($id) => Query::notEqual('$id', $id),
                $projectCollectionIds
            );

            $queries[] = Query::orderAsc();

            $this->deleteByGroup(
                Database::METADATA,
                $queries,
                $dbForProject
            );
        }

        // Delete all storage directories
        $deviceForFiles->delete($deviceForFiles->getRoot(), true);
        $deviceForFunctions->delete($deviceForFunctions->getRoot(), true);
        $deviceForBuilds->delete($deviceForBuilds->getRoot(), true);
        $deviceForCache->delete($deviceForCache->getRoot(), true);
    }

    /**
     * @param callable $getProjectDB
     * @param Document $document user document
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteUser(callable $getProjectDB, Document $document, Document $project): void
    {
        $userId = $document->getId();
        $userInternalId = $document->getInternalId();
        $dbForProject = $getProjectDB($project);

        // Delete all sessions of this user from the sessions table and update the sessions field of the user record
        $this->deleteByGroup('sessions', [
            Query::equal('userInternalId', [$userInternalId]),
            Query::orderAsc()
        ], $dbForProject);

        $dbForProject->purgeCachedDocument('users', $userId);

        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup('memberships', [
            Query::equal('userInternalId', [$userInternalId]),
            Query::orderAsc()
        ], $dbForProject, function (Document $document) use ($dbForProject) {
            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $dbForProject->getDocument('teams', $teamId);
                if (!$team->isEmpty()) {
                    $dbForProject->decreaseDocumentAttribute('teams', $teamId, 'total', 1, 0);
                }
            }
        });

        // Delete tokens
        $this->deleteByGroup('tokens', [
            Query::equal('userInternalId', [$userInternalId]),
            Query::orderAsc()
        ], $dbForProject);

        // Delete identities
        Identities::delete($dbForProject, Query::equal('userInternalId', [$userInternalId]));

        // Delete targets
        Targets::delete($dbForProject, Query::equal('userInternalId', [$userInternalId]));
    }

    /**
     * @param database $dbForPlatform
     * @param callable $getProjectDB
     * @param string $datetime
     * @return void
     * @throws Exception
     */
    private function deleteExecutionLogs(Document $project, callable $getProjectDB, string $datetime): void
    {
        $dbForProject = $getProjectDB($project);

        // Delete Executions
        $this->deleteByGroup('executions', [
            Query::select([...$this->selects, '$createdAt']),
            Query::lessThan('$createdAt', $datetime),
            Query::orderDesc('$createdAt'),
            Query::orderDesc(),
        ], $dbForProject);
    }

    /**
     * @param Database $dbForPlatform
     * @param callable $getProjectDB
     * @return void
     * @throws Exception|Throwable
     */
    private function deleteExpiredSessions(Document $project, callable $getProjectDB): void
    {
        $dbForProject = $getProjectDB($project);
        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $expired = DateTime::addSeconds(new \DateTime(), -1 * $duration);

        // Delete Sessions
        $this->deleteByGroup('sessions', [
            Query::select([...$this->selects, '$createdAt']),
            Query::lessThan('$createdAt', $expired),
            Query::orderDesc('$createdAt'),
            Query::orderDesc(),
        ], $dbForProject);
    }

    /**
     * @param Database $dbForPlatform
     * @param string $datetime
     * @return void
     * @throws Exception
     */
    private function deleteRealtimeUsage(Database $dbForPlatform, string $datetime): void
    {
        // Delete Dead Realtime Logs
        $this->deleteByGroup('realtime', [
            Query::lessThan('timestamp', $datetime),
            Query::orderDesc('timestamp'),
            Query::orderAsc(),
        ], $dbForPlatform);
    }

    /**
     * @param Database $dbForPlatform
     * @param callable $getProjectDB
     * @param string $auditRetention
     * @return void
     * @throws Exception
     */
    private function deleteAuditLogs(Document $project, callable $getProjectDB, string $auditRetention): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);

        try {
            $this->deleteByGroup(Audit::COLLECTION, [
                Query::select([...$this->selects, 'time']),
                Query::lessThan('time', $auditRetention),
                Query::orderDesc('time'),
                Query::orderAsc(),
            ], $dbForProject);
        } catch (DatabaseException $e) {
            Console::error('Failed to delete audit logs for project ' . $projectId . ': ' . $e->getMessage());
        }
    }

    /**
     * @param callable $getProjectDB
     * @param Device $deviceForFunctions
     * @param Device $deviceForBuilds
     * @param Document $document function document
     * @param Document $project
     * @param Executor $executor
     * @return void
     * @throws Exception
     */
    private function deleteFunction(Database $dbForPlatform, callable $getProjectDB, Device $deviceForFunctions, Device $deviceForBuilds, CertificatesAdapter $certificates, Document $document, Document $project, Executor $executor): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);
        $functionId = $document->getId();
        $functionInternalId = $document->getInternalId();

        /**
         * Delete rules
         */
        Console::info("Deleting rules for function " . $functionId);
        $this->deleteByGroup('rules', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::equal('resourceType', ['function']),
            Query::orderAsc()
        ], $dbForPlatform, function (Document $document) use ($project, $dbForPlatform, $certificates) {
            $this->deleteRule($dbForPlatform, $document, $certificates);
        });

        /**
         * Delete Variables
         */
        Console::info("Deleting variables for function " . $functionId);
        $this->deleteByGroup('variables', [
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::equal('resourceType', ['function']),
            Query::orderAsc()
        ], $dbForProject);

        /**
         * Delete Deployments
         */
        Console::info("Deleting deployments for function " . $functionId);

        $deploymentInternalIds = [];
        $this->deleteByGroup('deployments', [
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::orderAsc()
        ], $dbForProject, function (Document $document) use ($deviceForFunctions, &$deploymentInternalIds) {
            $deploymentInternalIds[] = $document->getInternalId();
            $this->deleteDeploymentFiles($deviceForFunctions, $document);
        });

        /**
         * Delete builds
         */
        Console::info("Deleting builds for function " . $functionId);

        foreach ($deploymentInternalIds as $deploymentInternalId) {
            $this->deleteByGroup('builds', [
                Query::equal('deploymentInternalId', [$deploymentInternalId]),
                Query::orderAsc()
            ], $dbForProject, function (Document $document) use ($deviceForBuilds) {
                $this->deleteBuildFiles($deviceForBuilds, $document);
            });
        }

        /**
         * Delete Executions
         */
        Console::info("Deleting executions for function " . $functionId);
        $this->deleteByGroup('executions', [
            Query::select($this->selects),
            Query::equal('functionInternalId', [$functionInternalId]),
            Query::orderAsc()
        ], $dbForProject);

        /**
         * Delete VCS Repositories and VCS Comments
         */
        Console::info("Deleting VCS repositories and comments linked to function " . $functionId);
        $this->deleteByGroup('repositories', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::equal('resourceType', ['function']),
            Query::orderAsc()
        ], $dbForPlatform, function (Document $document) use ($dbForPlatform) {
            $providerRepositoryId = $document->getAttribute('providerRepositoryId', '');
            $projectInternalId = $document->getAttribute('projectInternalId', '');

            $this->deleteByGroup('vcsComments', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::equal('projectInternalId', [$projectInternalId]),
                Query::orderAsc()
            ], $dbForPlatform);
        });

        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete all deployment containers for function " . $functionId);
        $this->deleteRuntimes($getProjectDB, $document, $project, $executor);
    }

    /**
     * @param Device $device
     * @param Document $deployment
     * @return void
     */
    private function deleteDeploymentFiles(Device $device, Document $deployment): void
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
        } catch (Throwable $th) {
            Console::error('Failed to delete deployment files: ' . $deploymentPath);
            Console::error('[Error] Type: ' . get_class($th));
            Console::error('[Error] Message: ' . $th->getMessage());
            Console::error('[Error] File: ' . $th->getFile());
            Console::error('[Error] Line: ' . $th->getLine());
        }
    }

    /**
     * @param Device $device
     * @param Document $build
     * @return void
     */
    private function deleteBuildFiles(Device $device, Document $build): void
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
        } catch (Throwable $th) {
            Console::error('Failed to delete deployment files: ' . $buildPath);
            Console::error('[Error] Type: ' . get_class($th));
            Console::error('[Error] Message: ' . $th->getMessage());
            Console::error('[Error] File: ' . $th->getFile());
            Console::error('[Error] Line: ' . $th->getLine());
        }
    }

    /**
     * @param callable $getProjectDB
     * @param Device $deviceForFunctions
     * @param Device $deviceForBuilds
     * @param Document $document
     * @param Document $project
     * @param Executor $executor
     * @return void
     * @throws Exception
     */
    private function deleteDeployment(callable $getProjectDB, Device $deviceForFunctions, Device $deviceForBuilds, Document $document, Document $project, Executor $executor): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);
        $deploymentId = $document->getId();
        $deploymentInternalId = $document->getInternalId();

        /**
         * Delete deployment files
         */
        $this->deleteDeploymentFiles($deviceForFunctions, $document);

        /**
         * Delete builds
         */
        Console::info("Deleting builds for deployment " . $deploymentId);

        $this->deleteByGroup('builds', [
            Query::equal('deploymentInternalId', [$deploymentInternalId]),
            Query::orderAsc()
        ], $dbForProject, function (Document $document) use ($deviceForBuilds) {
            $this->deleteBuildFiles($deviceForBuilds, $document);
        });

        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete deployment container for deployment " . $deploymentId);
        $this->deleteRuntimes($getProjectDB, $document, $project, $executor);
    }

    /**
     * @param string $collection collectionID
     * @param array $queries
     * @param Database $database
     * @param ?callable $callback
     * @return void
     * @throws DatabaseException
     */
    protected function deleteByGroup(
        string $collection,
        array $queries,
        Database $database,
        ?callable $callback = null
    ): void {
        $start = \microtime(true);

        /**
         * deleteDocuments uses a cursor, we need to add a unique order by field or use default
         */
        try {
            $count = $database->deleteDocuments(
                $collection,
                $queries,
                Database::DELETE_BATCH_SIZE,
                $callback
            );
        } catch (Throwable $th) {
            $tenant = $database->getSharedTables() ? 'Tenant:'.$database->getTenant() : '';
            Console::error("Failed to delete documents for collection:{$database->getNamespace()}_{$collection} {$tenant} :{$th->getMessage()}");
            return;
        }

        $end = \microtime(true);
        Console::info("Deleted {$count} documents by group in " . ($end - $start) . " seconds");
    }

    /**
     * @param string $collection collectionID
     * @param Query[] $queries
     * @param Database $database
     * @param callable|null $callback
     * @return void
     * @throws Exception
     */
    protected function listByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
    {
        $count = 0;
        $limit = 1000;
        $sum = $limit;
        $cursor = null;

        $start = \microtime(true);

        while ($sum === $limit) {

            $queries = \array_merge([Query::limit($limit)], $queries);

            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $results = $database->find($collection, $queries);

            $sum = \count($results);

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

        $end = \microtime(true);

        Console::info("Listed {$count} documents by group in " . ($end - $start) . " seconds");
    }

    /**
     * @param Database $dbForPlatform
     * @param Document $document rule document
     * @return void
     */
    private function deleteRule(Database $dbForPlatform, Document $document, CertificatesAdapter $certificates): void
    {
        $domain = $document->getAttribute('domain');
        $certificates->deleteCertificate($domain);

        // Delete certificate document, so Appwrite is aware of change
        if (isset($document['certificateId'])) {
            $dbForPlatform->deleteDocument('certificates', $document['certificateId']);
        }
    }

    /**
     * @param callable $getProjectDB
     * @param Device $deviceForFiles
     * @param Document $document
     * @param Document $project
     * @return void
     */
    private function deleteBucket(callable $getProjectDB, Device $deviceForFiles, Document $document, Document $project): void
    {
        $dbForProject = $getProjectDB($project);

        $dbForProject->deleteCollection('bucket_' . $document->getInternalId());

        $deviceForFiles->deletePath($document->getId());
    }

    /**
     * @param Database $dbForPlatform
     * @param callable $getProjectDB
     * @param Document $document
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteInstallation(Database $dbForPlatform, callable $getProjectDB, Document $document, Document $project): void
    {
        $dbForProject = $getProjectDB($project);

        $this->listByGroup('functions', [
            Query::equal('installationInternalId', [$document->getInternalId()])
        ], $dbForProject, function ($function) use ($dbForProject, $dbForPlatform) {
            $dbForPlatform->deleteDocument('repositories', $function->getAttribute('repositoryId'));

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

    /**
     * @param callable $getProjectDB
     * @param ?Document $function
     * @param Document $project
     * @param Executor $executor
     * @return void
     * @throws Exception
     */
    private function deleteRuntimes(callable $getProjectDB, ?Document $function, Document $project, Executor $executor): void
    {
        $deleteByFunction = function (Document $function) use ($getProjectDB, $project, $executor) {
            $this->listByGroup(
                'deployments',
                [
                    Query::equal('resourceInternalId', [$function->getInternalId()]),
                    Query::equal('resourceType', ['functions']),
                ],
                $getProjectDB($project),
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
                $getProjectDB($project),
                function (Document $function) use ($deleteByFunction) {
                    $deleteByFunction($function);
                }
            );
        }
    }
}
