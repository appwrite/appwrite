<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Utopia\NATS\Connection as NatsConnection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\Consumer as NatsConsumer;
use Utopia\NATS\JetStream\ConsumerConfig;
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

    // A second connection reserved for passive management reads (getQueueSize). Those
    // run from the telemetry/health coroutine, NOT the consume coroutine, and a NATS
    // socket cannot be read by two coroutines at once — Swoole aborts the process with
    // "Socket#N has already been bound to another coroutine". Keeping these reads off the
    // consume connection is the fix; see controlConnection().
    private ?NatsConnection $controlConnection = null;
    private ?JetStream $controlJs = null;

    /** @var array<string, array<string, NatsConsumer>> control-connection consumer handles, [stream][durable] */
    private array $controlConsumers = [];

    /**
     * A NATS Connection is single-owner (one socket, one shared read pump), so it is
     * NOT safe to share across concurrent coroutines. Pass a Closure factory rather
     * than a live Connection when the consumer forks or reconnects per worker (each
     * worker resolves its own connection), and run at most one message at a time per
     * connection (e.g. `job('…', 1)`) or lease one connection
     * per coroutine from a pool.
     *
     * commit()/reject() correlate the JetStream acknowledgement to a message through an
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
     *        nowhere, which is how a lost dead letter becomes invisible.
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
    ) {
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
     * The connection for passive management reads (getQueueSize), separate from the
     * consume connection so a telemetry/health coroutine never reads the same socket
     * the consume loop is blocked on. Requires the Closure factory to open a second
     * connection; a broker built from a live Connection (publisher-only use, where no
     * concurrent consume loop exists) falls back to the single connection.
     */
    private function controlConnection(): NatsConnection
    {
        if ($this->controlConnection instanceof NatsConnection) {
            return $this->controlConnection;
        }

        return $this->controlConnection = $this->source instanceof \Closure
            ? ($this->source)()
            : $this->connection();
    }

    private function controlJs(): JetStream
    {
        return $this->controlJs ??= $this->controlConnection()->jetStream();
    }

    private function controlConsumer(string $stream, string $durable): NatsConsumer
    {
        return $this->controlConsumers[$stream][$durable] ??= $this->controlJs()->getConsumer($stream, $durable);
    }

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        $this->ensure($queue);

        $subject = $priority ? $this->prioritySubject($queue) : $this->workSubject($queue);
        $this->publishEnvelope($subject, $this->envelope($queue, $payload));

        return true;
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        if ($payloads === []) {
            return true;
        }

        $this->ensure($queue);

        // JetStream publishes one subject at a time, so the saving here is the
        // stream check and the connection checkout rather than the round trips.
        $subject = $priority ? $this->prioritySubject($queue) : $this->workSubject($queue);
        foreach ($payloads as $payload) {
            $this->publishEnvelope($subject, $this->envelope($queue, $payload));
        }

        return true;
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
     * Hand a failure to the caller's reporter, if it gave one.
     *
     * Never throws: a reporting hook that fails must not escalate into the
     * failure it was called to describe.
     */
    private function report(\Throwable $error): void
    {
        if (!$this->onError instanceof \Closure) {
            return;
        }

        try {
            ($this->onError)($error);
        } catch (\Throwable) {
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
            $jsMessage->inProgress();
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
        $this->controlConnection = null;
        $this->controlJs = null;
        $this->controlConsumers = [];
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
        if ($jsMessage instanceof JetStreamMessage) {
            $jsMessage->ackSync();
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

        if ($jsMessage->metadata()->numDelivered >= $this->maxDeliver) {
            // Exhausted: park on the dead stream and drop it from the work stream.
            $this->js()->publish($this->deadSubject($queue), $jsMessage->getData());
            $jsMessage->term('max deliveries exceeded');

            return;
        }

        // Redeliver later (AckWait/NAK); a crashed worker is reclaimed the same way.
        $jsMessage->nak($this->backoffFor($jsMessage->metadata()->numDelivered));
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
     */
    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $this->ensure($queue);

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
     * Queue depth, read on the control connection so it is safe to call from a
     * telemetry/health coroutine while another coroutine is in receive() on this same
     * broker. It is a passive observer: it does NOT provision (ensure()) or drain dead
     * letters — the consume loop owns those — and reports 0 for a queue whose streams
     * do not exist yet, matching Broker\Redis's empty-list semantics.
     */
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $stream = $this->workStream($queue);

        try {
            if ($failedJobs) {
                return $this->controlJs()->getStreamInfo($this->deadStream($queue))->state->messages;
            }

            return $this->controlConsumer($stream, self::CONSUMER_NORMAL)->info(true)->numPending
                + $this->controlConsumer($stream, self::CONSUMER_PRIORITY)->info(true)->numPending;
        } catch (JetStreamException $e) {
            if ($e->apiError?->code === 404) {
                return 0; // stream/consumer not provisioned yet — nothing enqueued
            }
            throw $e;
        }
    }

    /**
     * Keep this broker's connections alive while nothing is using them.
     *
     * NATS pings every 120s and closes after two go unanswered, and the client
     * keepalive only runs while a caller is inside a call — so a broker parked
     * in a publisher pool between publishes is reaped on a timer nobody is
     * watching, and the next publish writes into a dead socket.
     *
     * Named tick() rather than maintain() on purpose: this reads the socket, so
     * the caller must hold the broker exclusively. {@see Pool::maintain()} is
     * what a running worker calls, because it sweeps only idle resources and so
     * cannot collide with a consume loop. Do not call this on a broker whose
     * receive loop is running.
     */
    public function tick(): void
    {
        $this->connection?->tick();

        // Only when it is a distinct socket; a publisher-only broker reuses one.
        if ($this->controlConnection instanceof NatsConnection && $this->controlConnection !== $this->connection) {
            $this->controlConnection->tick();
        }
    }

    public function close(): void
    {
        $this->connection?->close();

        // Only when it is a distinct socket; a publisher-only broker reuses the one connection.
        if ($this->controlConnection instanceof NatsConnection && $this->controlConnection !== $this->connection) {
            $this->controlConnection->close();
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

    /** Idempotently provision the work + dead streams and the durable consumers. */
    private function ensure(Queue $queue): void
    {
        $key = $this->identity($queue);
        if (isset($this->provisioned[$key])) {
            return;
        }

        $this->guardStreamName($queue, $key);

        $maxAge = $queue->jobTtl > 0 ? (float) $queue->jobTtl : null;

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
            maxAge: $maxAge,
            storage: $this->storage,
            replicas: $this->replicas,
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
                backoff: $this->backoff,
            )),
            'priority' => $this->js()->createConsumer($this->workStream($queue), new ConsumerConfig(
                durableName: self::CONSUMER_PRIORITY,
                ackPolicy: AckPolicy::Explicit,
                ackWait: $this->ackWait,
                maxDeliver: $this->maxDeliver,
                filterSubject: $this->prioritySubject($queue),
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
