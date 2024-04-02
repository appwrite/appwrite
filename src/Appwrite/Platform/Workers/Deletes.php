<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Executor\Executor;
use Throwable;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization as ValidatorAuthorization;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;

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
            ->inject('deviceForFiles')
            ->inject('deviceForFunctions')
            ->inject('deviceForBuilds')
            ->inject('deviceForCache')
            ->inject('abuseRetention')
            ->inject('executionRetention')
            ->inject('auditRetention')
            ->inject('log')
            ->inject('auth')
            ->callback(fn ($message, $dbForConsole, callable $getProjectDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, string $abuseRetention, string $executionRetention, string $auditRetention, Log $log, ValidatorAuthorization $auth) => $this->action($message, $dbForConsole, $getProjectDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $abuseRetention, $executionRetention, $auditRetention, $log, $auth));
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function action(Message $message, Database $dbForConsole, callable $getProjectDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, string $abuseRetention, string $executionRetention, string $auditRetention, Log $log, ValidatorAuthorization $auth): void
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
        $project  = new Document($payload['project'] ?? []);

        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        switch (\strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                switch ($document->getCollection()) {
                    case DELETE_TYPE_PROJECTS:
                        $this->deleteProject($dbForConsole, $getProjectDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $document);
                        break;
                    case DELETE_TYPE_FUNCTIONS:
                        $this->deleteFunction($dbForConsole, $getProjectDB, $deviceForFunctions, $deviceForBuilds, $document, $project);
                        break;
                    case DELETE_TYPE_DEPLOYMENTS:
                        $this->deleteDeployment($getProjectDB, $deviceForFunctions, $deviceForBuilds, $document, $project);
                        break;
                    case DELETE_TYPE_USERS:
                        $this->deleteUser($getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_TEAMS:
                        $this->deleteMemberships($getProjectDB, $document, $project);
                        if ($project->getId() === 'console') {
                            $this->deleteProjectsByTeam($dbForConsole, $getProjectDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $document);
                        }
                        break;
                    case DELETE_TYPE_BUCKETS:
                        $this->deleteBucket($getProjectDB, $deviceForFiles, $document, $project);
                        break;
                    case DELETE_TYPE_INSTALLATIONS:
                        $this->deleteInstallation($dbForConsole, $getProjectDB, $document, $project);
                        break;
                    case DELETE_TYPE_RULES:
                        $this->deleteRule($dbForConsole, $document);
                        break;
                    default:
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                        break;
                }
                break;
            case DELETE_TYPE_EXECUTIONS:
                $this->deleteExecutionLogs($project, $getProjectDB, $executionRetention);
                break;
            case DELETE_TYPE_AUDIT:
                if (!$project->isEmpty()) {
                    $this->deleteAuditLogs($project, $getProjectDB, $auditRetention, $auth);
                }

                if (!$document->isEmpty()) {
                    $this->deleteAuditLogsByResource($getProjectDB, 'document/' . $document->getId(), $project);
                }
                break;
            case DELETE_TYPE_ABUSE:
                $this->deleteAbuseLogs($project, $getProjectDB, $abuseRetention, $auth);
                break;
            case DELETE_TYPE_REALTIME:
                $this->deleteRealtimeUsage($dbForConsole, $datetime);
                break;
            case DELETE_TYPE_SESSIONS:
                $this->deleteExpiredSessions($project, $getProjectDB);
                break;
            case DELETE_TYPE_USAGE:
                $this->deleteUsageStats($project, $getProjectDB, $hourlyUsageRetentionDatetime);
                break;
            case DELETE_TYPE_CACHE_BY_RESOURCE:
                $this->deleteCacheByResource($project, $getProjectDB, $resource, $resourceType);
                break;
            case DELETE_TYPE_CACHE_BY_TIMESTAMP:
                $this->deleteCacheByDate($project, $getProjectDB, $datetime);
                break;
            case DELETE_TYPE_SCHEDULES:
                $this->deleteSchedules($dbForConsole, $getProjectDB, $datetime);
                break;
            case DELETE_TYPE_TOPIC:
                $this->deleteTopic($project, $getProjectDB, $document);
                break;
            case DELETE_TYPE_TARGET:
                $this->deleteTargetSubscribers($project, $getProjectDB, $document);
                break;
            case DELETE_TYPE_EXPIRED_TARGETS:
                $this->deleteExpiredTargets($project, $getProjectDB);
                break;
            case DELETE_TYPE_SESSION_TARGETS:
                $this->deleteSessionTargets($project, $getProjectDB, $document);
                break;
            default:
                throw new \Exception('No delete operation for type: ' . \strval($type));
        }
    }

    /**
     * @param Database $dbForConsole
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
    private function deleteSchedules(Database $dbForConsole, callable $getProjectDB, string $datetime): void
    {
        $this->listByGroup(
            'schedules',
            [
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                Query::lessThanEqual('resourceUpdatedAt', $datetime),
                Query::equal('active', [false]),
            ],
            $dbForConsole,
            function (Document $document) use ($dbForConsole, $getProjectDB) {
                $project = $dbForConsole->getDocument('projects', $document->getAttribute('projectId'));

                if ($project->isEmpty()) {
                    $dbForConsole->deleteDocument('schedules', $document->getId());
                    Console::success('Deleted schedule for deleted project ' . $document->getAttribute('projectId'));
                    return;
                }

                $collectionId = match ($document->getAttribute('resourceType')) {
                    'function' => 'functions',
                    'message' => 'messages'
                };

                $resource = $getProjectDB($project)->getDocument(
                    $collectionId,
                    $document->getAttribute('resourceId')
                );

                $delete = true;

                switch ($document->getAttribute('resourceType')) {
                    case 'function':
                        $delete = $resource->isEmpty();
                        break;
                }

                if ($delete) {
                    $dbForConsole->deleteDocument('schedules', $document->getId());
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
                Query::equal('topicInternalId', [$topic->getInternalId()])
            ],
            $getProjectDB($project)
        );
    }

    /**
     * @param Document $project
     * @param callable $getProjectDB
     * @param Document $target
     * @throws Exception
     */
    private function deleteTargetSubscribers(Document $project, callable $getProjectDB, Document $target): void
    {
        /** @var Database */
        $dbForProject = $getProjectDB($project);

        // Delete subscribers and decrement topic counts
        $this->deleteByGroup(
            'subscribers',
            [
                Query::equal('targetInternalId', [$target->getInternalId()])
            ],
            $dbForProject,
            function (Document $subscriber) use ($dbForProject, $target) {
                $topicId = $subscriber->getAttribute('topicId');
                $topicInternalId = $subscriber->getAttribute('topicInternalId');
                $topic = $dbForProject->getDocument('topics', $topicId);
                if (!$topic->isEmpty() && $topic->getInternalId() === $topicInternalId) {
                    $totalAttribute = match ($target->getAttribute('providerType')) {
                        MESSAGE_TYPE_EMAIL => 'emailTotal',
                        MESSAGE_TYPE_SMS => 'smsTotal',
                        MESSAGE_TYPE_PUSH => 'pushTotal',
                        default => throw new Exception('Invalid target provider type'),
                    };
                    $dbForProject->decreaseDocumentAttribute(
                        'topics',
                        $topicId,
                        $totalAttribute,
                        min: 0
                    );
                }
            }
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
        $this->deleteByGroup(
            'targets',
            [
                Query::equal('expired', [true])
            ],
            $getProjectDB($project),
            function (Document $target) use ($getProjectDB, $project) {
                $this->deleteTargetSubscribers($project, $getProjectDB, $target);
            }
        );
    }

    private function deleteSessionTargets(Document $project, callable $getProjectDB, Document $session): void
    {
        $this->deleteByGroup(
            'targets',
            [
                Query::equal('sessionInternalId', [$session->getInternalId()])
            ],
            $getProjectDB($project),
            function (Document $target) use ($getProjectDB, $project) {
                $this->deleteTargetSubscribers($project, $getProjectDB, $target);
            }
        );
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

        $query[] = Query::equal('resource', [$resource]);
        if (!empty($resourceType)) {
            $query[] = Query::equal('resourceType', [$resourceType]);
        }

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
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $hourlyUsageRetentionDatetime
     * @return void
     * @throws Exception
     */
    private function deleteUsageStats(Document $project, callable $getProjectDB, string $hourlyUsageRetentionDatetime): void
    {
        $dbForProject = $getProjectDB($project);
        // Delete Usage stats
        $this->deleteByGroup('stats', [
            Query::lessThan('time', $hourlyUsageRetentionDatetime),
            Query::equal('period', ['1h']),
        ], $dbForProject);
    }

    /**
     * @param callable $getProjectDB
     * @param Document $document teams document
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteMemberships(callable $getProjectDB, Document $document, Document $project): void
    {
        $dbForProject = $getProjectDB($project);
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
                $dbForProject->purgeCachedDocument('users', $userId);
            }
        );
    }

    /**
     * @param Database $dbForConsole
     * @param Document $document
     * @return void
     * @throws Authorization
     * @throws \Utopia\Database\Exception
     * @throws Conflict
     * @throws Restricted
     * @throws Structure
     */
    private function deleteProjectsByTeam(Database $dbForConsole, callable $getProjectDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, Document $document): void
    {

        $projects = $dbForConsole->find('projects', [
            Query::equal('teamInternalId', [$document->getInternalId()])
        ]);
        foreach ($projects as $project) {
            $this->deleteProject($dbForConsole, $getProjectDB, $deviceForFiles, $deviceForFunctions, $deviceForBuilds, $deviceForCache, $project);
            $dbForConsole->deleteDocument('projects', $project->getId());
        }
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param Device $deviceForFiles
     * @param Device $deviceForFunctions
     * @param Device $deviceForBuilds
     * @param Device $deviceForCache
     * @param Document $document
     * @return void
     * @throws Exception
     * @throws Authorization
     * @throws \Utopia\Database\Exception
     */
    private function deleteProject(Database $dbForConsole, callable $getProjectDB, Device $deviceForFiles, Device $deviceForFunctions, Device $deviceForBuilds, Device $deviceForCache, Document $document): void
    {
        $projectId = $document->getId();
        $projectInternalId = $document->getInternalId();

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

        // Delete Platforms
        $this->deleteByGroup('platforms', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete project and function rules
        $this->deleteByGroup('rules', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole, function (Document $document) use ($dbForConsole) {
            $this->deleteRule($dbForConsole, $document);
        });

        // Delete Keys
        $this->deleteByGroup('keys', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete Webhooks
        $this->deleteByGroup('webhooks', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete VCS Installations
        $this->deleteByGroup('installations', [
            Query::equal('projectInternalId', [$projectInternalId])
        ], $dbForConsole);

        // Delete VCS Repositories
        $this->deleteByGroup('repositories', [
            Query::equal('projectInternalId', [$projectInternalId]),
        ], $dbForConsole);

        // Delete VCS commments
        $this->deleteByGroup('vcsComments', [
            Query::equal('projectInternalId', [$projectInternalId]),
        ], $dbForConsole);

        // Delete metadata tables
        try {
            $dbForProject->deleteCollection('_metadata');
        } catch (\Throwable) {
            // Ignore: deleteCollection tries to delete a metadata entry after the collection is deleted,
            // which will throw an exception here because the metadata collection is already deleted.
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
            Query::equal('userInternalId', [$userInternalId])
        ], $dbForProject);

        $dbForProject->purgeCachedDocument('users', $userId);

        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup('memberships', [
            Query::equal('userInternalId', [$userInternalId])
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
            Query::equal('userInternalId', [$userInternalId])
        ], $dbForProject);

        // Delete identities
        $this->deleteByGroup('identities', [
            Query::equal('userInternalId', [$userInternalId])
        ], $dbForProject);

        // Delete targets
        $this->deleteByGroup(
            'targets',
            [
                Query::equal('userInternalId', [$userInternalId])
            ],
            $dbForProject,
            function (Document $target) use ($getProjectDB, $project) {
                $this->deleteTargetSubscribers($project, $getProjectDB, $target);
            }
        );
    }

    /**
     * @param database $dbForConsole
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
            Query::lessThan('$createdAt', $datetime)
        ], $dbForProject);
    }

    /**
     * @param Database $dbForConsole
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
            Query::lessThan('$createdAt', $expired)
        ], $dbForProject);
    }

    /**
     * @param Database $dbForConsole
     * @param string $datetime
     * @return void
     * @throws Exception
     */
    private function deleteRealtimeUsage(Database $dbForConsole, string $datetime): void
    {
        // Delete Dead Realtime Logs
        $this->deleteByGroup('realtime', [
            Query::lessThan('timestamp', $datetime)
        ], $dbForConsole);
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @return void
     * @throws Exception
     */
    private function deleteAbuseLogs(Document $project, callable $getProjectDB, string $abuseRetention, ValidatorAuthorization $auth): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);
        $timeLimit = new TimeLimit("", 0, 1, $dbForProject, $auth);
        $abuse = new Abuse($timeLimit, $auth);
        $status = $abuse->cleanup($abuseRetention);
        if (!$status) {
            throw new Exception('Failed to delete Abuse logs for project ' . $projectId);
        }
    }

    /**
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param string $datetime
     * @return void
     * @throws Exception
     */
    private function deleteAuditLogs(Document $project, callable $getProjectDB, string $auditRetention, ValidatorAuthorization $auth): void
    {
        $projectId = $project->getId();
        $dbForProject = $getProjectDB($project);
        $audit = new Audit($dbForProject, $auth);
        $status = $audit->cleanup($auditRetention);
        if (!$status) {
            throw new Exception('Failed to delete Audit logs for project' . $projectId);
        }
    }

    /**
     * @param callable $getProjectDB
     * @param string $resource
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteAuditLogsByResource(callable $getProjectDB, string $resource, Document $project): void
    {
        $dbForProject = $getProjectDB($project);

        $this->deleteByGroup(Audit::COLLECTION, [
            Query::equal('resource', [$resource])
        ], $dbForProject);
    }

    /**
     * @param callable $getProjectDB
     * @param Device $deviceForFunctions
     * @param Device $deviceForBuilds
     * @param Document $document function document
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteFunction(Database $dbForConsole, callable $getProjectDB, Device $deviceForFunctions, Device $deviceForBuilds, Document $document, Document $project): void
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
            Query::equal('resourceType', ['function']),
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::equal('projectInternalId', [$project->getInternalId()])
        ], $dbForConsole, function (Document $document) use ($project, $dbForConsole) {
            $this->deleteRule($dbForConsole, $document);
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

        $deploymentInternalIds = [];
        $this->deleteByGroup('deployments', [
            Query::equal('resourceInternalId', [$functionInternalId])
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
                Query::equal('deploymentInternalId', [$deploymentInternalId])
            ], $dbForProject, function (Document $document) use ($deviceForBuilds) {
                $this->deleteBuildFiles($deviceForBuilds, $document);
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
         * Delete VCS Repositories and VCS Comments
         */
        Console::info("Deleting VCS repositories and comments linked to function " . $functionId);
        $this->deleteByGroup('repositories', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::equal('resourceInternalId', [$functionInternalId]),
            Query::equal('resourceType', ['function']),
        ], $dbForConsole, function (Document $document) use ($dbForConsole) {
            $providerRepositoryId = $document->getAttribute('providerRepositoryId', '');
            $projectInternalId = $document->getAttribute('projectInternalId', '');
            $this->deleteByGroup('vcsComments', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::equal('projectInternalId', [$projectInternalId]),
            ], $dbForConsole);
        });

        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete all deployment containers for function " . $functionId);
        $this->deleteRuntimes($getProjectDB, $document, $project);
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
        } catch (\Throwable $th) {
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
        } catch (\Throwable $th) {
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
     * @return void
     * @throws Exception
     */
    private function deleteDeployment(callable $getProjectDB, Device $deviceForFunctions, Device $deviceForBuilds, Document $document, Document $project): void
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
            Query::equal('deploymentInternalId', [$deploymentInternalId])
        ], $dbForProject, function (Document $document) use ($deviceForBuilds) {
            $this->deleteBuildFiles($deviceForBuilds, $document);
        });

        /**
         * Request executor to delete all deployment containers
         */
        Console::info("Requesting executor to delete deployment container for deployment " . $deploymentId);
        $this->deleteRuntimes($getProjectDB, $document, $project);
    }

    /**
     * @param Document $document to be deleted
     * @param Database $database to delete it from
     * @param callable|null $callback to perform after document is deleted
     * @return void
     */
    private function deleteById(Document $document, Database $database, callable $callback = null): void
    {
        if ($database->deleteDocument($document->getCollection(), $document->getId())) {
            Console::success('Deleted document "' . $document->getId() . '" successfully');

            if (is_callable($callback)) {
                $callback($document);
            }
        } else {
            Console::error('Failed to delete document: ' . $document->getId());
        }
    }

    /**
     * @param string $collection collectionID
     * @param array $queries
     * @param Database $database
     * @param callable|null $callback
     * @return void
     * @throws Exception
     */
    private function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
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
     * @return void
     * @throws Exception
     */
    private function listByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
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
     * @param Database $dbForConsole
     * @param Document $document rule document
     * @return void
     */
    private function deleteRule(Database $dbForConsole, Document $document): void
    {

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
            $dbForConsole->deleteDocument('certificates', $document['certificateId']);
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
     * @param Database $dbForConsole
     * @param callable $getProjectDB
     * @param Document $document
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteInstallation(Database $dbForConsole, callable $getProjectDB, Document $document, Document $project): void
    {
        $dbForProject = $getProjectDB($project);

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

    /**
     * @param callable $getProjectDB
     * @param ?Document $function
     * @param Document $project
     * @return void
     * @throws Exception
     */
    private function deleteRuntimes(callable $getProjectDB, ?Document $function, Document $project): void
    {
        $executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));

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
