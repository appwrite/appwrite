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
use Utopia\Database\Operator;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Span\Span;

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

    /**
     * Upsert a user's presence row and raise its per-socket `connections` refcount -- the
     * connection-aware counterpart to {@see upsertForUser}. A socket still holding the live row
     * -- matched by its internal `$sequence`, which a delete-and-recreate changes even when the
     * explicit id is reused -- just refreshes it; otherwise the count goes up (create = 1, else +1).
     *
     * @return array{presence: Document, created: bool, counted: bool}
     */
    public function upsertForUserWithConnections(
        Database $dbForProject,
        Document $presenceDocument,
        string $userId,
        ?string $sequence,
    ): array {
        $created = false;
        $counted = false;
        try {
            if ($sequence !== null) {
                $existing = $dbForProject->getAuthorization()->skip(
                    fn () => $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userId', [$userId])])
                );
                if (!$existing->isEmpty() && (string) $existing->getSequence() === $sequence) {
                    $presenceDocument->setAttribute('$id', $existing->getId());
                    $presence = $dbForProject->updateDocument(self::COLLECTION_ID, $existing->getId(), $presenceDocument);

                    return ['presence' => $presence, 'created' => false, 'counted' => false];
                }
            }

            // Transactional so the increment serializes against the decrement-to-zero delete; a
            // create that loses the unique-userId race retries and then increments the winner's row.
            $duplicate = null;
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                try {
                    $presence = $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $userId, &$created, &$counted) {
                        // Reset per attempt: a prior attempt that set $created then lost the create
                        // race must not report a creation when this retry only increments.
                        $created = false;
                        $counted = false;

                        $existing = $dbForProject->getAuthorization()->skip(
                            fn () => $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userId', [$userId])])
                        );

                        if ($existing->isEmpty()) {
                            if ($presenceDocument->getId() === '') {
                                $presenceDocument->setAttribute('$id', Id::unique());
                            }
                            $presenceDocument->setAttribute('connections', 1);
                            $created = true;
                            $counted = true;

                            return $dbForProject->createDocument(self::COLLECTION_ID, $presenceDocument);
                        }

                        $current = $dbForProject->getAuthorization()->skip(
                            fn () => $dbForProject->getDocument(self::COLLECTION_ID, $existing->getId(), forUpdate: true)
                        );
                        if ($current->isEmpty()) {
                            throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$existing->getId()]);
                        }

                        $presenceDocument->setAttribute('$id', $current->getId());
                        $presenceDocument->setAttribute('connections', Operator::increment(1));
                        $counted = true;

                        return $dbForProject->updateDocument(self::COLLECTION_ID, $current->getId(), $presenceDocument);
                    });

                    return ['presence' => $presence, 'created' => $created, 'counted' => $counted];
                } catch (DuplicateException $e) {
                    $duplicate = $e;
                }
            }

            // Every attempt lost the create race for this user.
            throw new Exception(Exception::PRESENCE_ALREADY_EXISTS, params: [$userId], previous: $duplicate);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage(), previous: $e);
        } catch (ConflictException $e) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT, $e->getMessage(), previous: $e);
        }
    }

    /**
     * Release one socket's connection on a user -- the inverse of
     * {@see upsertForUserWithConnections}. Decrements `connections`, deleting the row at zero and
     * returning it so the caller can emit the offline event; returns null otherwise.
     */
    public function releaseConnectionForUser(Database $dbForProject, string $userId, string $sequence): ?Document
    {
        return $dbForProject->withTransaction(function () use ($dbForProject, $userId, $sequence): ?Document {
            $existing = $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userId', [$userId])])
            );
            if ($existing->isEmpty()) {
                return null;
            }

            $current = $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->getDocument(self::COLLECTION_ID, $existing->getId(), forUpdate: true)
            );
            if ($current->isEmpty()) {
                return null;
            }

            // Row recreated since this socket incremented it (a delete-and-recreate gets a new
            // internal sequence even when the id is reused): its +1 is already gone, don't touch.
            if ((string) $current->getSequence() !== $sequence) {
                return null;
            }

            $remaining = (int) $current->getAttribute('connections', 0) - 1;
            if ($remaining > 0) {
                $dbForProject->getAuthorization()->skip(
                    fn () => $dbForProject->updateDocument(self::COLLECTION_ID, $current->getId(), new Document(['connections' => $remaining]))
                );

                return null;
            }

            $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->deleteDocument(self::COLLECTION_ID, $current->getId())
            );

            return $current;
        });
    }

    /**
     * After {@see releaseConnectionForUser} deletes a user's row, trigger the offline side
     * effects: purge the list cache, decrement usage (when counted) and emit the delete event.
     * `$deleted` is the document that release returned.
     */
    public function triggerPresenceDeleted(
        Database $dbForProject,
        Document $project,
        string $userId,
        Document $deleted,
        UsagePublisher $publisherForUsage,
        QueueEvent $queueForEvents,
        QueueRealtime $queueForRealtime,
        bool $triggerUsage = true,
    ): void {
        $this->purgeListCache($dbForProject);

        if ($triggerUsage) {
            $this->triggerUsage($publisherForUsage, $project, -1);
        }

        // A transient user lookup failure must not suppress the delete event (the close path does
        // not retry) -- emit with an empty user, as the old close cleanup did.
        try {
            $fetched = $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->getDocument('users', $userId)
            );
            $user = $fetched->isEmpty() ? new User([]) : new User($fetched->getArrayCopy());
        } catch (\Throwable) {
            $user = new User([]);
        }

        foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId', 'connections'] as $attribute) {
            $deleted->removeAttribute($attribute);
        }

        $this->triggerEvent($queueForEvents, $queueForRealtime, $project, $user, 'presences.[presenceId].delete', $deleted);
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
