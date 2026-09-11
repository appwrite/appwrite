<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Utopia\Lock\Mutex;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\Consumer as NatsConsumer;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\DiscardPolicy;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\RetentionPolicy;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * NATS JetStream broker.
 *
 * Each queue is a WorkQueue-retention stream (a message is removed once acked)
 * with two subjects — normal and priority — served by two durable pull consumers.
 * Redelivery and dead-lettering are native: a rejected message is NAK'd and
 * redelivered until MaxDeliver, after which it is TERM'd and copied to a per-queue
 * dead stream. This replaces the Redis broker's hand-rolled processing/failed/dead
 * lists and its reap()/retry() sweeps (AckWait redelivery reclaims stranded jobs).
 *
 * Stream/subject names carry the queue name but NOT its namespace (isolation is a
 * per-account/cluster concern), so run one queue namespace per NATS account. Two
 * queues that map to the same stream — a duplicate name across namespaces, or names
 * that sanitize alike — are rejected loudly by ensure() rather than silently shared.
 *
 * One NATS connection is one socket behind one shared read pump, and driving it from
 * two coroutines at once does not degrade — Swoole ends the worker on the first
 * overlap:
 *
 *     Swoole\Error: Socket#5 has already been bound to another coroutine#2,
 *     reading of the same socket in coroutine#3 at the same time is not allowed
 *
 * So the broker is wired the way Broker\Redis is: a connection dedicated to the
 * blocking receive, and a second, lock-guarded connection carrying the commands.
 *
 *   receive connection  — fetch, provisioning, the dead-letter advisory, publishing.
 *     Driven by the consume loop, behind {@see self::synchronize()}. The fetch parks
 *     here for the whole receive timeout, and nothing on the hot path waits for it.
 *   commands connection — commit, reject, extend, getQueueSize. Driven by the handler
 *     and telemetry coroutines, behind {@see self::command()}. A JetStream ack is just
 *     a message published to the delivery's reply subject, so it does not have to
 *     leave on the connection that fetched it; rebinding it (see onCommands()) is what
 *     moves the per-message ack path off the receive socket entirely.
 *
 * The two locks are never nested, so they cannot deadlock, and each connection has
 * exactly one lock — a broker built from a live Connection serves both roles from one
 * socket and therefore shares one lock, which is the whole reason $commandsLock is
 * resolved from the source's shape rather than always constructed.
 *
 * Together this is what lets a NATS worker run job('…', N) above one, so the broker no
 * longer carries Consumer\Exclusive. Publishing stays on the receive connection: a
 * publisher-only broker has no consume loop to contend with, and a consumer does not
 * publish. Its commands connection is opened lazily on the first ack, so a publisher
 * never pays for a socket it will not use.
 */
class Nats implements Synchronous, Consumer
{
    // Wire-level identifiers (stream/subject naming, durable consumers, advisories).
    private const string STREAM_PREFIX = 'Q_';
    private const string DEAD_STREAM_SUFFIX = '_DEAD';
    private const string SUBJECT_PREFIX = 'q';
    private const string SUBJECT_NORMAL = 'normal';
    private const string SUBJECT_PRIORITY = 'priority';
    private const string SUBJECT_DEAD = 'dead';
    private const string CONSUMER_NORMAL = 'worker';
    private const string CONSUMER_PRIORITY = 'worker_priority';
    private const string CONSUMER_RETRY = 'retry';
    private const string ADVISORY_MAX_DELIVERIES = '$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES';

    // Queue group for the advisory subscription, so one worker per queue acts
    // on an exhausted message rather than every worker acting on it at once.
    private const string ADVISORY_GROUP = 'utopia_queue_dead_letter';

    // JetStream's stream-name byte limit, and the stream-metadata key that records
    // which queue identity owns a stream (the cross-instance collision guard).
    private const int MAX_STREAM_NAME = 255;
    private const string METADATA_IDENTITY = 'utopia_queue_identity';

    // Retry budget for first-time provisioning of a replicated stream. The window
    // doubles per attempt because a fixed one re-synchronises the losers: every process
    // that lost the first race waits the same interval and collides again. See ensure().
    private const int PROVISION_ATTEMPTS = 5;
    private const int PROVISION_BACKOFF_US = 500_000;

    /**
     * Wait forever to get onto the connection. A command must never be dropped
     * because the socket is momentarily busy — matching Connection\Locking.
     */
    private const float ACQUIRE_TIMEOUT = -1;

    /** @var array<string, bool> queues whose streams/consumers have been provisioned */
    private array $provisioned = [];

    /** @var array<string, array{normal: NatsConsumer, priority: NatsConsumer}> */
    private array $consumers = [];

    /** @var array<string, JetStreamMessage> in-flight messages keyed by pid, for commit/reject */
    private array $inFlight = [];

    /** @var array<string, \Utopia\NATS\Subscription> max-deliveries advisory subscription per queue */
    private array $advisories = [];

    /** Publishes the stream recognised as duplicates of an id it already held. */
    private int $duplicates = 0;

    private ?NatsConnection $connection = null;
    private ?JetStream $js = null;

    // The commands connection: every per-message acknowledgment (commit/reject/extend)
    // rides this socket instead of the one the consume loop is parked in. This is the
    // shape Broker\Redis is wired in -- a blocking receive connection plus a
    // lock-guarded commands connection -- and it is what keeps an ack from waiting on
    // a fetch. See commandsConnection().
    private ?NatsConnection $commandsConnection = null;
    private ?JetStream $commandsJs = null;

    /**
     * Guards the receive connection: fetch, provisioning and publishing.
     *
     * Deliberately a Mutex and not the Lock interface. The invariant is "one coroutine
     * at a time on this process's socket", which is inherently in-process -- a
     * distributed lock would guard nothing -- and the interface's acquire timeout is
     * not honoured consistently across its implementations: Mutex reads a negative
     * timeout as "wait forever" as the interface documents, while File and Distributed
     * treat any non-positive timeout as one immediate attempt. Accepting either would
     * turn contention into a Contention exception instead of serialising the socket,
     * which is the inverse of the guarantee, so the type rules them out.
     */
    private readonly Mutex $lock;

    /**
     * Guards the commands connection.
     *
     * A broker built from a live Connection has one socket serving both roles, so it
     * shares the receive lock: two locks over one socket would let two coroutines onto
     * it, which is the crash this whole design exists to stop. Resolved once, at
     * construction, because the source's shape is known there.
     */
    private readonly Mutex $commandsLock;

    /** @var list<\Throwable> failures owed to $onError, handed over off the lock */
    private array $deferred = [];

    /** @var array<string, array<string, NatsConsumer>> commands-connection consumer handles, [stream][durable] */
    private array $commandsConsumers = [];

    /**
     * A NATS Connection is one socket behind one shared read pump, so it cannot be
     * driven by two coroutines at once. This broker serialises its own use of it
     * behind $lock, which is what makes concurrent handlers (`job('…', N)`) safe on a
     * single connection — the lock covers this broker only, so the connection still
     * must not be shared with anything outside it. Pass a Closure factory rather than
     * a live Connection when the consumer forks or reconnects per worker, so each
     * worker resolves its own.
     *
     * commit()/reject() correlate the JetStream acknowledgment to a message through an
     * in-instance map keyed by pid, so a message must be committed/rejected on the SAME
     * instance that received it: use one consumer instance (Broker\Pool is for the
     * publisher side).
     *
     * @param NatsConnection|(\Closure(): NatsConnection) $source
     * @param list<float>|null $backoff Redelivery delays in seconds, one per attempt (the
     *        last entry repeats). JetStream couples this to the other knobs: the first
     *        entry must equal $ackWait and $maxDeliver must exceed the entry count —
     *        both are validated here so a bad combination fails at construction, not
     *        as a server error inside ensure() on first use.
     * @param StorageType $storage Backing store for the work and dead streams. Memory
     *        trades durability for latency: messages are lost on server restart (a
     *        replicated memory stream survives single-node loss, not quorum loss).
     * @param float|null $deadMaxAge Dead-letter TTL in seconds: how long a
     *        dead-lettered message stays inspectable/retryable before JetStream
     *        discards it. Null keeps dead messages forever.
     * @param float $duplicateWindow How far back the work stream remembers
     *        message ids, in seconds. A retry is only deduplicated if it lands
     *        inside this window, so it has to cover the whole span over which a
     *        caller might retry an ambiguous publish — not merely the request
     *        timeout. Memory on the server scales with ids retained, which is
     *        why it is a window and not forever.
     * @param (\Closure(array<string, mixed>): string)|null $messageId Derives a
     *        message's deduplication id from its payload. Omitted, each publish
     *        gets a fresh random id, which collapses a republish of the same
     *        envelope but not a caller retrying enqueue(). Supply this to make
     *        the caller's own retries idempotent — and make it a function of
     *        what identifies the work, never of the clock.
     * @param (\Closure(\Throwable): void)|null $onError Where the broker reports
     *        a failure it cannot raise, because it happens outside any caller's
     *        call — currently the dead-lettering of a message that exhausted
     *        maxDeliver while no handler held it. Omitted, those failures go
     *        nowhere, which is how a lost dead letter becomes invisible. Called on
     *        the way out of receive(), after the broker has released its locks, so a
     *        reporter is free to use this broker.
     * @param float|null $maxAge Message TTL on the work stream, in seconds. Null
     *        derives it from the queue's own jobTtl, which is the historical
     *        behaviour — but a queue object is built separately on each side, so
     *        producer and consumer only agree by convention. Set this to state the
     *        TTL where both sides read the same value, and the derivation is bypassed.
     * @param int|null $maxMsgSize Largest accepted message, in bytes. Applied to the
     *        work AND dead streams: a message the work stream accepts must be storable
     *        as a dead letter too, or dead-lettering fails on exactly the messages an
     *        operator is trying to read. The server's own max_payload (1MB by default)
     *        is a separate, lower ceiling that is cluster configuration.
     * @param int $maxMsgs Most messages the work stream holds; -1 is unlimited.
     * @param int $maxBytes Most bytes the work stream holds; -1 is unlimited. This is
     *        how one queue is stopped from consuming a shared account's whole file store.
     * @param DiscardPolicy $discard What a full work stream does: Old drops the oldest
     *        stored message, New refuses the publish. New is backpressure — the producer
     *        finds out — and it requires one of $maxMsgs/$maxBytes to mean anything.
     * @param int|null $maxAckPending In-flight ceiling per worker consumer: how many
     *        messages JetStream will hand out before it waits for an ack. The server
     *        default (1000) lets messages sit with the ack clock running while nothing
     *        is working them, which surfaces as redelivery of jobs that never started —
     *        set it near the worker's concurrency.
     * @param int|null $maxWaiting Pull requests a consumer may have parked at once.
     * @param float|null $inactiveThreshold Idle time after which JetStream deletes a
     *        durable consumer, in seconds. Null keeps it forever, which is what a
     *        scale-to-zero fleet wants: a threshold shorter than a quiet period returns
     *        the queue to cold provisioning on the next message.
     * @param Provisioning $provisioning Whether this broker may create and rewrite the
     *        queue's streams and worker consumers, or must use what is already there
     *        and refuse otherwise. See {@see Provisioning}.
     */
    public function __construct(
        private readonly NatsConnection|\Closure $source,
        private readonly float $ackWait = 30.0,
        private readonly int $maxDeliver = 5,
        private readonly int $replicas = 1,
        private readonly ?array $backoff = null,
        private readonly StorageType $storage = StorageType::File,
        private readonly ?float $deadMaxAge = null,
        private readonly float $duplicateWindow = 120.0,
        private readonly ?\Closure $messageId = null,
        private readonly ?\Closure $onError = null,
        private readonly ?float $maxAge = null,
        private readonly ?int $maxMsgSize = null,
        private readonly int $maxMsgs = -1,
        private readonly int $maxBytes = -1,
        private readonly DiscardPolicy $discard = DiscardPolicy::Old,
        private readonly ?int $maxAckPending = null,
        private readonly ?int $maxWaiting = null,
        private readonly ?float $inactiveThreshold = null,
        private readonly Provisioning $provisioning = Provisioning::Ensure,
    ) {
        $this->lock = new Mutex();

        // One socket serving both roles means one lock; see $commandsLock.
        $this->commandsLock = $this->source instanceof \Closure ? new Mutex() : $this->lock;

        if ($this->backoff !== null) {
            if ($this->backoff === [] || min($this->backoff) <= 0) {
                throw new \InvalidArgumentException('backoff must be a non-empty list of positive delays (seconds)');
            }
            if ($this->backoff[0] !== $this->ackWait) {
                throw new \InvalidArgumentException(\sprintf('JetStream requires the first backoff entry to equal ackWait: got backoff[0]=%s, ackWait=%s', $this->backoff[0], $this->ackWait));
            }
            if ($this->maxDeliver <= \count($this->backoff)) {
                throw new \InvalidArgumentException(\sprintf('JetStream requires maxDeliver (%d) to exceed the number of backoff entries (%d)', $this->maxDeliver, \count($this->backoff)));
            }
        }
        if ($this->deadMaxAge !== null && $this->deadMaxAge <= 0) {
            throw new \InvalidArgumentException('deadMaxAge must be a positive number of seconds, or null to keep dead messages forever');
        }
        if ($this->duplicateWindow <= 0) {
            throw new \InvalidArgumentException('duplicateWindow must be a positive number of seconds');
        }
        if ($this->maxAge !== null && $this->maxAge <= 0) {
            throw new \InvalidArgumentException('maxAge must be a positive number of seconds, or null to derive it from the queue\'s jobTtl');
        }
        if ($this->maxMsgSize !== null && $this->maxMsgSize <= 0) {
            throw new \InvalidArgumentException('maxMsgSize must be a positive number of bytes, or null for no limit');
        }
        foreach (['maxMsgs' => $this->maxMsgs, 'maxBytes' => $this->maxBytes] as $name => $limit) {
            // JetStream reads -1 as unlimited and rejects 0 outright; anything below
            // -1 is a typo that would otherwise reach the server as one of those two.
            if ($limit !== -1 && $limit <= 0) {
                throw new \InvalidArgumentException(\sprintf('%s must be a positive limit, or -1 for unlimited', $name));
            }
        }
        if ($this->discard === DiscardPolicy::New && $this->maxMsgs === -1 && $this->maxBytes === -1) {
            // Discard policy only takes effect on a stream that can be full, so this
            // combination reads as backpressure and delivers none.
            throw new \InvalidArgumentException('discard: New requires a maxMsgs or maxBytes limit — on an unlimited stream it never applies');
        }
        if ($this->maxAckPending !== null && $this->maxAckPending < 1) {
            throw new \InvalidArgumentException('maxAckPending must be at least 1, or null for the server default');
        }
        if ($this->maxWaiting !== null && $this->maxWaiting < 1) {
            throw new \InvalidArgumentException('maxWaiting must be at least 1, or null for the server default');
        }
        if ($this->inactiveThreshold !== null && $this->inactiveThreshold <= 0) {
            throw new \InvalidArgumentException('inactiveThreshold must be a positive number of seconds, or null to keep durable consumers forever');
        }
    }

    /**
     * Run one operation while holding the connection lock, so only ever one
     * coroutine is on the socket.
     *
     * This is the receive connection's lock — fetch, provisioning and publishing.
     * Acknowledgments take {@see self::command()} instead, on their own connection.
     *
     * Not reentrant: the lock is a channel of one, so a synchronised method that
     * called another would park forever waiting for itself. Every public entry point
     * acquires one of the two locks, and the private helpers they reach never do.
     *
     * @template T
     * @param callable(): T $command
     * @return T
     */
    private function synchronize(callable $command): mixed
    {
        return $this->lock->withLock($command, self::ACQUIRE_TIMEOUT);
    }

    /**
     * Run one operation on the commands connection, holding its own lock.
     *
     * Separate from {@see self::synchronize()} on purpose: the receive lock is held
     * across a fetch that can park for the whole receive timeout, and an ack must not
     * wait for it. The two locks are never nested -- no method holding one acquires the
     * other -- so they cannot deadlock.
     *
     * @template T
     * @param callable(): T $command
     * @return T
     */
    private function command(callable $command): mixed
    {
        return $this->commandsLock->withLock($command, self::ACQUIRE_TIMEOUT);
    }

    private function connection(): NatsConnection
    {
        return $this->connection ??= $this->source instanceof \Closure ? ($this->source)() : $this->source;
    }

    private function js(): JetStream
    {
        return $this->js ??= $this->connection()->jetStream();
    }

    /**
     * The connection every acknowledgment goes out on.
     *
     * A NATS ack is a message published to the delivery's reply subject, so nothing
     * ties it to the connection that fetched the message -- which is what lets the
     * whole per-message ack path move off the receive socket. Requires the Closure
     * factory to open a second connection; a broker built from a live Connection
     * (publisher-only use, where no consume loop competes) falls back to the single
     * connection, and shares its lock accordingly.
     */
    private function commandsConnection(): NatsConnection
    {
        if ($this->commandsConnection instanceof NatsConnection) {
            return $this->commandsConnection;
        }

        return $this->commandsConnection = $this->source instanceof \Closure
            ? ($this->source)()
            : $this->connection();
    }

    private function commandsJs(): JetStream
    {
        return $this->commandsJs ??= $this->commandsConnection()->jetStream();
    }

    /**
     * The same delivery, bound to the commands connection so its ack leaves on that
     * socket. Cheap -- the envelope is reused, only the connection differs.
     */
    private function onCommands(JetStreamMessage $jsMessage): JetStreamMessage
    {
        return new JetStreamMessage($this->commandsConnection(), $jsMessage->message);
    }

    private function commandsConsumer(string $stream, string $durable): NatsConsumer
    {
        return $this->commandsConsumers[$stream][$durable] ??= $this->commandsJs()->getConsumer($stream, $durable);
    }

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        // Enveloped before the lock is taken. envelope() runs the caller's $messageId
        // closure, and the lock is a channel of one acquired with no timeout, so a
        // closure that reached back into the broker would wait for a lock its own call
        // is holding and hang the worker for good. Nothing here needs the socket.
        $subject = $priority ? $this->prioritySubject($queue) : $this->workSubject($queue);
        $envelope = $this->envelope($queue, $payload);

        return $this->synchronize(function () use ($queue, $subject, $envelope): bool {
            $this->ensure($queue);
            $this->publishEnvelope($subject, $envelope);

            return true;
        });
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        if ($payloads === []) {
            return true;
        }

        // Enveloped before the lock, for the reason publish() gives.
        $subject = $priority ? $this->prioritySubject($queue) : $this->workSubject($queue);

        $messages = [];
        foreach ($payloads as $payload) {
            $envelope = $this->envelope($queue, $payload);
            /** @var string $id */
            $id = $envelope['pid'];

            $messages[] = [
                'subject' => $subject,
                'data' => (string) json_encode($envelope),
                'msgId' => $id,
            ];
        }

        return $this->synchronize(function () use ($queue, $messages): bool {
            $this->ensure($queue);

            // One round trip per window rather than one per payload: the whole batch is
            // written before any acknowledgment is read. Each payload still carries its
            // own message id, so deduplication works exactly as it does on the single
            // enqueue, and a payload the server rejects still throws.
            foreach ($this->js()->publishMany($messages) as $ack) {
                // Not discarded, for the same reason publishEnvelope() counts it: a
                // duplicate acknowledgment is the only signal that deduplication did
                // anything, and a quiet success reads the same as nothing collapsing.
                if ($ack->duplicate) {
                    ++$this->duplicates;
                }
            }

            return true;
        });
    }

    /**
     * Publish one envelope under its own id, so the stream can recognise it.
     *
     * The id goes out as Nats-Msg-Id, which is what makes a republish inside
     * the stream's duplicate window store one message instead of two. Without
     * it a publish that timed out ambiguously — the default request timeout is
     * 5s, and the server may well have stored the message before the client
     * gave up on the ack — can only be retried by creating a second copy that
     * nothing downstream can tell from a genuine second message.
     *
     * @param array<string, mixed> $envelope
     */
    private function publishEnvelope(string $subject, array $envelope): void
    {
        /** @var string $id */
        $id = $envelope['pid'];

        $ack = $this->js()->publish(
            $subject,
            (string) json_encode($envelope),
            msgId: $id,
        );

        // Not discarded: a duplicate ack means the stream already held this id,
        // so the retry collapsed instead of double-delivering. That is the
        // deduplication working, and the only signal that it is — worth
        // counting rather than throwing away, because the alternative reading
        // of a quiet success is that nothing was deduplicated at all.
        if ($ack->duplicate) {
            ++$this->duplicates;
        }
    }

    /**
     * Owe a failure to the caller's reporter, if it gave one.
     *
     * Buffered rather than called here. The only caller is drainDeadLetters(), which
     * runs under the receive lock, and the reporter is the caller's code: one that
     * publishes a notification -- onto this very broker, plausibly -- would wait on a
     * lock its own call stack is holding. {@see self::flushReports()} hands these over
     * once receive() has let go.
     */
    private function report(\Throwable $error): void
    {
        if (!$this->onError instanceof \Closure) {
            return;
        }

        $this->deferred[] = $error;
    }

    /**
     * Hand over everything report() owes, off the lock.
     *
     * Never throws: a reporting hook that fails must not escalate into the failure it
     * was called to describe.
     */
    private function flushReports(): void
    {
        if ($this->deferred === [] || !$this->onError instanceof \Closure) {
            $this->deferred = [];

            return;
        }

        // Taken and cleared first, so a reporter that re-enters receive() cannot see
        // the same failure twice.
        $owed = $this->deferred;
        $this->deferred = [];

        foreach ($owed as $error) {
            try {
                ($this->onError)($error);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Publishes the stream collapsed as duplicates of an id it already held.
     *
     * Zero on a queue whose callers never retry. A number that climbs tracks
     * retries being absorbed; it climbing on a caller that does not retry means
     * ids are colliding, which is the failure mode of a messageId function that
     * is not as unique as its author believed.
     */
    public function duplicates(): int
    {
        return $this->duplicates;
    }

    /**
     * Match the Redis broker's message shape so Message round-trips identically.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function envelope(Queue $queue, array $payload): array
    {
        return [
            'pid' => $this->messageId($payload),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];
    }

    /**
     * The message's identity: its pid, and its deduplication key on the wire.
     *
     * A random id per call dedupes a republish of the same envelope but not a
     * caller that retries enqueue() itself, because that mints a fresh one. A
     * caller who can name its work — an event id, a billing period, a document
     * id — supplies $messageId and gets its own retries deduplicated too.
     *
     * @param array<string, mixed> $payload
     */
    private function messageId(array $payload): string
    {
        if (!$this->messageId instanceof \Closure) {
            return uniqid('', true);
        }

        $id = ($this->messageId)($payload);

        // This value becomes a header, and Headers does not police what it is
        // given: a CRLF in it would end the header block early and inject
        // whatever follows into the frame. Rejecting it here keeps a
        // caller-supplied key from being able to forge protocol.
        if ($id === '' || preg_match('/[\r\n\x00]/', $id) === 1) {
            throw new \InvalidArgumentException('messageId must return a non-empty string free of CR, LF and NUL');
        }

        return $id;
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        try {
            return $this->synchronize(fn(): ?Message => $this->pull($queue, $timeout));
        } finally {
            // Off the lock, and on the way out however pull() ended.
            $this->flushReports();
        }
    }

    /**
     * The body of receive(), on the connection lock.
     *
     * Holds it across the fetch, which is what an ack from a handler coroutine waits
     * behind — see the class docblock for that bound and why it is safe.
     */
    private function pull(Queue $queue, int $timeout): ?Message
    {
        $this->ensure($queue);
        $key = $this->identity($queue);
        $this->drainDeadLetters($queue, $key);

        // Priority first (no_wait poll), then the normal queue for up to $timeout.
        $jsMessage = $this->fetchOne($this->consumers[$key]['priority'], 0.25, true)
            ?? $this->fetchOne($this->consumers[$key]['normal'], (float) $timeout, false);

        if (!$jsMessage instanceof JetStreamMessage) {
            return null;
        }

        /** @var array{pid: string, queue: string, timestamp: int, payload: array<mixed>} $data */
        $data = json_decode($jsMessage->getData(), true);
        $this->inFlight[$data['pid']] = $jsMessage;

        return new Message($data)
            // JetStream counts deliveries from 1; expose it as the Redis-style attempt count.
            ->setAttempts(max(0, $jsMessage->metadata()->numDelivered - 1))
            ->setSequence($jsMessage->metadata()->streamSequence);
    }

    /**
     * Tell the server the handler is still working on this message.
     *
     * ackWait is a deadline, not a hint: when it passes with no ack the server
     * assumes the worker died and redelivers, so without this it is a hard
     * ceiling on how long a job may take. A job that runs past it is not merely
     * retried later — the redelivery is concurrent with the first attempt still
     * running, which for anything with a side effect means doing it twice at
     * once. Every extension buys another ackWait.
     *
     * Silent for a message that is no longer in flight: a handler racing its own
     * completion must not turn into an error on a job that already finished.
     */
    public function extend(Queue $queue, Message $message): void
    {
        $jsMessage = $this->inFlight[$message->getPid()] ?? null;

        if ($jsMessage instanceof JetStreamMessage) {
            $this->command(fn() => $this->onCommands($jsMessage)->inProgress());
        }
    }

    /**
     * How often {@see self::extend()} should be called while a handler runs.
     *
     * A third of ackWait, so two consecutive extensions can be lost — to a
     * scheduling delay, or a hiccup on the socket — before the server gives up
     * on the message and redelivers it.
     */
    public function extendInterval(): float
    {
        return max(0.1, $this->ackWait / 3);
    }

    /**
     * Rebuild this broker's connections in place.
     *
     * Pool::recover() probes a failed resource for reset()/reconnect() and
     * destroys it when it finds neither, which meant a single failed lease
     * threw away the whole broker — its provisioning cache, its consumer
     * handles and its advisory subscriptions — and rebuilt them on next use.
     * Recovering in place keeps the slot.
     *
     * Everything derived from the old sockets is dropped rather than reused:
     * consumer handles and subscriptions belong to the connection that created
     * them. In-flight messages are deliberately not carried over — their ack
     * subjects died with the connection, so the honest outcome is to let the
     * server redeliver them on ackWait rather than pretend they can still be
     * acknowledged.
     */
    public function reconnect(): bool
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // Already gone. The point is to stop using it, not to close it well.
        }

        // A broker handed a live Connection has nothing to rebuild from: that
        // socket is the only one it will ever have, and clearing the caches
        // would just make the next call reach for it again, now closed. Saying
        // so lets Pool::recover() destroy the resource and construct a fresh
        // one, which is the only way back for this shape.
        if (!$this->source instanceof \Closure) {
            return false;
        }

        $this->connection = null;
        $this->js = null;
        $this->commandsConnection = null;
        $this->commandsJs = null;
        $this->commandsConsumers = [];
        $this->consumers = [];
        $this->advisories = [];
        $this->provisioned = [];
        $this->inFlight = [];

        return true;
    }

    public function commit(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();
        $jsMessage = $this->inFlight[$pid] ?? null;
        if (!$jsMessage instanceof JetStreamMessage) {
            return;
        }

        // Dropped however the ack ends. The map records that this instance owes
        // an ack for the message, not that one succeeded, and ackSync() is a
        // request-reply that throws on a transient failure. Nothing else would
        // clear the entry: reject() is the only other place that unsets, and
        // Adapter::runPhases() deliberately does not reject after a commit
        // failure -- the work is done, so a NAK there is a guaranteed duplicate.
        // Left behind, the entry has no owner and pins a JetStreamMessage for
        // the life of the worker, one per failed ack.
        try {
            $this->command(fn() => $this->onCommands($jsMessage)->ackSync());
        } finally {
            unset($this->inFlight[$pid]);
        }
    }

    public function reject(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();
        $jsMessage = $this->inFlight[$pid] ?? null;
        if (!$jsMessage instanceof JetStreamMessage) {
            return;
        }
        unset($this->inFlight[$pid]);

        $numDelivered = $jsMessage->metadata()->numDelivered;

        $this->command(function () use ($queue, $jsMessage, $numDelivered): void {
            $onCommands = $this->onCommands($jsMessage);

            if ($numDelivered >= $this->maxDeliver) {
                // Exhausted: park on the dead stream and drop it from the work stream.
                $this->commandsJs()->publish($this->deadSubject($queue), $jsMessage->getData());
                $onCommands->term('max deliveries exceeded');

                return;
            }

            // Redeliver later (AckWait/NAK); a crashed worker is reclaimed the same way.
            $onCommands->nak($this->backoffFor($numDelivered));
        });
    }

    /**
     * The delay this attempt's NAK should carry, from the tier backoff.
     *
     * A bare nak() redelivers immediately, so the backoff array governed only
     * the per-attempt ack timer and never the rescheduling — a permanently
     * failing job burned its whole maxDeliver budget in a tight loop instead of
     * spreading over the window the backoff describes, and reached the dead
     * letter in seconds rather than minutes.
     *
     * Attempts are 1-based and the last entry repeats, matching how JetStream
     * reads the same array for its own timer, so the two agree on every attempt.
     */
    private function backoffFor(int $numDelivered): ?float
    {
        if ($this->backoff === null) {
            return null;
        }

        return $this->backoff[min(max($numDelivered, 1), \count($this->backoff)) - 1];
    }

    /**
     * Re-drive dead-lettered messages back onto the work queue, up to $limit.
     *
     * $maxAttempts and $newerThan exist only for signature compatibility with
     * Broker\Redis::retry() (cloud calls it with them); they are not applied here.
     * In the JetStream model attempts are capped server-side by maxDeliver before a
     * message reaches the dead stream, so there is nothing left to gate on re-drive.
     *
     * Takes the connection lock for the whole sweep rather than per message: this is a
     * maintenance call, made against a publisher or maintenance broker, not one whose
     * consume loop is running — so there is no ack on the other side of it to starve.
     *
     * This is the call that motivates {@see Provisioning::Require}. ensure() below is
     * the broker's own configuration reaching a queue it does not own: a maintenance
     * task built with different knobs rewrites the work stream's replica count, the dead
     * stream's TTL and the worker consumers' ack settings just by re-driving. Construct
     * the broker with Provisioning::Require and ensure() adopts instead, so the sweep
     * moves messages and changes nothing else.
     */
    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $this->synchronize(function () use ($queue, $limit): void {
            $this->ensure($queue);

            // Created in both modes on purpose: this durable reads the dead stream and
            // carries none of the settings a running fleet depends on, so requiring it
            // to pre-exist would only mean refusing the first re-drive of every queue.
            $consumer = $this->js()->createConsumer($this->deadStream($queue), new ConsumerConfig(
                durableName: self::CONSUMER_RETRY,
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                filterSubject: $this->deadSubject($queue),
            ));

            $remaining = $limit ?? 500;
            while ($remaining > 0) {
                $jsMessage = $this->fetchOne($consumer, 1.0, false);
                if (!$jsMessage instanceof JetStreamMessage) {
                    break;
                }
                // Re-drive onto the work queue, then remove it from the dead stream.
                $this->js()->publish($this->workSubject($queue), $jsMessage->getData());
                $jsMessage->ackSync();
                $remaining--;
            }
        });
    }

    /**
     * Reaping stranded in-flight jobs is unnecessary on JetStream: AckWait redelivery
     * reclaims a message whose worker died before committing. Kept for drop-in
     * compatibility with the Redis broker's call sites; always returns 0.
     */
    public function reap(Queue $queue, int $olderThan = 90000, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): int
    {
        return 0;
    }

    /**
     * Queue depth, read on the commands connection under its lock, so it is safe to
     * call from a telemetry or health coroutine while another coroutine is in receive()
     * on this same broker.
     *
     * This used to need a third connection of its own, because nothing serialised the
     * socket and a depth read from the telemetry coroutine could land on top of the
     * consume loop's fetch. The commands connection already carries traffic from
     * arbitrary coroutines behind a lock, which is the same guarantee for one fewer
     * socket -- and the guarantee is now enforced rather than documented.
     *
     * Still a passive observer: it does NOT provision (ensure()) or drain dead letters
     * -- the consume loop owns those -- and reports 0 for a queue whose streams do not
     * exist yet, matching Broker\Redis's empty-list semantics.
     */
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $stream = $this->workStream($queue);

        return $this->command(function () use ($queue, $stream, $failedJobs): int {
            try {
                if ($failedJobs) {
                    return $this->commandsJs()->getStreamInfo($this->deadStream($queue))->state->messages;
                }

                return $this->commandsConsumer($stream, self::CONSUMER_NORMAL)->info(true)->numPending
                    + $this->commandsConsumer($stream, self::CONSUMER_PRIORITY)->info(true)->numPending;
            } catch (JetStreamException $e) {
                if ($e->apiError?->code === 404) {
                    return 0; // stream/consumer not provisioned yet — nothing enqueued
                }
                throw $e;
            }
        });
    }

    /**
     * Keep this broker's connections alive while nothing is using them.
     *
     * NATS pings every 120s and closes after two go unanswered, and the client
     * keepalive only runs while a caller is inside a call — so a broker parked
     * in a publisher pool between publishes is reaped on a timer nobody is
     * watching, and the next publish writes into a dead socket.
     *
     * Named tick() rather than maintain() on purpose: this reads the socket, where
     * {@see Pool::maintain()} sweeps only idle resources. Each connection is ticked
     * under its own lock, so unlike the pre-split version this is safe to call while a
     * consume loop is running -- the receive tick simply waits for the fetch in front
     * of it.
     */
    public function tick(): void
    {
        if ($this->connection instanceof NatsConnection) {
            // Bound to a local: the closure must tick the connection this check passed
            // on, not whatever the property holds by the time the lock is granted.
            $receive = $this->connection;
            $this->synchronize(fn() => $receive->tick());
        }

        // Only when it is a distinct socket; a publisher-only broker reuses one, and
        // ticking it twice under two locks would be the one call that deadlocks.
        if ($this->commandsConnection instanceof NatsConnection && $this->commandsConnection !== $this->connection) {
            $commands = $this->commandsConnection;
            $this->command(fn() => $commands->tick());
        }
    }

    public function close(): void
    {
        $this->connection?->close();

        // Only when it is a distinct socket; a publisher-only broker reuses one.
        // Deliberately off both locks: shutdown must not depend on acquiring a lock a
        // hung caller might still be holding.
        if ($this->commandsConnection instanceof NatsConnection && $this->commandsConnection !== $this->connection) {
            $this->commandsConnection->close();
        }
    }

    /** Fetch a single message, or null on timeout / empty. */
    private function fetchOne(NatsConsumer $consumer, float $timeout, bool $noWait): ?JetStreamMessage
    {
        foreach ($consumer->fetch(1, $timeout, $noWait) as $message) {
            return $message;
        }

        return null;
    }

    /**
     * Idempotently provision the work + dead streams and the durable consumers.
     *
     * Retried, because concurrent *first* provisioning of a replicated stream does not
     * degrade gracefully. Measured against a 3-node JetStream cluster, creating a fresh
     * R3 stream and its two durable consumers takes ~290ms from a single process and
     * succeeds every time; two processes doing it at once both exceed the client's 5s
     * request timeout and neither completes. It is a cliff rather than a slope -- eight
     * concurrent cold starts landed 0-1 successes. The server logs show why: the
     * competing CREATEs drive the stream and consumer RAFT groups into repeated leader
     * elections, which continue for minutes after the callers have given up.
     *
     * The retry works because the state that makes an attempt expensive does not survive
     * it. Once any one process wins, the stream and consumers exist, and JetStream
     * answers a repeat CREATE for an identical config without another election -- eight
     * concurrent processes against an existing stream provision in 19-34ms with no
     * election at all. So a later attempt is not a rerun of the same race.
     *
     * This path is the first pod that ever touches a queue, not a scale-up: a KEDA 0->N
     * expansion runs against a stream that already exists and is the cheap case above.
     *
     * Reading the current config first, to skip a no-op write, was tried and made this
     * worse: the extra round trips spend the same 5s budget, taking 8 concurrent cold
     * starts from 8/8 down to 7/8.
     */
    private function ensure(Queue $queue): void
    {
        $key = $this->identity($queue);
        if (isset($this->provisioned[$key])) {
            return;
        }

        if ($this->provisioning === Provisioning::Require) {
            $this->adopt($queue, $key);

            return;
        }

        $attempt = 0;
        while (true) {
            try {
                $this->provision($queue, $key);

                return;
            } catch (TimeoutException $e) {
                if (++$attempt >= self::PROVISION_ATTEMPTS) {
                    throw $e;
                }

                // Full jitter over an exponentially growing window, so processes that
                // collided once are not released together to collide again.
                usleep(random_int(0, self::PROVISION_BACKOFF_US << ($attempt - 1)));
            }
        }
    }

    /** One provisioning attempt. See ensure() for why this is retried. */
    private function provision(Queue $queue, string $key): void
    {
        $this->guardStreamName($queue, $key);

        // An explicit maxAge states the TTL where producer and consumer read the same
        // value; the jobTtl derivation is per-queue-object, so the two sides agree only
        // as long as both construct the Queue the same way.
        $maxAge = $this->maxAge ?? ($queue->jobTtl > 0 ? (float) $queue->jobTtl : null);

        // JetStream refuses a stream whose duplicate window outlives its max
        // age, and it is right to: an id cannot be recognised as a duplicate of
        // a message the stream has already discarded. So a queue with a jobTtl
        // shorter than the configured window gets the shorter of the two, and
        // its deduplication reaches exactly as far back as its messages do.
        $duplicateWindow = $maxAge === null
            ? $this->duplicateWindow
            : min($this->duplicateWindow, $maxAge);

        $this->js()->createOrUpdateStream(new StreamConfig(
            name: $this->workStream($queue),
            subjects: [$this->workSubject($queue), $this->prioritySubject($queue)],
            description: $key,
            retention: RetentionPolicy::WorkQueue,
            maxMsgs: $this->maxMsgs,
            maxBytes: $this->maxBytes,
            maxMsgSize: $this->maxMsgSize,
            maxAge: $maxAge,
            storage: $this->storage,
            replicas: $this->replicas,
            discard: $this->discard,
            // Without this the stream keeps no memory of message ids, so
            // Nats-Msg-Id is carried on the wire and then ignored, and a
            // retried publish is stored as a second message.
            duplicateWindow: $duplicateWindow,
            metadata: [self::METADATA_IDENTITY => $key],
        ));

        $this->js()->createOrUpdateStream(new StreamConfig(
            name: $this->deadStream($queue),
            subjects: [$this->deadSubject($queue)],
            description: $key,
            retention: RetentionPolicy::WorkQueue,
            // Size limits are the work stream's backpressure and do not belong on a
            // stream nobody publishes work to -- but maxMsgSize is mirrored: a message
            // the work stream accepted has to be storable as a dead letter, or it is
            // lost at exactly the point an operator would go looking for it.
            maxMsgSize: $this->maxMsgSize,
            maxAge: $this->deadMaxAge,
            storage: $this->storage,
            replicas: $this->replicas,
            metadata: [self::METADATA_IDENTITY => $key],
        ));

        $this->consumers[$key] = [
            'normal' => $this->js()->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: self::CONSUMER_NORMAL,
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->workSubject($queue),
                maxWaiting: $this->maxWaiting,
                maxAckPending: $this->maxAckPending,
                inactiveThreshold: $this->inactiveThreshold,
                backoff: $this->backoff,
            )),
            'priority' => $this->js()->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: self::CONSUMER_PRIORITY,
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->prioritySubject($queue),
                maxWaiting: $this->maxWaiting,
                maxAckPending: $this->maxAckPending,
                inactiveThreshold: $this->inactiveThreshold,
                backoff: $this->backoff,
            )),
        ];

        // Best-effort terminal dead-lettering for the crash-loop case: a worker that
        // dies (never reject()s) is redelivered by AckWait until maxDeliver, after which
        // JetStream stops delivering and emits this advisory. We drain it in receive()
        // and move the stuck message to the dead stream. Caveat: core
        // advisories are ephemeral, so a message that exhausts while no broker is
        // subscribed stays as pending backlog (still visible) rather than dead-lettered.
        // The queue group is what keeps this to one dead-letter copy. A plain
        // subscription delivers the advisory to every worker process, and each
        // of them then publishes its own copy of the exhausted message onto the
        // dead stream — so the dead letter multiplies by the worker count, on
        // exactly the messages an operator is trying to read. With a group the
        // server picks one subscriber.
        $this->advisories[$key] = $this->connection()->subscribe(
            self::ADVISORY_MAX_DELIVERIES . ".{$this->workStream($queue)}.*",
            queue: self::ADVISORY_GROUP,
        );

        $this->provisioned[$key] = true;
    }

    /**
     * Take up a queue that is already provisioned, without sending any configuration.
     *
     * The counterpart to provision() under {@see Provisioning::Require}: the streams and
     * the two worker consumers must exist, and this broker's own settings are never
     * written to them. That is the whole guarantee — a maintenance process built with a
     * different ackWait, replica count or dead-letter TTL can use the queue without
     * restyling it underneath the fleet that owns it.
     *
     * Refusing is the point of the absent case. A queue that has never been provisioned
     * has no configuration to inherit, and creating one here from a process that does not
     * own the queue is exactly the side effect this mode exists to remove.
     */
    private function adopt(Queue $queue, string $key): void
    {
        // Read-only: the length limit and the cross-queue ownership check both hold
        // here, and neither sends configuration.
        $this->guardStreamName($queue, $key);

        foreach ([$this->workStream($queue), $this->deadStream($queue)] as $stream) {
            try {
                $this->js()->getStreamInfo($stream);
            } catch (JetStreamException $e) {
                if ($e->apiError?->code === 404) {
                    throw new \RuntimeException("NATS stream \"{$stream}\" is not provisioned; queue \"{$queue->name}\" must be created by its own producer or consumer before this broker can use it.", $e->getCode(), $e);
                }
                throw $e;
            }
        }

        $this->consumers[$key] = [
            'normal' => $this->adoptConsumer($queue, self::CONSUMER_NORMAL),
            'priority' => $this->adoptConsumer($queue, self::CONSUMER_PRIORITY),
        ];

        // Same advisory subscription provision() takes: a core subscription carries no
        // configuration, and a broker consuming a pre-provisioned queue still owes its
        // exhausted messages a dead letter.
        $this->advisories[$key] = $this->connection()->subscribe(
            self::ADVISORY_MAX_DELIVERIES . ".{$this->workStream($queue)}.*",
            queue: self::ADVISORY_GROUP,
        );

        $this->provisioned[$key] = true;
    }

    /** Resolve an existing durable consumer, refusing rather than creating it. */
    private function adoptConsumer(Queue $queue, string $durable): NatsConsumer
    {
        $stream = $this->workStream($queue);

        try {
            return $this->js()->getConsumer($stream, $durable);
        } catch (JetStreamException $e) {
            if ($e->apiError?->code === 404) {
                throw new \RuntimeException("NATS consumer \"{$durable}\" on stream \"{$stream}\" is not provisioned; queue \"{$queue->name}\" must be created by its own producer or consumer before this broker can use it.", $e->getCode(), $e);
            }
            throw $e;
        }
    }

    /**
     * Reject a stream name that would overflow JetStream's limit, or that a different
     * queue identity already owns. The owner is recorded in the stream's metadata and
     * checked against server state, so a collision between separate broker instances or
     * processes is caught, not just within one instance's memory. This is a loud
     * backstop for the run-one-namespace-per-account contract, not a concurrency lock:
     * two colliding names provisioned at the very same instant can still both create
     * the (identical) stream before either sees the other.
     */
    private function guardStreamName(Queue $queue, string $identity): void
    {
        // The dead stream (work name + suffix) is the longest, so if it fits, both do.
        // Fixed-width names never overflow, but a long queue name can -- fail clearly
        // rather than letting JetStream reject the create with an opaque error.
        $longest = $this->deadStream($queue);
        if (\strlen($longest) > self::MAX_STREAM_NAME) {
            throw new \RuntimeException("NATS stream name \"{$longest}\" exceeds JetStream's " . self::MAX_STREAM_NAME . '-byte limit; shorten queue "' . $queue->name . '".');
        }

        $stream = $this->workStream($queue);
        try {
            $owner = ($this->js()->getStreamInfo($stream)->config->metadata ?? [])[self::METADATA_IDENTITY] ?? null;
        } catch (JetStreamException $e) {
            if ($e->apiError?->code !== 404) {
                throw $e; // a real JetStream error, not "stream absent" -- don't mask it
            }
            $owner = null; // stream not provisioned yet
        }
        if ($owner !== null && $owner !== $identity) {
            throw new \RuntimeException("NATS stream \"{$stream}\" already belongs to queue \"{$owner}\", not \"{$identity}\"; rename one queue.");
        }
    }

    /** Move messages that exhausted maxDeliver (per the advisory) onto the dead stream. */
    private function drainDeadLetters(Queue $queue, string $key): void
    {
        $advisory = $this->advisories[$key] ?? null;
        if (!$advisory instanceof \Utopia\NATS\Subscription) {
            return;
        }

        while (($event = $advisory->nextMessage(0.0)) instanceof \Utopia\NATS\Message) {
            $decoded = json_decode($event->data, true);
            $seq = \is_array($decoded) ? ($decoded['stream_seq'] ?? null) : null;
            if (!\is_int($seq)) {
                continue;
            }

            try {
                $stored = $this->js()->getMessage($this->workStream($queue), $seq);
                $this->js()->publish($this->deadSubject($queue), $stored->data);
                $this->js()->deleteMessage($this->workStream($queue), $seq);
            } catch (\Throwable $error) {
                // Usually benign — the message was acked, deleted or claimed by
                // another worker between the advisory and this read. But a real
                // failure here loses the dead letter outright: the message is
                // past maxDeliver, so nothing will deliver it again, and if it
                // never reaches the dead stream there is no record of it
                // anywhere. Reported rather than discarded, because the two
                // cases are indistinguishable from the outside and only one of
                // them is fine.
                $this->report($error);
            }
        }
    }

    /** Logical queue identity (namespace + name); used for cache keys and stream naming. */
    private function identity(Queue $queue): string
    {
        // Length-prefix the namespace so a delimiter in either field can't create an
        // ambiguous join (ns "a.b"+name "c" vs "a"+"b.c"). Byte-safe: unlike json_encode
        // it never fails on invalid UTF-8 (which would collapse to an empty identity).
        return \strlen($queue->namespace) . ':' . $queue->namespace . ':' . $queue->name;
    }

    private function workStream(Queue $queue): string
    {
        // NATS-idiomatic: a short uppercase category prefix (mirrors JetStream's own
        // KV_/OBJ_ streams) plus the queue name, e.g. Q_AUDITS. The namespace is not
        // folded in -- isolation is per-account/cluster -- and ensure() guards the rare
        // case of two names sanitizing to the same stream.
        return self::STREAM_PREFIX . $this->streamToken($queue->name);
    }

    private function deadStream(Queue $queue): string
    {
        return $this->workStream($queue) . self::DEAD_STREAM_SUFFIX;
    }

    /**
     * Subject namespace for a queue: a fixed root token plus the queue name as a single
     * dot-free token, e.g. q.audits — the class tail (.normal/.priority/.dead) is appended
     * by the callers below. subjectToken() collapses any dot in the name to '_' so the name
     * can never split into extra subject tokens, and ensure() rejects two names that
     * collapse to the same subject. Subscribe q.> to observe all queue traffic.
     */
    private function subjectBase(Queue $queue): string
    {
        return self::SUBJECT_PREFIX . '.' . $this->subjectToken($queue->name);
    }

    private function workSubject(Queue $queue): string
    {
        return $this->subjectBase($queue) . '.' . self::SUBJECT_NORMAL;
    }

    private function prioritySubject(Queue $queue): string
    {
        return $this->subjectBase($queue) . '.' . self::SUBJECT_PRIORITY;
    }

    private function deadSubject(Queue $queue): string
    {
        return $this->subjectBase($queue) . '.' . self::SUBJECT_DEAD;
    }

    /** Stream names are uppercase and forbid dots; anything outside A-Z 0-9 _ - maps to '_'. */
    private function streamToken(string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name));
    }

    /** A single lowercase subject token; dots (token separators) and any other
     *  character outside a-z 0-9 _ - collapse to '_'. */
    private function subjectToken(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name));
    }
}
