<?php

namespace Utopia\Queue\Broker;

use Utopia\Queue\Codec;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Connection;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

class Redis implements Synchronous, Consumer
{
    private const int POP_TIMEOUT = 2;
    private const int RECONNECT_BACKOFF_MS = 100;
    private const int RECONNECT_MAX_BACKOFF_MS = 5_000;

    /** Claim lifetime; handlers refresh it every third of this interval. */
    private const int CLAIM_TTL = 90;

    /** Lock expiry schedules the next fleet sweep; never release it early. */
    private const int REAP_INTERVAL = 60;

    /** Maximum claims requeued per sweep; bounds work, not elapsed time. */
    private const int REAP_LIMIT = 1_000;

    private bool $closed = false;

    /**
     * Queues attempted by this broker, keyed by namespace/name pairs.
     *
     * @var array<string, Queue>
     */
    private array $served = [];
    private int $reconnectAttempt = 0;
    private int $reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
    /**
     * @var (callable(Queue, \Throwable, int, int): void)|null
     */
    private $reconnectCallback;
    /**
     * @var (callable(Queue, int): void)|null
     */
    private $reconnectSuccessCallback;

    public function __construct(
        // Blocking receive loop + claim writes (single caller).
        private readonly Connection $receive,
        // Acks and publishing; wrap in Locking when shared by coroutines.
        private readonly Connection $commands,
        // How an envelope is written and read; Json is what every release so
        // far has put on the list. See Codec\Compat before changing it on a
        // queue that already holds messages.
        private readonly Codec $codec = new Json(),
        // Minimum publish age for recovery without a live heartbeat.
        // Keep conservative until all workers and adapters heartbeat.
        private readonly int $reapAfter = 90_000,
    ) {}

    public function setReconnectCallback(?callable $callback): self
    {
        $this->reconnectCallback = $callback;

        return $this;
    }

    public function setReconnectSuccessCallback(?callable $callback): self
    {
        $this->reconnectSuccessCallback = $callback;

        return $this;
    }

    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        if ($this->isClosed()) {
            return [];
        }

        // An idle queue's stranded claims still need sweeping, so the queue is
        // remembered on the attempt, not on the first message.
        $this->served[serialize([$queue->namespace, $queue->name])] ??= $queue;

        $key = "{$queue->namespace}.queue.{$queue->name}";

        try {
            // One command for the whole batch: LMPOP's blocking form waits for
            // the first message exactly as BRPOP does, then takes whatever else
            // is already on the list in the same round trip -- so a queue
            // holding one message costs what it costs today and never waits for
            // company that is not coming. A batch of one stays on BRPOP, which
            // keeps LMPOP's Redis 7.0 floor on the consumers that asked for a
            // batch rather than on every deployment.
            if ($n > 1) {
                $batch = $this->receive->rightPopMany($key, $n, $timeout);
            } else {
                $raw = $this->receive->rightPop($key, $timeout);
                $batch = \is_string($raw) && $raw !== '' ? [$raw] : [];
            }

            if ($this->reconnectAttempt > 0) {
                $this->triggerReconnectSuccessCallback($queue, $this->reconnectAttempt);
            }

            $this->reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
            $this->reconnectAttempt = 0;
        } catch (\RedisException|\RedisClusterException $e) {
            if ($this->isClosed()) {
                return [];
            }

            $this->reconnectAttempt++;

            try {
                $this->receive->close();
            } catch (\Throwable) {
            }

            $sleepMs = mt_rand(0, $this->reconnectBackoffMs);
            $this->triggerReconnectCallback($queue, $e, $this->reconnectAttempt, $sleepMs);

            usleep($sleepMs * 1000);
            $this->reconnectBackoffMs = min(self::RECONNECT_MAX_BACKOFF_MS, $this->reconnectBackoffMs * 2);

            return [];
        }

        if ($batch === []) {
            return [];
        }

        return $this->claim($queue, $batch);
    }

    /**
     * Take ownership of popped bytes: store each job, mark them processing, and
     * bump the received counters.
     *
     * Between the pop and the write to the processing list these messages exist
     * nowhere but in this process. Claiming them is therefore not optional and
     * not partially skippable: a failure here puts the whole batch back on the
     * queue rather than letting it evaporate, and the caller sees the error.
     *
     * The counters move once for the batch instead of once per message, so the
     * claim costs 2N + 3 commands -- a job key and a claim heartbeat per
     * message -- rather than 5N.
     *
     * @param list<string> $batch
     * @return list<Message>
     */
    private function claim(Queue $queue, array $batch): array
    {
        $messages = [];
        $pids = [];
        $unclaimed = [];

        foreach ($batch as $raw) {
            try {
                $envelope = $this->codec->decode($raw);
            } catch (\Throwable) {
                $envelope = null;
            }

            if (!\is_array($envelope) || !isset($envelope['pid'], $envelope['queue'], $envelope['timestamp'])) {
                $this->park($queue, $raw);

                continue;
            }

            $envelope['timestamp'] = (int) $envelope['timestamp'];

            $message = new Message($envelope);
            $messages[] = $message;
            $pids[] = $message->getPid();
            $unclaimed[$message->getPid()] = $raw;
        }

        if ($messages === []) {
            return [];
        }

        try {
            // The bytes go back unchanged rather than re-encoded, which is the
            // encode this path used to pay on every message.
            foreach ($messages as $message) {
                $pid = $message->getPid();
                $this->receive->set("{$queue->namespace}.jobs.{$queue->name}.{$pid}", $unclaimed[$pid], $queue->jobTtl);

                // Write the heartbeat before exposing the claim to the reaper.
                // A failed claim leaves a key that expires on its own.
                $this->receive->set("{$queue->namespace}.claims.{$queue->name}.{$pid}", (string) time(), self::CLAIM_TTL);
            }

            // The line that makes them claimed, and the only one that does: a
            // message is recoverable once its pid is on the processing list and
            // not before, so nothing may be dropped from $unclaimed until this
            // returns. A job key written for a message that goes back on the
            // queue is simply overwritten by the claim that eventually keeps it.
            $this->receive->leftPushMany("{$queue->namespace}.processing.{$queue->name}", $pids);
            $unclaimed = [];

            // Past the point of no return: these are counters, and a failure
            // here leaves the batch claimed and reap()-able rather than lost.
            $this->receive->incrementBy("{$queue->namespace}.stats.{$queue->name}.total", \count($pids));
            $this->receive->incrementBy("{$queue->namespace}.stats.{$queue->name}.processing", \count($pids));
        } catch (\Throwable $error) {
            $this->restore($queue, $unclaimed);

            throw $error;
        }

        return $messages;
    }

    /**
     * Put back bytes that were popped but never claimed.
     *
     * Onto the pop end, so the batch is the next thing taken rather than going
     * behind everything published since. Pushed in reverse, because the last
     * one pushed there is the first one popped: the batch comes back in the
     * order it left.
     *
     * @param array<string, string> $unclaimed
     */
    private function restore(Queue $queue, array $unclaimed): void
    {
        if ($unclaimed === []) {
            return;
        }

        try {
            $this->receive->rightPushMany("{$queue->namespace}.queue.{$queue->name}", array_reverse(array_values($unclaimed)));
        } catch (\Throwable) {
            // Nothing left to try: the connection that would carry them back is
            // the one that just failed. The original error is what propagates.
        }
    }

    public function commit(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();

        $this->commands->remove("{$queue->namespace}.jobs.{$queue->name}.{$pid}");
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.success");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    /**
     * Park a failed message for the retry() sweep -- or, where the handler
     * declared the failure permanent, on the dead list the sweep never reads.
     *
     * The failed list is a retry queue in all but name: retry() pops it and
     * re-enqueues, so a message that fails the same way on every attempt
     * circulates until maxAttempts or newerThan finally parks it. A terminal
     * message skips that circuit and goes where an exhausted one ends up
     * anyway, on the first failure instead of after N of them.
     */
    public function reject(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();

        $list = $message->isTerminal() ? 'dead' : 'failed';

        $this->commands->leftPush("{$queue->namespace}.{$list}.{$queue->name}", $pid);
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.failed");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    /**
     * Refresh the claim while its handler runs. A late beat after commit is
     * harmless: the pid is no longer on the processing list and the key expires.
     */
    public function extend(Queue $queue, Message $message): void
    {
        $this->commands->set("{$queue->namespace}.claims.{$queue->name}.{$message->getPid()}", (string) time(), self::CLAIM_TTL);
    }

    /** Refresh often enough to tolerate two missed beats. */
    public function extendInterval(): float
    {
        return self::CLAIM_TTL / 3;
    }

    /** Recover stranded claims from served queues, once per fleet interval. */
    public function maintain(): void
    {
        foreach ($this->served as $queue) {
            if (!$this->commands->setNotExists("{$queue->namespace}.reap-lock.{$queue->name}", (string) time(), self::REAP_INTERVAL)) {
                continue;
            }

            $this->reap($queue, $this->reapAfter, limit: self::REAP_LIMIT, scan: self::REAP_LIMIT * 2);
        }
    }

    /** Idle resource hook used by Utopia\Pools\Pool::maintain(). */
    public function tick(): void
    {
        $this->maintain();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @phpstan-impure close() flips this from another coroutine mid-receive(). */
    private function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Set aside bytes no codec on this worker can read.
     *
     * The pop already took them off the queue, so the choice is where they go,
     * not whether they leave: dropping them loses the work silently, and
     * putting them back wedges the queue behind a message every worker chokes
     * on. The failed and dead lists hold pids, and a message nothing can decode
     * has no pid to hold -- so the raw bytes go on a list of their own, for a
     * human to read.
     */
    private function park(Queue $queue, string $raw): void
    {
        $this->receive->leftPush("{$queue->namespace}.poison.{$queue->name}", $raw);
    }

    private function triggerReconnectCallback(Queue $queue, \Throwable $error, int $attempt, int $sleepMs): void
    {
        if (!\is_callable($this->reconnectCallback)) {
            return;
        }

        try {
            ($this->reconnectCallback)($queue, $error, $attempt, $sleepMs);
        } catch (\Throwable) {
        }
    }

    private function triggerReconnectSuccessCallback(Queue $queue, int $attempts): void
    {
        if (!\is_callable($this->reconnectSuccessCallback)) {
            return;
        }

        try {
            ($this->reconnectSuccessCallback)($queue, $attempts);
        } catch (\Throwable) {
        }
    }

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        $key = "{$queue->namespace}.queue.{$queue->name}";
        $envelope = $this->codec->encode($this->envelope($queue, $payload));

        return $priority
            ? $this->commands->rightPush($key, $envelope)
            : $this->commands->leftPush($key, $envelope);
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        if ($payloads === []) {
            return true;
        }

        $encoded = [];
        foreach ($payloads as $payload) {
            $encoded[] = $this->codec->encode($this->envelope($queue, $payload));
        }

        $key = "{$queue->namespace}.queue.{$queue->name}";

        return $priority
            ? $this->commands->rightPushMany($key, $encoded)
            : $this->commands->leftPushMany($key, $encoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function envelope(Queue $queue, array $payload): array
    {
        return [
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];
    }

    /**
     * Take all jobs from the failed queue and re-enqueue them.
     *
     * @param int|null $limit The amount of jobs to retry
     * @param int|null $maxAttempts Jobs requeued this many times are parked on
     *        the dead queue instead of looping forever; null retries unbounded.
     * @param int|null $newerThan Only jobs enqueued within this many seconds
     *        are requeued; older ones are parked on the dead queue. Payloads
     *        never expire by default, so without this bound a sweep would
     *        resurrect arbitrarily old work.
     */
    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $start = time();
        $processed = 0;

        while ($limit === null || $processed < $limit) {
            $pid = $this->commands->rightPop("{$queue->namespace}.failed.{$queue->name}", self::POP_TIMEOUT);

            // No more jobs to retry
            if ($pid === false) {
                break;
            }

            // The payload expired; nothing left to requeue.
            $job = $this->getJob($queue, $pid);
            if ($job === false) {
                continue;
            }

            // Wrapped around to a job this sweep already requeued: put the
            // claim back and stop.
            if ($job->getTimestamp() >= $start) {
                $this->commands->rightPush("{$queue->namespace}.failed.{$queue->name}", $pid);
                break;
            }

            if (($maxAttempts !== null && $job->getAttempts() >= $maxAttempts)
                || ($newerThan !== null && $job->getTimestamp() < $start - $newerThan)) {
                $this->commands->leftPush("{$queue->namespace}.dead.{$queue->name}", $pid);
                continue;
            }

            $this->requeue($queue, $job);
            $processed++;
        }
    }

    /**
     * Recover claims left between receive() and commit/reject(). Live heartbeats
     * protect running handlers; the publish-age gate protects workers without
     * heartbeats. Keep $olderThan above their longest possible runtime.
     *
     * @param int $olderThan Seconds since enqueue before a heartbeat-less claim
     *        counts as stale
     * @param int|null $limit Maximum number of claims to requeue
     * @param int|null $maxAttempts Claims requeued this many times are parked
     *        on the dead queue; null reaps unbounded.
     * @param int|null $newerThan Only claims enqueued within this many seconds
     *        are requeued; older ones are parked on the dead queue.
     * @param int|null $scan Maximum claims examined; bounded sweeps share progress.
     * @return int The number of claims requeued
     */
    public function reap(Queue $queue, int $olderThan = 90000, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null, ?int $scan = null): int
    {
        $processing = "{$queue->namespace}.processing.{$queue->name}";
        $now = time();
        $cutoff = $now - $olderThan;
        $requeued = 0;

        $size = $this->commands->listSize($processing);
        if ($size === 0) {
            return 0;
        }

        // Share progress across sweep winners. Retained entries count from the
        // tail; remaining entries bound the cycle so arrivals cannot delay wrapping.
        $key = "{$queue->namespace}.reap-cursor.{$queue->name}";
        $cursor = $scan === null ? [] : explode(':', (string) $this->commands->get($key));
        $retained = (int) ($cursor[0] ?? 0);
        $remaining = (int) ($cursor[1] ?? 0);
        if ($retained < 0 || $retained >= $size || $remaining <= 0) {
            $retained = 0;
            $remaining = $size;
        }
        $remaining = min($remaining, $size - $retained);
        $length = $scan === null ? $size : min(max(1, $scan), $remaining);
        $claims = $this->commands->listRange($processing, $length, max(0, $size - $retained - $length));

        foreach (array_reverse($claims) as $pid) {
            if ($limit !== null && $requeued >= $limit) {
                break;
            }

            $remaining--;
            if (!\is_string($pid)) {
                $retained++;
                continue;
            }

            // The payload expired: the claim is unrecoverable, drop it.
            $job = $this->getJob($queue, $pid);
            if ($job === false) {
                $this->commands->listRemove($processing, $pid);
                continue;
            }

            if ($job->getTimestamp() > $cutoff
                || \is_string($this->commands->get("{$queue->namespace}.claims.{$queue->name}.{$pid}"))) {
                $retained++;
                continue;
            }

            if (($maxAttempts !== null && $job->getAttempts() >= $maxAttempts)
                || ($newerThan !== null && $job->getTimestamp() < $now - $newerThan)) {
                $this->commands->listRemove($processing, $pid);
                $this->commands->leftPush("{$queue->namespace}.dead.{$queue->name}", $pid);
                continue;
            }

            $this->requeue($queue, $job);
            $this->commands->listRemove($processing, $pid);
            $requeued++;
        }

        if ($scan !== null) {
            $this->commands->set($key, "{$retained}:{$remaining}");
        }

        return $requeued;
    }

    /**
     * Re-enqueue with a fresh pid and timestamp, carrying the attempt count
     * forward so retry() and reap() can park messages that never succeed.
     */
    private function requeue(Queue $queue, Message $job): void
    {
        $payload = [
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $job->getPayload(),
            'attempts' => $job->getAttempts() + 1,
        ];
        $this->commands->leftPush("{$queue->namespace}.queue.{$queue->name}", $this->codec->encode($payload));
    }

    private function getJob(Queue $queue, string $pid): Message|false
    {
        $value = $this->commands->get("{$queue->namespace}.jobs.{$queue->name}.{$pid}");

        // Missing or expired jobs come back null or false; anything else is a
        // stored envelope, which only the codec can read.
        if (\is_string($value)) {
            try {
                $value = $this->codec->decode($value);
            } catch (\Throwable) {
                return false;
            }
        }

        return \is_array($value) ? new Message($value) : false;
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $queueName = "{$queue->namespace}.queue.{$queue->name}";
        if ($failedJobs) {
            $queueName = "{$queue->namespace}.failed.{$queue->name}";
        }
        return $this->commands->listSize($queueName);
    }
}
