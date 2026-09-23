<?php

declare(strict_types=1);

namespace Appwrite\Platform\Modules\Migrations;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Helpers\ID;
use Utopia\Migration\Destinations\Appwrite\ProvisioningOwner;

final readonly class Claim
{
    /**
     * Claim work is one metadata read/write plus one queue publish. These match
     * the Functions/Sites deployment-cancellation convention: enough lease and
     * wait time for a transient datastore pause without locking actual work.
     */
    private const int LOCK_TTL = 30;
    private const float LOCK_TIMEOUT = 10.0;

    /**
     * Seconds a live attempt may go without a progress write. Must stay below
     * the queue reaper's 25 hour redelivery delay, so a dead attempt has lapsed
     * by the time its message comes back.
     */
    public const int LIVENESS_LEASE = 3_600;

    /**
     * Seconds a live attempt may spend finalizing, which writes no progress
     * while it flips database statuses, sweeps overwrite orphans or registers
     * an export. Must exceed the longest finalization a live worker runs.
     */
    public const int FINALIZING_LEASE = 86_400;

    public const string STAGE_FINALIZING = 'finalizing';
    public const string STAGE_FINISHED = 'finished';
    public const string STAGE_INIT = 'init';
    public const string STAGE_MIGRATING = 'migrating';
    public const string STAGE_PROCESSING = 'processing';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';

    private ?\Closure $locks;

    public function __construct(
        private Database $database,
        ?callable $locks = null,
    ) {
        $this->locks = $locks === null ? null : \Closure::fromCallable($locks);
    }

    /**
     * Refuse to create, retry or claim an attempt until V26 has installed every
     * ownership field: without them the attempt identifier would be silently
     * dropped. A new API starts attempts only after its project schema has
     * crossed V26, and a worker hands the delivery back to the queue, leaving
     * the migration pending, so the queue can redeliver it once V26 has run.
     */
    public function assertReady(): void
    {
        $missing = [];

        foreach ([
            'databases' => ['migrationId', 'migrationAttemptId'],
            'migrations' => ['attemptId'],
        ] as $collection => $required) {
            $this->database->purgeCachedCollection($collection);

            try {
                $attributes = \array_map(
                    static fn ($attribute): string => $attribute->getId(),
                    $this->database->getCollection($collection)->getAttribute('attributes', []),
                );
            } catch (\Throwable $error) {
                throw new Exception(
                    Exception::MIGRATION_SCHEMA_NOT_READY,
                    "Migration ownership schema is not ready: collection {$collection} is unavailable",
                    previous: $error,
                );
            }

            foreach ($required as $attribute) {
                if (!\in_array($attribute, $attributes, true)) {
                    $missing[] = "{$collection}.{$attribute}";
                }
            }
        }

        if ($missing !== []) {
            throw new Exception(
                Exception::MIGRATION_SCHEMA_NOT_READY,
                'Migration ownership schema is not ready; missing attributes: ' . \implode(', ', $missing),
            );
        }
    }

    /**
     * Publish one exact initial generation. If publishing fails, remove only
     * the still-pending generation created by this request; a worker or newer
     * claim that advanced it always wins.
     *
     * @param array<string, mixed> $platform
     */
    public function initial(
        Document $project,
        Document $migration,
        array $platform,
        MigrationPublisher $publisher,
    ): Document {
        $this->assertReady();

        $migrationId = $migration->getId();
        if ($migrationId === '') {
            throw new \LogicException('Migration identifier is missing');
        }

        $claimed = $this->guard(
            $this->key($project->getId(), $migrationId),
            function () use ($migration, $migrationId): Document {
                return $this->database->withTransaction(function () use ($migration, $migrationId): Document {
                    $live = $this->database->getDocument('migrations', $migrationId, forUpdate: true);
                    $attemptId = $migration->getAttribute('attemptId');
                    if (
                        !\is_string($attemptId)
                        || $attemptId === ''
                        || !$this->sameObservation($live, $migration)
                        || $live->getAttribute('status') !== self::STATUS_PENDING
                        || $live->getAttribute('stage') !== self::STAGE_INIT
                    ) {
                        throw new \LogicException('Initial migration generation is no longer publishable');
                    }

                    return $this->write($live, new Document([
                        'attemptId' => ID::unique(),
                    ]));
                });
            },
        );

        try {
            $published = $publisher->enqueue(new MigrationMessage(
                project: $project,
                migration: $claimed,
                platform: $platform,
            ));

            if ($published === false) {
                throw new \RuntimeException('Failed to enqueue migration');
            }
        } catch (\Throwable $error) {
            $this->withGeneration($claimed, function (Document $live) use ($migrationId): void {
                if (
                    $live->getAttribute('status') === self::STATUS_PENDING
                    && $live->getAttribute('stage') === self::STAGE_INIT
                ) {
                    $this->database->withRequestTimestamp(
                        $this->readAt($live),
                        fn (): bool => $this->database->deleteDocument('migrations', $migrationId),
                    );
                }
            });

            throw $error;
        }

        return $claimed;
    }

    /**
     * Claim the next generation of a failed migration without publishing it,
     * for a worker that runs the attempt itself: pass `message()` of the
     * result to the worker, and consume() accepts it exactly once. The
     * terminal document is a separate immutable snapshot of the failed
     * attempt; the live document becomes pending.
     */
    public function reclaim(string $projectId, string $migrationId): Retry
    {
        $this->assertReady();

        return $this->guard(
            $this->key($projectId, $migrationId),
            function () use ($migrationId): Retry {
                return $this->database->withTransaction(function () use ($migrationId): Retry {
                    $migration = $this->database->getDocument('migrations', $migrationId, forUpdate: true);

                    if ($migration->isEmpty()) {
                        throw new Exception(Exception::MIGRATION_NOT_FOUND);
                    }

                    if ($migration->getAttribute('status') !== self::STATUS_FAILED) {
                        throw new Exception(Exception::MIGRATION_IN_PROGRESS, 'Migration is not in a terminal failed state');
                    }

                    $terminal = new Document([
                        '$id' => $migration->getId(),
                        'attemptId' => $migration->getAttribute('attemptId'),
                        'status' => $migration->getAttribute('status'),
                        'stage' => $migration->getAttribute('stage'),
                    ]);

                    return new Retry(
                        migration: $this->write($migration, new Document([
                            'attemptId' => ID::unique(),
                            'status' => self::STATUS_PENDING,
                            'stage' => self::STAGE_FINISHED,
                        ])),
                        terminal: $terminal,
                    );
                });
            },
        );
    }

    /**
     * Persist a retry claim before publishing it. If publishing fails, restore
     * the failed attempt unless a newer claim already moved past this one.
     *
     * @param array<string, mixed> $platform
     */
    public function retry(
        Document $project,
        string $migrationId,
        array $platform,
        MigrationPublisher $publisher,
    ): Document {
        $retry = $this->reclaim($project->getId(), $migrationId);

        try {
            if ($publisher->enqueue($retry->message($project, $platform)) === false) {
                throw new \RuntimeException('Failed to enqueue migration');
            }
        } catch (\Throwable $error) {
            $this->withGeneration($retry->migration, function (Document $live) use ($retry): void {
                if (
                    $live->getAttribute('status') === self::STATUS_PENDING
                    && $live->getAttribute('stage') === self::STAGE_FINISHED
                ) {
                    $this->write($live, new Document([
                        'attemptId' => $retry->terminal->getAttribute('attemptId'),
                        'status' => $retry->terminal->getAttribute('status'),
                        'stage' => $retry->terminal->getAttribute('stage'),
                    ]));
                }
            });

            throw $error;
        }

        return $retry->migration;
    }

    /**
     * Claim one exact queued generation for processing. A redelivery of an
     * attempt that stopped writing for longer than its lease takes the
     * migration over with a new attempt, which fences the dead worker out.
     * Every other duplicate or stale delivery returns null after observing
     * authoritative live state.
     */
    public function consume(string $projectId, MigrationMessage $message): ?Delivery
    {
        $queued = $message->migration;
        $migrationId = $queued->getId();

        if ($migrationId === '') {
            return null;
        }

        $this->assertReady();

        try {
            return $this->guard(
                $this->key($projectId, $migrationId),
                function () use ($message, $migrationId, $queued): ?Delivery {
                    return $this->database->withTransaction(function () use ($message, $migrationId, $queued): ?Delivery {
                        $live = $this->database->getDocument('migrations', $migrationId, forUpdate: true);
                        if ($live->isEmpty()) {
                            return null;
                        }

                        if (!$this->sameObservation($live, $queued)) {
                            if (!$this->abandoned($live, $queued)) {
                                return null;
                            }

                            return new Delivery(
                                migration: $this->write($live, new Document([
                                    'attemptId' => ID::unique(),
                                    'status' => self::STATUS_PROCESSING,
                                    'stage' => self::STAGE_PROCESSING,
                                ])),
                                terminal: null,
                            );
                        }

                        $terminal = $message->terminal;
                        $queuedAttemptId = $queued->getAttribute('attemptId');
                        $liveAttemptId = $live->getAttribute('attemptId');
                        $attemptsMatch = $queuedAttemptId === $liveAttemptId
                            && ($queuedAttemptId === null || (\is_string($queuedAttemptId) && $queuedAttemptId !== ''));
                        if (!$attemptsMatch) {
                            return null;
                        }

                        $terminalAttemptId = $terminal?->getAttribute('attemptId');
                        if (
                            $terminalAttemptId !== null
                            && (!\is_string($terminalAttemptId) || $terminalAttemptId === '')
                        ) {
                            return null;
                        }

                        $initial = $terminal === null
                            && $queued->getAttribute('status') === self::STATUS_PENDING
                            && $queued->getAttribute('stage') === self::STAGE_INIT
                            && $live->getAttribute('status') === self::STATUS_PENDING
                            && $live->getAttribute('stage') === self::STAGE_INIT;
                        $retry = $terminal !== null
                            && $terminal->getId() === $migrationId
                            && $terminal->getAttribute('status') === self::STATUS_FAILED
                            && $queued->getAttribute('status') === self::STATUS_PENDING
                            && $queued->getAttribute('stage') === self::STAGE_FINISHED
                            && $live->getAttribute('status') === self::STATUS_PENDING
                            && $live->getAttribute('stage') === self::STAGE_FINISHED
                            && ($terminalAttemptId === null || $terminalAttemptId !== $liveAttemptId);
                        $legacyRetry = $terminal === null
                            && $queued->getAttribute('status') === self::STATUS_PENDING
                            && $live->getAttribute('status') === self::STATUS_FAILED;

                        if (!$initial && !$retry && !$legacyRetry) {
                            return null;
                        }

                        if ($legacyRetry) {
                            $terminal = new Document([
                                '$id' => $live->getId(),
                                'attemptId' => $liveAttemptId,
                                'status' => $live->getAttribute('status'),
                                'stage' => $live->getAttribute('stage'),
                            ]);
                        }

                        if ($liveAttemptId === null || $legacyRetry) {
                            $liveAttemptId = ID::unique();
                        }

                        $migration = $this->write($live, new Document([
                            'attemptId' => $liveAttemptId,
                            'status' => self::STATUS_PROCESSING,
                            'stage' => self::STAGE_PROCESSING,
                        ]));

                        return new Delivery($migration, $terminal);
                    });
                },
            );
        } catch (Conflict) {
            return null;
        }
    }

    /**
     * Persist worker state only while the exact attempt and update generation
     * that produced it still own the migration.
     */
    public function persist(Document $migration): ?Document
    {
        return $this->withGeneration($migration, function (Document $live) use ($migration): Document {
            $updates = [];
            foreach ($migration->getArrayCopy() as $attribute => $value) {
                if (\str_starts_with($attribute, '$') || $attribute === 'attemptId') {
                    continue;
                }

                if ($live->getAttribute($attribute) !== $value) {
                    $updates[$attribute] = $value;
                }
            }

            return $updates === []
                ? $live
                : $this->write($live, new Document($updates));
        });
    }

    /**
     * Turn one observed processing generation whose lease has lapsed into a
     * retryable terminal.
     */
    public function expire(Document $migration): ?Document
    {
        try {
            return $this->database->withTransaction(function () use ($migration): ?Document {
                $live = $this->database->getDocument('migrations', $migration->getId(), forUpdate: true);

                if (!$this->sameObservation($live, $migration) || !$this->lapsed($live)) {
                    return null;
                }

                return $this->write($live, new Document([
                    'status' => self::STATUS_FAILED,
                    'stage' => self::STAGE_FINISHED,
                ]));
            });
        } catch (Conflict) {
            return null;
        }
    }

    /**
     * Resolve the terminal migration that owns an incomplete destination
     * database. Active or unverifiable ownership always fails closed.
     */
    public function recoverable(Document $database, ?Document $terminal = null): ?ProvisioningOwner
    {
        $migrationId = $database->getAttribute('migrationId');
        $attemptId = $database->getAttribute('migrationAttemptId');
        if (
            !\is_string($migrationId)
            || $migrationId === ''
            || !\is_string($attemptId)
            || $attemptId === ''
        ) {
            return null;
        }

        $migration = $this->database->getDocument('migrations', $migrationId);
        if ($migration->isEmpty()) {
            return null;
        }

        $status = $migration->getAttribute('status');
        $stage = $migration->getAttribute('stage');
        $currentAttemptId = $migration->getAttribute('attemptId');
        $terminalAttemptId = $terminal?->getAttribute('attemptId');
        $terminalOwner = $terminal !== null
            && $terminal->getId() === $migrationId
            && \is_string($terminalAttemptId)
            && $terminalAttemptId === $attemptId
            && $terminal->getAttribute('status') === self::STATUS_FAILED
            && $terminal->getAttribute('stage') === self::STAGE_FINISHED
            && \is_string($currentAttemptId)
            && $currentAttemptId !== ''
            && $currentAttemptId !== $terminalAttemptId
            && \in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
            && \in_array($stage, [self::STAGE_FINISHED, self::STAGE_PROCESSING, self::STAGE_MIGRATING], true);

        return $terminalOwner ? new ProvisioningOwner($migrationId, $attemptId) : null;
    }

    private function abandoned(Document $live, Document $queued): bool
    {
        $attemptId = $queued->getAttribute('attemptId');

        return \is_string($attemptId)
            && $attemptId !== ''
            && $attemptId === $live->getAttribute('attemptId')
            && $live->getSequence() === $queued->getSequence()
            && $this->lapsed($live);
    }

    private function lapsed(Document $live): bool
    {
        if ($live->getAttribute('status') !== self::STATUS_PROCESSING) {
            return false;
        }

        $lease = match ($live->getAttribute('stage')) {
            self::STAGE_PROCESSING, self::STAGE_MIGRATING => self::LIVENESS_LEASE,
            self::STAGE_FINALIZING => self::FINALIZING_LEASE,
            default => null,
        };

        return $lease !== null && $this->readAt($live)->getTimestamp() < \time() - $lease;
    }

    private function key(string $projectId, string $migrationId): string
    {
        return 'migration:' . $projectId . ':' . $migrationId;
    }

    private function guard(string $key, callable $callback): mixed
    {
        if ($this->locks === null) {
            return $callback();
        }

        return ($this->locks)(
            $key,
            self::LOCK_TTL,
            $callback,
            self::LOCK_TIMEOUT,
        );
    }

    private function withGeneration(Document $migration, callable $callback): mixed
    {
        if ($migration->getUpdatedAt() === null) {
            throw new \LogicException('Migration generation cannot be compared without an update timestamp');
        }

        try {
            return $this->database->withTransaction(function () use ($callback, $migration): mixed {
                $live = $this->database->getDocument('migrations', $migration->getId(), forUpdate: true);
                if (!$this->sameObservation($live, $migration)) {
                    return null;
                }

                return $callback($live);
            });
        } catch (Conflict) {
            return null;
        }
    }

    /**
     * Every update moves `$updatedAt` strictly forward, so a write pinned to the
     * timestamp the row was read with is refused once another writer got there first.
     */
    private function write(Document $live, Document $updates): Document
    {
        return $this->required($this->database->withRequestTimestamp(
            $this->readAt($live),
            fn (): Document => $this->database->updateDocument('migrations', $live->getId(), $updates),
        ));
    }

    private function readAt(Document $document): \DateTime
    {
        $updatedAt = $document->getUpdatedAt();
        if ($updatedAt === null || $updatedAt === '') {
            throw new \LogicException('Migration document update timestamp is missing');
        }

        return new \DateTime($updatedAt);
    }

    private function required(Document $document): Document
    {
        if ($document->isEmpty()) {
            throw new Conflict('Migration document no longer exists');
        }

        return $document;
    }

    private function sameObservation(Document $live, Document $queued): bool
    {
        return !$live->isEmpty()
            && $live->getId() !== ''
            && $live->getId() === $queued->getId()
            && $live->getAttribute('attemptId') === $queued->getAttribute('attemptId')
            && $live->getUpdatedAt() !== null
            && $live->getUpdatedAt() === $queued->getUpdatedAt()
            && $live->getSequence() !== ''
            && $live->getSequence() === $queued->getSequence();
    }
}
