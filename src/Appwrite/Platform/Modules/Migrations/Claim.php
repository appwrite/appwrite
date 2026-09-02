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
use Utopia\System\System;

final readonly class Claim
{
    /**
     * Claim work is one metadata read/write plus one queue publish. These match
     * the Functions/Sites deployment-cancellation convention: enough lease and
     * wait time for a transient datastore pause without locking actual work.
     */
    private const int LOCK_TTL = 30;
    private const float LOCK_TIMEOUT = 10.0;
    private const string STAGE_FINISHED = 'finished';
    private const string STAGE_INIT = 'init';
    private const string STAGE_MIGRATING = 'migrating';
    private const string STAGE_PROCESSING = 'processing';
    private const string STATUS_FAILED = 'failed';
    private const string STATUS_PENDING = 'pending';
    private const string STATUS_PROCESSING = 'processing';

    private ?\Closure $locks;

    public function __construct(
        private Database $database,
        ?callable $locks = null,
    ) {
        $this->locks = $locks === null ? null : \Closure::fromCallable($locks);
    }

    /**
     * Refuse attempt creation until V26 has installed every ownership field.
     *
     * The check lives on the request path as a deployment safety net: a new
     * API can start only after its project schema has crossed V26.
     */
    public function assertReady(): void
    {
        // Operators enable this only after V26 schema migration and claim-aware
        // workers are fully rolled out. The default-disabled phase blocks producers.
        if (System::getEnv('_APP_MIGRATIONS_CLAIM_ENABLED', 'disabled') !== 'enabled') {
            throw new Exception(Exception::MIGRATION_CLAIM_DISABLED);
        }

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
                        || !$this->sameGeneration($live, $migration)
                        || $live->getAttribute('status') !== self::STATUS_PENDING
                        || $live->getAttribute('stage') !== self::STAGE_INIT
                    ) {
                        throw new \LogicException('Initial migration generation is no longer publishable');
                    }

                    return $this->required($this->database->updateDocument('migrations', $migrationId, new Document([
                        'attemptId' => ID::unique(),
                    ]), expectedVersion: $this->version($live)));
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
                    $this->database->deleteDocument(
                        'migrations',
                        $migrationId,
                        expectedVersion: $this->version($live),
                    );
                }
            });

            throw $error;
        }

        return $claimed;
    }

    /**
     * Persist a retry claim before publishing it. The terminal document is a
     * separate immutable queue snapshot; the live document becomes active.
     *
     * @param array<string, mixed> $platform
     */
    public function retry(
        Document $project,
        string $migrationId,
        array $platform,
        MigrationPublisher $publisher,
    ): Document {
        $this->assertReady();

        [$claimed, $terminal] = $this->guard(
            $this->key($project->getId(), $migrationId),
            function () use ($migrationId): array {
                return $this->database->withTransaction(function () use ($migrationId): array {
                    $migration = $this->database->getDocument('migrations', $migrationId, forUpdate: true);

                    if ($migration->isEmpty()) {
                        throw new Exception(Exception::MIGRATION_NOT_FOUND);
                    }

                    if (
                        $migration->getAttribute('status') !== self::STATUS_FAILED
                        || $migration->getAttribute('stage') !== self::STAGE_FINISHED
                    ) {
                        throw new Exception(Exception::MIGRATION_IN_PROGRESS, 'Migration is not in a terminal failed state');
                    }

                    $terminal = new Document([
                        '$id' => $migration->getId(),
                        'attemptId' => $migration->getAttribute('attemptId'),
                        'status' => $migration->getAttribute('status'),
                        'stage' => $migration->getAttribute('stage'),
                    ]);
                    $claimed = $this->required($this->database->updateDocument('migrations', $migrationId, new Document([
                        'attemptId' => ID::unique(),
                        'status' => self::STATUS_PENDING,
                        'stage' => self::STAGE_FINISHED,
                    ]), expectedVersion: $this->version($migration)));

                    return [$claimed, $terminal];
                });
            },
        );

        try {
            $published = $publisher->enqueue(new MigrationMessage(
                project: $project,
                migration: $claimed,
                platform: $platform,
                terminal: $terminal,
            ));

            if ($published === false) {
                throw new \RuntimeException('Failed to enqueue migration');
            }
        } catch (\Throwable $error) {
            $this->withGeneration($claimed, function (Document $live) use ($migrationId, $terminal): void {
                if (
                    $live->getAttribute('status') === self::STATUS_PENDING
                    && $live->getAttribute('stage') === self::STAGE_FINISHED
                ) {
                    $this->database->updateDocument('migrations', $migrationId, new Document([
                        'attemptId' => $terminal->getAttribute('attemptId'),
                        'status' => self::STATUS_FAILED,
                        'stage' => self::STAGE_FINISHED,
                    ]), expectedVersion: $this->version($live));
                }
            });

            throw $error;
        }

        return $claimed;
    }

    /**
     * Claim one exact queued generation for processing. Duplicate or stale
     * deliveries return null after observing authoritative live state.
     */
    public function consume(string $projectId, MigrationMessage $message): ?Delivery
    {
        $queued = $message->migration;
        $migrationId = $queued->getId();

        if ($migrationId === '') {
            return null;
        }

        try {
            return $this->guard(
                $this->key($projectId, $migrationId),
                function () use ($message, $migrationId, $queued): ?Delivery {
                    return $this->database->withTransaction(function () use ($message, $migrationId, $queued): ?Delivery {
                        $live = $this->database->getDocument('migrations', $migrationId, forUpdate: true);
                        if ($live->isEmpty() || !$this->sameGeneration($live, $queued)) {
                            // A redelivery of an already-claimed generation is stale and is
                            // acknowledged. If its worker died mid-attempt, maintenance turns
                            // the old processing row into a failed/finished retryable terminal.
                            return null;
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
                            && $terminal->getAttribute('stage') === self::STAGE_FINISHED
                            && $queued->getAttribute('status') === self::STATUS_PENDING
                            && $queued->getAttribute('stage') === self::STAGE_FINISHED
                            && $live->getAttribute('status') === self::STATUS_PENDING
                            && $live->getAttribute('stage') === self::STAGE_FINISHED
                            && ($terminalAttemptId === null || $terminalAttemptId !== $liveAttemptId);
                        $legacyRetry = $terminal === null
                            && $queued->getAttribute('status') === self::STATUS_PENDING
                            && $queued->getAttribute('stage') === self::STAGE_FINISHED
                            && $live->getAttribute('status') === self::STATUS_FAILED
                            && $live->getAttribute('stage') === self::STAGE_FINISHED;

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

                        $migration = $this->required($this->database->updateDocument('migrations', $migrationId, new Document([
                            'attemptId' => $liveAttemptId,
                            'status' => self::STATUS_PROCESSING,
                            'stage' => self::STAGE_PROCESSING,
                        ]), expectedVersion: $this->version($live)));

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
                : $this->required($this->database->updateDocument(
                    'migrations',
                    $live->getId(),
                    new Document($updates),
                    expectedVersion: $this->version($live),
                ));
        });
    }

    /**
     * Turn one exact stale processing generation into a retryable terminal.
     */
    public function expire(Document $migration): ?Document
    {
        return $this->withGeneration($migration, function (Document $live): ?Document {
            if (
                $live->getAttribute('status') !== self::STATUS_PROCESSING
                || !\in_array($live->getAttribute('stage'), [self::STAGE_PROCESSING, self::STAGE_MIGRATING], true)
            ) {
                return null;
            }

            return $this->required($this->database->updateDocument('migrations', $live->getId(), new Document([
                'status' => self::STATUS_FAILED,
                'stage' => self::STAGE_FINISHED,
            ]), expectedVersion: $this->version($live)));
        });
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
        try {
            return $this->database->withTransaction(function () use ($callback, $migration): mixed {
                $live = $this->database->getDocument('migrations', $migration->getId(), forUpdate: true);
                if (!$this->sameGeneration($live, $migration)) {
                    return null;
                }

                return $callback($live);
            });
        } catch (Conflict) {
            return null;
        }
    }

    private function version(Document $document): int
    {
        $version = $document->getVersion();
        if ($version === null) {
            throw new \LogicException('Migration document version is missing');
        }

        return $version;
    }

    private function required(Document $document): Document
    {
        if ($document->isEmpty()) {
            throw new Conflict('Migration document no longer exists');
        }

        return $document;
    }

    private function sameGeneration(Document $live, Document $queued): bool
    {
        return !$live->isEmpty()
            && $live->getId() !== ''
            && $live->getId() === $queued->getId()
            && $live->getAttribute('attemptId') === $queued->getAttribute('attemptId')
            && $live->getUpdatedAt() !== null
            && $live->getUpdatedAt() === $queued->getUpdatedAt();
    }
}
