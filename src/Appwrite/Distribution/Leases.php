<?php

namespace Appwrite\Distribution;

use Appwrite\Distribution\Lease\Status;
use Appwrite\Distribution\Lease\Token;
use Appwrite\Distribution\Runner\Platform;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Exception\ClientException;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\Query;
use Utopia\System\System;

/**
 * The queue of iOS builds waiting for a macOS runner. A lease is one attempt of
 * a build: queued at dispatch, leased by the runner that claims it, and released
 * when the build ends. Every state change of a claimed lease is a compare-and-set
 * on its `$updatedAt`, so two runners never hold the same lease.
 */
readonly class Leases
{
    public const int TTL = 300;

    public const int SCAN = 25;

    public const int TRIES = 5;

    private const string XCODE = 'xcode-';

    private const string EVENT = 'orchestrator.job.';

    private const string STAGE = 'stage';

    private const array EVENTS = ['start', 'log', 'artifact', 'exit', 'complete', self::STAGE];

    private const int ID = 36;

    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * Empty without `_APP_OPENSSL_KEY_V1`, which makes every lease token fail to verify.
     */
    public static function secret(): string
    {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        return $key === '' ? '' : \hash_hmac('sha256', 'distribution:lease', $key);
    }

    public static function id(Document $project, Document $build, int $attempt): string
    {
        return \substr(\hash('sha256', "{$project->getSequence()}:{$build->getId()}:lease:{$attempt}"), 0, self::ID);
    }

    public static function artifact(string $leaseId): string
    {
        return \substr(\hash('sha256', "{$leaseId}:artifact"), 0, self::ID);
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return \hash('sha256', $token);
    }

    /**
     * @return list<string>
     */
    public static function labels(Document $target): array
    {
        $configuration = $target->getAttribute('configuration');
        $xcode = \is_array($configuration) ? ($configuration['xcode'] ?? null) : null;

        return \is_string($xcode) && $xcode !== '' ? [self::XCODE . $xcode] : [];
    }

    /**
     * @param array<mixed> $labels
     * @param array<mixed> $available
     */
    public static function isSubset(array $labels, array $available): bool
    {
        return \array_diff($labels, $available) === [];
    }

    public static function isExpired(Document $lease, int $now): bool
    {
        return $lease->getAttribute('status') === Status::Leased->value
            && self::timestamp($lease->getAttribute('expiresAt')) <= $now;
    }

    public static function isStale(Document $lease, int $now): bool
    {
        return $lease->getAttribute('status') === Status::Queued->value
            && self::timestamp($lease->getAttribute('queuedAt')) + Runners::SEEN <= $now;
    }

    /**
     * Whether the lease is held for its build's current attempt while the build is active.
     */
    public static function isCurrent(Document $lease, Document $build): bool
    {
        return $lease->getAttribute('status') === Status::Leased->value
            && (string) $lease->getAttribute('runnerId', '') !== ''
            && !$build->isEmpty()
            && $build->getAttribute('jobId') === $lease->getId()
            && (int) $build->getAttribute('attempt', 1) === (int) $lease->getAttribute('attempt', 1)
            && \in_array($build->getAttribute('status'), Builds::ACTIVE, true);
    }

    public static function timestamp(mixed $value): int
    {
        if (!\is_string($value) || $value === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * The meta of a build job's events, so the worker handles a lease's events as the build's.
     *
     * @return array<string, string>
     */
    public static function meta(Document $lease): array
    {
        return [
            'projectId' => (string) $lease->getAttribute('projectId', ''),
            'buildId' => (string) $lease->getAttribute('buildId', ''),
            'stage' => Builds::STAGE_BUILD,
            'attempt' => (string) $lease->getAttribute('attempt', 1),
        ];
    }

    /**
     * The build error of a runner's exit reason, or null when the build should be queued again.
     * `canceled` queues it again: a build its user canceled is already terminal and drops the
     * exit, so a canceled exit only comes from a lease the build no longer holds.
     */
    public static function error(?string $reason, int $exitCode): ?string
    {
        return match ($reason) {
            'timeout' => Builds::ERROR_BUILD_TIMEOUT,
            'build' => $exitCode === 2 ? Builds::ERROR_BUILD_INVALID : Builds::ERROR_BUILD_FAILED,
            'source' => Builds::ERROR_BUILD_SOURCE_FAILED,
            'output', 'verification' => Builds::ERROR_BUILD_OUTPUT_INVALID,
            'lease' => Builds::ERROR_BUILD_INVALID,
            default => null,
        };
    }

    /**
     * A runner event as the build job event the worker handles, with the lease as
     * its job and the lease's meta, or null for a `stage` event, which is dropped.
     *
     * @param array<mixed> $event
     * @return array{event: CallbackEvent, id: string, data: array<string, mixed>}|null
     * @throws \InvalidArgumentException When the event is not one of the lease's or is malformed
     */
    public static function event(Document $lease, array $event): ?array
    {
        $id = $event['id'] ?? null;
        $prefix = $lease->getId() . '-';
        if (!\is_string($id) || !\str_starts_with($id, $prefix) || !\ctype_digit(\substr($id, \strlen($prefix)))) {
            throw new \InvalidArgumentException('The event id must be the lease id, a hyphen and digits.');
        }

        $type = $event['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, self::EVENTS, true)) {
            throw new \InvalidArgumentException('The event type must be one of: ' . \implode(', ', self::EVENTS) . '.');
        }

        if ($type === self::STAGE) {
            return null;
        }

        $data = $event['data'] ?? [];
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The event data must be an object.');
        }

        unset($data['jobId'], $data['meta']);
        if (\array_key_exists('error', $data) && $data['error'] === null) {
            unset($data['error']);
        }

        $data = [...$data, 'jobId' => $lease->getId(), 'meta' => self::meta($lease)];
        $callback = CallbackEvent::from(self::EVENT . $type);

        try {
            $callback->decode($data);
        } catch (ClientException $error) {
            throw new \InvalidArgumentException($error->getMessage(), previous: $error);
        }

        return ['event' => $callback, 'id' => $id, 'data' => $data];
    }

    /**
     * Queues the build's current attempt, or returns the lease already queued for it.
     *
     * @param list<string> $labels
     */
    public function queue(Document $project, Document $build, array $labels, string $endpoint): Document
    {
        $attempt = (int) $build->getAttribute('attempt', 1);
        $id = self::id($project, $build, $attempt);

        $lease = $this->dbForPlatform->getDocument('distributionLeases', $id);
        if (!$lease->isEmpty()) {
            return $lease;
        }

        try {
            return $this->dbForPlatform->createDocument('distributionLeases', new Document([
                '$id' => $id,
                'projectId' => $project->getId(),
                'projectInternalId' => (string) $project->getSequence(),
                'buildId' => $build->getId(),
                'platform' => (string) $build->getAttribute('platform', ''),
                'runnerPlatform' => Platform::Macos->value,
                'labels' => Runners::normalize($labels),
                'region' => (string) $project->getAttribute('region', System::getEnv('_APP_REGION', 'default')),
                'endpoint' => $endpoint,
                'status' => Status::Queued->value,
                'attempt' => $attempt,
                'queuedAt' => DateTime::now(),
            ]));
        } catch (Duplicate) {
            return $this->dbForPlatform->getDocument('distributionLeases', $id);
        }
    }

    public function current(Document $project, Document $build): Document
    {
        $jobId = (string) $build->getAttribute('jobId', '');
        if ($jobId === '') {
            return new Document();
        }

        return $this->dbForPlatform->findOne('distributionLeases', [
            Query::equal('$id', [$jobId]),
            Query::equal('projectInternalId', [(string) $project->getSequence()]),
        ]);
    }

    /**
     * Leases the oldest queued lease the runner has the labels for, unless the
     * runner is at capacity. A lease another runner claimed first is passed over,
     * for at most $tries compare-and-sets.
     *
     * @param list<string> $labels
     * @return array{lease: Document, token: string, tries: int}|null
     * @throws \LogicException When `_APP_OPENSSL_KEY_V1` is empty
     */
    public function claim(Document $runner, array $labels, int $tries): ?array
    {
        $runnerInternalId = (string) $runner->getSequence();

        $held = $this->dbForPlatform->count('distributionLeases', [
            Query::equal('status', [Status::Leased->value]),
            Query::equal('runnerInternalId', [$runnerInternalId]),
            Query::greaterThan('expiresAt', DateTime::now()),
        ]);
        if ($held >= (int) $runner->getAttribute('capacity', 1)) {
            return null;
        }

        $queued = $this->dbForPlatform->find('distributionLeases', [
            Query::equal('status', [Status::Queued->value]),
            Query::equal('runnerPlatform', [(string) $runner->getAttribute('platform', '')]),
            Query::orderAsc('queuedAt'),
            Query::limit(self::SCAN),
        ]);

        $used = 0;
        foreach ($queued as $candidate) {
            if ($used >= $tries) {
                break;
            }

            if (!self::isSubset($candidate->getAttribute('labels', []), $labels)) {
                continue;
            }

            $used++;
            $token = Token::issue($candidate->getId(), $runner->getId(), (string) $candidate->getAttribute('projectInternalId', ''), self::secret());
            $lease = $this->compareAndSet($candidate, new Document([
                'status' => Status::Leased->value,
                'runnerId' => $runner->getId(),
                'runnerInternalId' => $runnerInternalId,
                'expiresAt' => DateTime::addSeconds(new \DateTime(), self::TTL),
                'token' => self::hash($token),
            ]));

            if ($lease !== null) {
                return ['lease' => $lease, 'token' => $token, 'tries' => $used];
            }
        }

        return null;
    }

    /**
     * The lease a verified token names, as long as the token is the one its claim issued.
     *
     * @throws Exception When the lease is missing, or the token is not its current one
     */
    public function held(Token $verified, #[\SensitiveParameter] string $token): Document
    {
        $lease = $this->dbForPlatform->getDocument('distributionLeases', $verified->leaseId);
        if ($lease->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND);
        }

        if ((string) $lease->getAttribute('runnerId', '') !== $verified->runnerId
            || (string) $lease->getAttribute('projectInternalId', '') !== $verified->projectInternalId
            || !\hash_equals((string) $lease->getAttribute('token', ''), self::hash($token))) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        return $lease;
    }

    /**
     * Extends the lease of the build's current active attempt to TTL from now. A lease that
     * changed since it was read is read again, so a concurrent extension of the same lease
     * answers with its expiry instead of stopping the runner.
     *
     * @return Document|null The extended lease, or null when the runner should stop because
     *                       the lease was released or is no longer the build's current attempt
     * @throws Exception When the lease expired, so the runner reports it lost and the build is queued again
     */
    public function renew(Document $lease, Document $build): ?Document
    {
        if (self::isCurrent($lease, $build) && !self::isExpired($lease, \time())) {
            $lease = $this->extend($lease);
        }

        if (!self::isCurrent($lease, $build)) {
            return null;
        }

        if (self::isExpired($lease, \time())) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND, 'The lease expired.');
        }

        return $lease;
    }

    /**
     * Ends a live lease now, so reconcile queues the build again.
     *
     * @return bool Whether the lease was live and this call ended it
     */
    public function expire(Document $lease): bool
    {
        if ($lease->getAttribute('status') !== Status::Leased->value || self::isExpired($lease, \time())) {
            return false;
        }

        return $this->compareAndSet($lease, new Document([
            'expiresAt' => DateTime::now(),
        ])) !== null;
    }

    public function release(Document $lease): void
    {
        try {
            $this->dbForPlatform->updateDocument('distributionLeases', $lease->getId(), new Document([
                'status' => Status::Released->value,
            ]));
        } finally {
            $this->dbForPlatform->purgeCachedDocument('distributionLeases', $lease->getId());
        }
    }

    private function extend(Document $lease): Document
    {
        return $this->compareAndSet($lease, new Document([
            'expiresAt' => DateTime::addSeconds(new \DateTime(), self::TTL),
        ])) ?? $this->dbForPlatform->getDocument('distributionLeases', $lease->getId());
    }

    /**
     * Writes $changes unless the lease changed after $lease was read.
     */
    private function compareAndSet(Document $lease, Document $changes): ?Document
    {
        try {
            return $this->dbForPlatform->withRequestTimestamp(
                new \DateTime((string) $lease->getUpdatedAt()),
                fn (): Document => $this->dbForPlatform->updateDocument('distributionLeases', $lease->getId(), $changes),
            );
        } catch (Conflict|NotFound) {
            return null;
        } finally {
            $this->dbForPlatform->purgeCachedDocument('distributionLeases', $lease->getId());
        }
    }
}
