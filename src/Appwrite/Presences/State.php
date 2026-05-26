<?php

namespace Appwrite\Presences;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime as QueueRealtime;
use Appwrite\Extend\Exception;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Database\Documents\User;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class State
{
    public const LIST_CACHE_FIELD_PRESENCES = 'presences';
    public const LIST_CACHE_FIELD_TOTAL = 'total';
    public const COLLECTION_ID = 'presenceLogs';

    public function setPermissions(
        Document $document,
        ?array $permissions,
        User $user,
        Authorization $authorization,
        ?string $ownerOverride = null,
    ): Document {
        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
            Database::PERMISSION_WRITE,
        ];

        if ($ownerOverride !== null) {
            $permissions = [];
            foreach ($allowedPermissions as $permission) {
                $permissions[] = (new Permission($permission, 'user', $ownerOverride))->toString();
            }
        } else {
            $isAPIKey = $user->isKey($authorization->getRoles());
            $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

            $permissions = Permission::aggregate($permissions, $allowedPermissions);

            if (\is_null($permissions)) {
                $permissions = [];
                if (!empty($user->getId()) && !$isPrivilegedUser) {
                    foreach ($allowedPermissions as $permission) {
                        $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                    }
                }
            }

            if (!$isAPIKey && !$isPrivilegedUser) {
                $this->checkPermissions($permissions, $authorization);
            }
        }

        sort($permissions, SORT_STRING);
        $document->setAttribute('$permissions', $permissions);
        $document->setAttribute('permissionsHash', \md5(\json_encode($permissions)));

        return $document;
    }

    public function upsertForUser(
        Database $dbForProject,
        Document $presenceDocument,
        string $presenceId,
        mixed $userInternalId,
        ?callable $onPresenceCreated = null
    ): Document {
        if ($presenceId === 'unique()') {
            $presenceId = ID::unique();
        }
        $presenceDocument->setAttribute('$id', $presenceId);

        $presenceCreated = false;

        try {
            if ($dbForProject->getAdapter()->getSupportForUpsertOnUniqueIndex()) {
                $existingPresence = $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userInternalId', [$userInternalId])]);
                if ($existingPresence->isEmpty()) {
                    $presenceCreated = true;
                } else {
                    $presenceDocument->setAttribute('$id', $existingPresence->getId());
                }
                $presence = $dbForProject->upsertDocument(self::COLLECTION_ID, $presenceDocument);
            } else {
                $presence = $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $userInternalId, &$presenceCreated) {
                    $existingPresence = $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userInternalId', [$userInternalId])]);

                    if ($existingPresence->isEmpty()) {
                        $presenceCreated = true;
                        return $dbForProject->createDocument(self::COLLECTION_ID, $presenceDocument);
                    }

                    $currentPresence = $dbForProject->getDocument(self::COLLECTION_ID, $existingPresence->getId(), forUpdate: true);

                    if ($currentPresence->isEmpty()) {
                        throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$existingPresence->getId()]);
                    }

                    $presenceDocument->setAttribute('$id', $currentPresence->getId());

                    return $dbForProject->updateDocument(self::COLLECTION_ID, $currentPresence->getId(), $presenceDocument);
                });
            }

            if ($presenceCreated && $onPresenceCreated !== null) {
                call_user_func($onPresenceCreated);
            }

            return $presence;
        } catch (DuplicateException $e) {
            throw new Exception(Exception::PRESENCE_ALREADY_EXISTS, params: [$presenceId], previous: $e);
        } catch (NotFoundException $e) {
            throw new Exception(Exception::PRESENCE_NOT_FOUND, params: [$presenceId], previous: $e);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage(), previous: $e);
        } catch (ConflictException $e) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT, $e->getMessage(), previous: $e);
        }
    }

    private function checkPermissions(array $permissions, Authorization $authorization): void
    {
        foreach (Database::PERMISSIONS as $type) {
            foreach ($permissions as $permission) {
                $permission = Permission::parse($permission);
                if ($permission->getPermission() != $type) {
                    continue;
                }

                $role = (new Role(
                    $permission->getRole(),
                    $permission->getIdentifier(),
                    $permission->getDimension()
                ))->toString();

                if (!$authorization->hasRole($role)) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $authorization->getRoles()) . ')');
                }
            }
        }
    }

    private function getListCacheFieldKey(array $roles, array $queries, string $type): string
    {
        $serialized = \array_map(
            static fn ($query) => $query instanceof Query ? $query->toArray() : $query,
            $queries,
        );

        return \sprintf(
            '%s:%s:%s',
            \md5(\json_encode($roles)),
            \md5(\json_encode($serialized)),
            $type,
        );
    }

    public function getListCacheField(
        Database $dbForProject,
        array $roles,
        array $queries,
        string $type,
        int $ttl
    ): mixed {
        $cacheField = $this->getListCacheFieldKey($roles, $queries, $type);
        [$collectionKey] = $dbForProject->getCacheKeys(self::COLLECTION_ID);

        try {
            return $dbForProject->getCache()->load($collectionKey, $ttl, $cacheField);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setListCacheField(
        Database $dbForProject,
        array $roles,
        array $queries,
        string $type,
        mixed $value
    ): void {
        $cacheField = $this->getListCacheFieldKey($roles, $queries, $type);
        [$collectionKey] = $dbForProject->getCacheKeys(self::COLLECTION_ID);

        try {
            $dbForProject->getCache()->save($collectionKey, $value, $cacheField);
        } catch (\Throwable) {
        }
    }

    public function purgeListCache(Database $dbForProject): bool
    {
        [$collectionKey] = $dbForProject->getCacheKeys(self::COLLECTION_ID);

        return $dbForProject->getCache()->purge($collectionKey);
    }

    public function triggerUsage(
        UsagePublisher $publisher,
        Document $project,
        int $value,
    ): void {
        if ($project->isEmpty()) {
            return;
        }

        try {
            $usage = new UsageContext();
            $usage->addMetric(METRIC_USERS_PRESENCE, $value);

            $publisher->enqueue(new UsageMessage(
                project: $project,
                metrics: $usage->getMetrics(),
            ));
        } catch (Throwable $th) {
            if (\function_exists('logError')) {
                \logError($th, 'realtimeStats', tags: ['projectId' => $project->getId()]);
            }
        }
    }

    public function triggerEvent(
        QueueEvent $queueForEvents,
        QueueRealtime $queueForRealtime,
        Document $project,
        User $user,
        string $eventName,
        Document $presence,
    ): void {
        if ($project->isEmpty() || $presence->isEmpty()) {
            return;
        }

        try {
            $queueForEvents
                ->reset()
                ->setProject($project)
                ->setUser($user)
                ->setEvent($eventName)
                ->setParam('presenceId', $presence->getId())
                ->setPayload($presence->getArrayCopy());

            $queueForRealtime
                ->reset()
                ->setProject($project)
                ->setUser($user)
                ->from($queueForEvents)
                ->trigger();
        } catch (Throwable $th) {
            if (\function_exists('logError')) {
                \logError($th, 'realtimePresenceEvent', tags: [
                    'projectId' => $project->getId(),
                    'event' => $eventName,
                ]);
            }
        }
    }
}
