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
use Utopia\Database\Capability;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Pools\Group;
use Utopia\Span\Span;

class State
{
    public const LIST_CACHE_FIELD_PRESENCES = 'presences';
    public const LIST_CACHE_FIELD_TOTAL = 'total';
    public const COLLECTION_ID = 'presenceLogs';

    public const int LOCK_TTL = 10;
    public const int LOCK_TIMEOUT = 5;

    public static function getLockKey(string $projectId, string $userId): string
    {
        return 'presence:' . $projectId . ':' . $userId;
    }

    public static function getConnectionCounterKey(string $projectId, string $userId): string
    {
        return 'presence:connections:' . $projectId . ':' . $userId;
    }

    public function incrementConnections(\Redis $redis, string $projectId, string $userId): void
    {
        $redis->incr(self::getConnectionCounterKey($projectId, $userId));
    }

    public function decrementConnections(\Redis $redis, string $projectId, string $userId): int
    {
        return $this->decrementConnectionsBy($redis, $projectId, $userId, 1);
    }

    public function decrementConnectionsBy(\Redis $redis, string $projectId, string $userId, int $by): int
    {
        $key = self::getConnectionCounterKey($projectId, $userId);
        $remaining = (int) $redis->decrBy($key, $by);
        if ($remaining <= 0) {
            $redis->del($key);
        }

        return $remaining;
    }

    /**
     * Release a connection's hold: decrement the live-holder count and, at the last
     * holder, delete the row(s) under the lock. Returns the deleted presences, cleaned
     * for emission, or an empty array when nothing was deleted.
     *
     * @param  array<int, string>  $presenceIds
     * @return array<string, Document>
     */
    public function releaseConnection(
        Group $pools,
        Database $dbForProject,
        string $projectId,
        string $userId,
        array $presenceIds,
    ): array {
        $deleted = [];

        $pools->get('lock')->use(function (\Redis $redis) use ($dbForProject, $presenceIds, $projectId, $userId, &$deleted): void {
            $lock = new Distributed($redis, self::getLockKey($projectId, $userId), ttl: self::LOCK_TTL);

            try {
                $lock->withLock(function () use ($lock, $dbForProject, $presenceIds, $redis, $projectId, $userId, &$deleted): void {
                    // Exact zero only; a negative count means Redis lost the key, so keep the row for expiresAt.
                    if ($this->decrementConnections($redis, $projectId, $userId) !== 0) {
                        return;
                    }

                    // Fence: refresh() proves we still hold the lease. If lost, another op may own the row, so leave it to expiresAt.
                    if (!$lock->refresh()) {
                        return;
                    }

                    foreach ($presenceIds as $presenceId) {
                        $presence = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument(self::COLLECTION_ID, $presenceId));
                        if ($presence->isEmpty()) {
                            continue;
                        }

                        $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteDocument(self::COLLECTION_ID, $presenceId));
                        $deleted[$presence->getId()] = $this->stripInternalAttributes($presence);
                    }
                }, timeout: self::LOCK_TIMEOUT);
            } catch (Contention) {
                // On contention, leave the row to expiresAt rather than decrement outside the lock.
            }
        });

        return $deleted;
    }

    /**
     * Balance the counter after a REST delete removed the row out-of-band: the holders it
     * dropped stop counting. Counter only -- the row is already gone, nothing to delete.
     */
    public function releaseStrippedHolders(Group $pools, string $projectId, string $userId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $pools->get('lock')->use(function (\Redis $redis) use ($projectId, $userId, $count): void {
            $lock = new Distributed($redis, self::getLockKey($projectId, $userId), ttl: self::LOCK_TTL);

            try {
                $lock->withLock(fn () => $this->decrementConnectionsBy($redis, $projectId, $userId, $count), timeout: self::LOCK_TIMEOUT);
            } catch (Contention) {
                // On contention, let the counter drift; the row is already gone and expiresAt covers it.
            }
        });
    }

    public function stripInternalAttributes(Document $presence): Document
    {
        foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId'] as $attribute) {
            $presence->removeAttribute($attribute);
        }

        return $presence;
    }

    public function setPermissions(
        Document $document,
        ?array $permissions,
        User $user,
        Authorization $authorization,
        ?string $ownerOverride = null,
    ): Document {
        $allowedPermissions = [
            PermissionType::Read,
            PermissionType::Update,
            PermissionType::Delete,
            PermissionType::Write,
        ];

        if ($ownerOverride !== null) {
            $permissions = [];
            foreach ($allowedPermissions as $permission) {
                $permissions[] = (new Permission($permission->value, 'user', $ownerOverride))->toString();
            }
        } else {
            $isAPIKey = $user->isKey($authorization->getRoles());
            $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

            $permissions = Permission::aggregate($permissions, $allowedPermissions);

            if (\is_null($permissions)) {
                $permissions = [];
                if (!empty($user->getId()) && !$isPrivilegedUser) {
                    foreach ($allowedPermissions as $permission) {
                        $permissions[] = (new Permission($permission->value, 'user', $user->getId()))->toString();
                    }
                }
            }

            if (!$isAPIKey && !$isPrivilegedUser) {
                $this->checkPermissions($permissions, $authorization);

                // Whatever roles a client shares its presence with, it must still be able to
                // heartbeat, update and delete it.
                if (!empty($user->getId())) {
                    $permissions[] = Permission::update(Role::user($user->getId()));
                    $permissions[] = Permission::delete(Role::user($user->getId()));
                    $permissions = \array_values(\array_unique($permissions));
                }
            }
        }

        sort($permissions, SORT_STRING);
        $document->setAttribute('$permissions', $permissions);
        $document->setAttribute('permissionsHash', \md5(\json_encode($permissions)));

        return $document;
    }

    /**
     * Convert a request metadata object to the associative shape Document
     * expects while retaining empty objects at any nested depth. `null` is
     * returned untouched so callers can distinguish "not provided" from an
     * explicit empty object.
     *
     * @return array<string, mixed>|null
     */
    public function normalizeMetadata(array|\stdClass|null $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if ($metadata instanceof \stdClass) {
            $metadata = (array) $metadata;
        }

        return \array_map($this->normalizeMetadataValue(...), $metadata);
    }

    private function normalizeMetadataValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = (array) $value;

            return $properties === []
                ? $value
                : \array_map($this->normalizeMetadataValue(...), $properties);
        }

        if (\is_array($value)) {
            return \array_map($this->normalizeMetadataValue(...), $value);
        }

        return $value;
    }

    public function upsertForUser(
        Database $dbForProject,
        Document $presenceDocument,
        string $presenceId,
        mixed $userInternalId,
        ?callable $onPresenceCreated = null
    ): Document {
        if ($presenceId === 'unique()') {
            $presenceId = Id::unique();
        }
        $presenceDocument->setAttribute('$id', $presenceId);

        $presenceCreated = false;
        $userId = $presenceDocument->getAttribute('userId');
        $existingQuery = \is_string($userId) && $userId !== ''
            ? [Query::equal('userId', [$userId])]
            : [Query::equal('userInternalId', [$userInternalId])];

        try {
            if ($dbForProject->getAdapter()->supports(Capability::UpsertOnUniqueIndex)) {
                $existingPresence = $dbForProject->getAuthorization()->skip(
                    fn () => $dbForProject->findOne(self::COLLECTION_ID, $existingQuery)
                );
                if ($existingPresence->isEmpty()) {
                    $presenceCreated = true;
                } else {
                    $presenceDocument->setAttribute('$id', $existingPresence->getId());
                }
                $presence = $dbForProject->upsertDocument(self::COLLECTION_ID, $presenceDocument);
            } else {
                $presence = $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $existingQuery, &$presenceCreated) {
                    $existingPresence = $dbForProject->getAuthorization()->skip(
                        fn () => $dbForProject->findOne(self::COLLECTION_ID, $existingQuery)
                    );
                    $presenceCreated = $existingPresence->isEmpty();

                    if ($presenceCreated) {
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
        foreach ([PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete] as $type) {
            foreach ($permissions as $permission) {
                $permission = Permission::parse($permission);
                if ($permission->getPermission() != $type->value) {
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
            Span::current()?->setError($th)->set('project.id', $project->getId());
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
            Span::current()?->setError($th)
                ->set('project.id', $project->getId())
                ->set('presence.event', $eventName);
        }
    }
}
