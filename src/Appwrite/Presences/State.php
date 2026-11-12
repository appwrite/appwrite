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
use Utopia\Database\DateTime;
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
        $previousHash = null;
        try {
            if ($sequence !== null) {
                $existing = $dbForProject->getAuthorization()->skip(
                    fn () => $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userId', [$userId])])
                );
                if (!$existing->isEmpty() && (string) $existing->getSequence() === $sequence) {
                    $presenceDocument->setAttribute('$id', $existing->getId());
                    $presence = $dbForProject->updateDocument(self::COLLECTION_ID, $existing->getId(), $presenceDocument);
                    $this->purgeListCacheOnPermissionChange($dbForProject, $existing->getAttribute('permissionsHash'), $presence);

                    return ['presence' => $presence, 'created' => false, 'counted' => false];
                }
            }

            // Transactional so the increment serializes against the decrement-to-zero delete; a
            // create that loses the unique-userId race retries and then increments the winner's row.
            $duplicate = null;
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                try {
                    $presence = $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $userId, &$created, &$counted, &$previousHash) {
                        // Reset per attempt: a prior attempt that set $created then lost the create
                        // race must not report a creation when this retry only increments.
                        $created = false;
                        $counted = false;
                        $previousHash = null;

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

                        $previousHash = $current->getAttribute('permissionsHash');
                        $presenceDocument->setAttribute('$id', $current->getId());
                        $presenceDocument->setAttribute('connections', Operator::increment(1));
                        $counted = true;

                        return $dbForProject->updateDocument(self::COLLECTION_ID, $current->getId(), $presenceDocument);
                    });

                    $this->purgeListCacheOnPermissionChange($dbForProject, $previousHash, $presence);

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
        $previousHash = null;
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
                    // Create, not upsert, so a row another request inserts concurrently collides here
                    // (duplicate) instead of being silently overwritten by this write. A genuine first
                    // insert only adds a row and cannot leak one under a former ACL, so it never purges.
                    try {
                        $presence = $dbForProject->createDocument(self::COLLECTION_ID, $presenceDocument);
                        $presenceCreated = true;
                    } catch (DuplicateException $e) {
                        // Lost the create race: the row exists now. Re-read it, upsert over it, and
                        // purge on a real permission change so a narrowing cannot keep being served
                        // from a page cached under the former ACL. If the re-read finds no row the
                        // duplicate was the requested id colliding with another user's presence, not
                        // a userId race -- propagate it to the PRESENCE_ALREADY_EXISTS handler below.
                        $raced = $dbForProject->getAuthorization()->skip(
                            fn () => $dbForProject->findOne(self::COLLECTION_ID, $existingQuery)
                        );
                        if ($raced->isEmpty()) {
                            throw $e;
                        }
                        $previousHash = $raced->getAttribute('permissionsHash');
                        $presenceDocument->setAttribute('$id', $raced->getId());
                        $presence = $dbForProject->upsertDocument(self::COLLECTION_ID, $presenceDocument);
                        $this->purgeListCacheOnPermissionChange($dbForProject, $previousHash, $presence);
                    }
                } else {
                    $previousHash = $existingPresence->getAttribute('permissionsHash');
                    $presenceDocument->setAttribute('$id', $existingPresence->getId());
                    $presence = $dbForProject->upsertDocument(self::COLLECTION_ID, $presenceDocument);
                    $this->purgeListCacheOnPermissionChange($dbForProject, $previousHash, $presence);
                }
            } else {
                $presence = $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $existingQuery, &$presenceCreated, &$previousHash) {
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

                    $previousHash = $currentPresence->getAttribute('permissionsHash');
                    $presenceDocument->setAttribute('$id', $currentPresence->getId());

                    return $dbForProject->updateDocument(self::COLLECTION_ID, $currentPresence->getId(), $presenceDocument);
                });

                // The transactional path holds the row for update and a racing insert fails with a
                // duplicate rather than being silently overwritten, so a create cannot hide a prior
                // row: purge only on a real permission change.
                $this->purgeListCacheOnPermissionChange($dbForProject, $previousHash, $presence);
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

    /**
     * Deletes the presence of a user who can no longer be online, such as a blocked or
     * deleted one, so subscribers drop it now instead of at `expiresAt`. The user is already
     * blocked or deleted when this runs, so a failure here is recorded, not thrown.
     */
    public function deleteForUser(
        Database $dbForProject,
        Document $project,
        Document $user,
        QueueRealtime $queueForRealtime,
        UsageContext $usage,
    ): void {
        try {
            /** @var array<Document> $presences */
            $presences = [];
            $dbForProject->getAuthorization()->skip(function () use ($dbForProject, $user, &$presences): void {
                $dbForProject->deleteDocuments(
                    self::COLLECTION_ID,
                    [Query::equal('userInternalId', [$user->getSequence()])],
                    onNext: function (Document $presence) use (&$presences): void {
                        $presences[] = $presence;
                    },
                );
            });

            if (empty($presences)) {
                return;
            }

            $this->purgeListCache($dbForProject);
            $usage->addMetric(METRIC_USERS_PRESENCE, -\count($presences));

            foreach ($presences as $presence) {
                foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId', 'connections'] as $attribute) {
                    $presence->removeAttribute($attribute);
                }

                $queueForRealtime
                    ->reset()
                    ->setProject($project)
                    ->setUser($user)
                    ->setEvent('presences.[presenceId].delete')
                    ->setParam('presenceId', $presence->getId())
                    ->setPayload($presence->getArrayCopy())
                    ->trigger();
            }
        } catch (Throwable $th) {
            Span::current()?->setError($th)
                ->set('project.id', $project->getId())
                ->set('user.id', $user->getId());
        }
    }

    /**
     * Hostnames of the other realtime processes that sent a heartbeat within `$window` seconds
     * of `$before`. Null when a live heartbeat does not name its host (written by an older
     * version), as then no other host can be called dead. This host never counts as live: the
     * hostname is ours now, so any earlier heartbeat under it came from a run that is gone.
     *
     * @return list<string>|null
     */
    public function getLiveHosts(
        Database $dbForPlatform,
        string $hostname,
        string $before,
        int $window = REALTIME_HEARTBEAT_WINDOW_SECONDS,
    ): ?array {
        $since = DateTime::addSeconds(new \DateTime($before), -$window);

        return $dbForPlatform->getAuthorization()->skip(function () use ($dbForPlatform, $hostname, $since): ?array {
            $hosts = [];
            foreach ($dbForPlatform->cursor('realtime', [Query::greaterThan('timestamp', $since)]) as $heartbeat) {
                // `container` is `<process id>@<hostname>`.
                $container = (string) $heartbeat->getAttribute('container', '');
                $separator = \strrpos($container, '@');
                if ($separator === false) {
                    return null;
                }

                $host = \substr($container, $separator + 1);
                if ($host !== $hostname) {
                    $hosts[] = $host;
                }
            }

            return \array_values(\array_unique($hosts));
        });
    }

    /**
     * Runs {@see deleteOrphaned} on every project until done or `$deadline` (unix seconds).
     * Retries while the platform database is not ready; a failing project is recorded on the
     * current span and skipped.
     *
     * @param callable(Document): Database $getProjectDB
     * @return array{deleted: int, projects: int, failed: int, outcome: 'done'|'timeout'|'failed'}
     */
    public function deleteOrphanedInProjects(
        Database $dbForPlatform,
        callable $getProjectDB,
        string $hostname,
        string $before,
        float $deadline,
        UsagePublisher $publisherForUsage,
        QueueRealtime $queueForRealtime,
        int $retrySeconds = DATABASE_RECONNECT_SLEEP,
    ): array {
        $deleted = 0;
        $projects = 0;
        $failed = 0;
        $error = null;

        while (\microtime(true) < $deadline) {
            $projects = 0;
            $failed = 0;

            try {
                $liveHosts = $this->getLiveHosts($dbForPlatform, $hostname, $before);

                $finished = $dbForPlatform->getAuthorization()->skip(function () use ($dbForPlatform, $getProjectDB, $hostname, $liveHosts, $before, $deadline, $publisherForUsage, $queueForRealtime, &$deleted, &$projects, &$failed): bool {
                    foreach ($dbForPlatform->cursor('projects', batchSize: 25) as $project) {
                        if (\microtime(true) >= $deadline) {
                            return false;
                        }

                        $projects++;
                        try {
                            $deleted += $this->deleteOrphaned($getProjectDB($project), $project, $hostname, $liveHosts, $before, $publisherForUsage, $queueForRealtime);
                        } catch (Throwable $th) {
                            $failed++;
                            Span::current()?->setError($th)->set('project.id', $project->getId());
                        }
                    }

                    return true;
                });

                return ['deleted' => $deleted, 'projects' => $projects, 'failed' => $failed, 'outcome' => $finished ? 'done' : 'timeout'];
            } catch (Throwable $th) {
                // Usually the database or schema isn't ready yet; a lasting error is retried until the deadline and set on the span.
                $error = $th;
                \sleep($retrySeconds);
            }
        }

        if ($error !== null) {
            Span::current()?->setError($error);
        }

        return ['deleted' => $deleted, 'projects' => $projects, 'failed' => $failed, 'outcome' => $error === null ? 'timeout' : 'failed'];
    }

    /**
     * Finishes a sweep that {@see deleteOrphanedInProjects} left undone (timed out, failed or
     * with failed projects), while serving. Safe then: rows written since `$before` are never
     * deleted. Rescans every project per attempt, up to `$attempts`, with doubling backoff.
     *
     * @param callable(Document): Database $getProjectDB
     * @return array{deleted: int, projects: int, failed: int, outcome: 'done'|'timeout'|'failed', attempts: int}
     */
    public function resumeOrphanedInProjects(
        Database $dbForPlatform,
        callable $getProjectDB,
        string $hostname,
        string $before,
        UsagePublisher $publisherForUsage,
        QueueRealtime $queueForRealtime,
        int $attempts = 5,
        int $attemptSeconds = 600,
        int $retrySeconds = DATABASE_RECONNECT_SLEEP,
    ): array {
        $deleted = 0;
        $result = ['deleted' => 0, 'projects' => 0, 'failed' => 0, 'outcome' => 'failed'];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1) {
                \sleep($retrySeconds * 2 ** ($attempt - 2));
            }

            $result = $this->deleteOrphanedInProjects($dbForPlatform, $getProjectDB, $hostname, $before, \microtime(true) + $attemptSeconds, $publisherForUsage, $queueForRealtime, $retrySeconds);
            $deleted += $result['deleted'];

            if ($result['outcome'] === 'done' && $result['failed'] === 0) {
                break;
            }
        }

        return [...$result, 'deleted' => $deleted, 'attempts' => \min($attempt, $attempts)];
    }

    /**
     * Deletes the realtime presences in one project whose host is no longer running: this
     * host's earlier runs and any host not in `$liveHosts` (only this host's when null). Rows
     * without a hostname are kept, as their host is unknown. Only rows untouched since
     * `$before` go, so a socket served meanwhile keeps its presence. Purges the list cache,
     * decrements usage and sends a realtime delete event per presence, as a socket closing
     * does (realtime only, no webhooks or functions; no user lookup, as delivery uses the payload).
     * Returns how many were deleted.
     *
     * @param list<string>|null $liveHosts
     */
    public function deleteOrphaned(
        Database $dbForProject,
        Document $project,
        string $hostname,
        ?array $liveHosts,
        string $before,
        UsagePublisher $publisherForUsage,
        QueueRealtime $queueForRealtime,
    ): int {
        // deleteDocuments() re-applies these filters in the DELETE itself, so a row a socket
        // updates after it was read is no longer older than `$before` and is kept.
        $queries = [
            Query::equal('source', ['realtime']),
            Query::lessThan('$updatedAt', $before),
        ];
        if ($liveHosts === null) {
            $queries[] = Query::equal('hostname', [$hostname]);
        } else {
            $queries[] = Query::isNotNull('hostname');
            // One notEqual per host: it takes a single value.
            foreach ($liveHosts as $liveHost) {
                $queries[] = Query::notEqual('hostname', $liveHost);
            }
        }

        $deleted = 0;
        try {
            $dbForProject->getAuthorization()->skip(function () use ($dbForProject, $queries, $project, $queueForRealtime, &$deleted): void {
                if ($dbForProject->findCollection(self::COLLECTION_ID) === null) {
                    return;
                }

                $dbForProject->deleteDocuments(
                    self::COLLECTION_ID,
                    $queries,
                    // Announced as each row is deleted, so nothing is buffered and a later failure can't silence it.
                    onNext: function (Document $presence) use ($project, $queueForRealtime, &$deleted): void {
                        $deleted++;

                        foreach (['$collection', '$tenant', 'hostname', 'permissionsHash', 'userInternalId', 'connections'] as $attribute) {
                            $presence->removeAttribute($attribute);
                        }

                        try {
                            $queueForRealtime
                                ->reset()
                                ->setProject($project)
                                ->setEvent('presences.[presenceId].delete')
                                ->setParam('presenceId', $presence->getId())
                                ->setPayload($presence->getArrayCopy())
                                ->trigger();
                        } catch (Throwable $th) {
                            Span::current()?->setError($th)->set('presence.id', $presence->getId());
                        }
                    },
                );
            });
        } finally {
            // Rows deleted before a failure are gone too, so count and purge them either way.
            if ($deleted > 0) {
                $this->triggerUsage($publisherForUsage, $project, -$deleted);

                try {
                    $this->purgeListCache($dbForProject);
                } catch (Throwable $th) {
                    Span::current()?->setError($th)->set('project.id', $project->getId());
                }
            }
        }

        return $deleted;
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

    /**
     * Drop the list cache when a presence's effective permissions changed, so a page that was
     * cached while the row was readable stops being served after it is narrowed. `$previousHash`
     * is the stored row's `permissionsHash` before the write, or null when the row was just
     * created -- a creation only adds a row, it cannot leak one to a reader who lost access, and
     * purging on every create would drop the cache on each realtime connect and heartbeat-create.
     */
    private function purgeListCacheOnPermissionChange(Database $dbForProject, ?string $previousHash, Document $presence): void
    {
        if ($previousHash !== null && $previousHash !== $presence->getAttribute('permissionsHash')) {
            $this->purgeListCache($dbForProject);
        }
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
