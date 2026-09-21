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

    /** Heartbeat lifetime; handlers refresh it every third of this interval. */
    private const int CLAIM_TTL = 90;

    /** Lock expiry schedules the next fleet sweep; never release it early. */
    private const int REAP_INTERVAL = 60;

    /** Maximum claims requeued per sweep; bounds work, not elapsed time. */
    private const int REAP_LIMIT = 1_000;

    private bool $closed = false;
    /** @var array<string, \Utopia\Queue\Internal\Buffer> */
    private array $settlements = [];

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
            $token = bin2hex(random_bytes(16));
            $reservation = "{$queue->namespace}.reservations.{$queue->name}.{$token}";
            $keys = [$key, "{$queue->namespace}.reservations.{$queue->name}", $reservation,
                "{$queue->namespace}.processing.{$queue->name}",
                "{$queue->namespace}.stats.{$queue->name}.total",
                "{$queue->namespace}.stats.{$queue->name}.processing"];
            $count = min(self::REAP_LIMIT, max(1, $n));
            $batch = $this->script($this->receive, 'reserve', $keys, [$count, self::CLAIM_TTL, max(0, $timeout), 0]);
            if ($batch === [] && $timeout > 0) {
                // The script registered this reservation before blocking. A crash
                // after the move is recoverable even before PHP sees the reply.
                $raw = $this->receive->rightPopLeftPush($key, $reservation, $timeout);
                if (\is_string($raw)) {
                    $batch = [$raw];
                    if ($count > 1) {
                        $batch = [...$batch, ...$this->script($this->receive, 'reserve', $keys, [$count - 1, self::CLAIM_TTL, 0, 1])];
                    }
                }
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

        return $this->claim($queue, $batch, $token, $reservation);
    }

    /** Finalize recoverable raw reservations without teaching Lua application codecs. */
    private function claim(Queue $queue, array $batch, string $token, string $reservation): array
    {
        $keys = ["{$queue->namespace}.reservations.{$queue->name}", $reservation,
            "{$queue->namespace}.processing.{$queue->name}",
            "{$queue->namespace}.stats.{$queue->name}.total",
            "{$queue->namespace}.stats.{$queue->name}.processing",
            "{$queue->namespace}.poison.{$queue->name}"];
        $messages = $args = $poison = [];
        foreach ($batch as $raw) {
            try {
                $envelope = $this->codec->decode($raw);
                if (!\is_array($envelope) || !isset($envelope['pid'], $envelope['queue'], $envelope['timestamp'])) {
                    throw new \UnexpectedValueException('Invalid queue envelope');
                }
                $envelope['timestamp'] = (int) $envelope['timestamp'];
                $message = new Message($envelope);
            } catch (\Throwable) {
                $poison[] = $raw;
                continue;
            }
            $message->setReceipt($token);
            $messages[] = $message;
            $pid = $message->getPid();
            $keys[] = "{$queue->namespace}.jobs.{$queue->name}.{$pid}";
            $keys[] = "{$queue->namespace}.claims.{$queue->name}.{$pid}";
            $keys[] = "{$queue->namespace}.owners.{$queue->name}.{$pid}";
            $args[] = $raw;
            $args[] = $pid;
        }
        $this->script($this->receive, 'claim', $keys, [self::CLAIM_TTL, $token, \count($messages), ...$args, ...$poison]);
        return $messages;
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->settle($queue, $message, 'commit');
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->settle($queue, $message, 'reject');
    }

    /** @param 'commit'|'reject'|'release' $operation */
    private function settle(Queue $queue, Message $message, string $operation): void
    {
        $pid = $message->getPid();
        $outcome = $operation === 'commit' ? 'success' : 'failed';
        $list = $operation === 'release' ? 'queue' : ($message->isTerminal() ? 'dead' : 'failed');
        $this->settlements[$queue->namespace] ??= new \Utopia\Queue\Internal\Buffer(function (array $requests, callable $resolved): void {
            $keys = $args = [];
            foreach ($requests as [$requestKeys, $requestArgs]) {
                array_push($keys, ...$requestKeys);
                array_push($args, ...$requestArgs);
            }
            foreach ($this->script($this->commands, 'settle', $keys, $args) as $index => $result) {
                $resolved($index, $result === false ? new \RedisException('Queue settlement failed') : $result);
            }
        });
        $result = $this->settlements[$queue->namespace]->request([[
            "{$queue->namespace}.claims.{$queue->name}.{$pid}",
            "{$queue->namespace}.jobs.{$queue->name}.{$pid}",
            "{$queue->namespace}.processing.{$queue->name}",
            "{$queue->namespace}.stats.{$queue->name}.processing",
            "{$queue->namespace}.stats.{$queue->name}.{$outcome}",
            "{$queue->namespace}.{$list}.{$queue->name}",
            "{$queue->namespace}.owners.{$queue->name}.{$pid}",
        ], [$message->getReceipt() ?? '', $pid, $operation, $queue->jobTtl]]);
        if ($result !== 1) {
            throw new \RuntimeException('Queue delivery is no longer owned by this consumer');
        }
    }

    /** Return prefetched work that never entered its handler, without counting a failure. */
    public function release(Queue $queue, Message ...$messages): void
    {
        foreach (array_reverse($messages) as $message) {
            $this->settle($queue, $message, 'release');
        }
    }

    public function extend(Queue $queue, Message ...$messages): void
    {
        if ($messages === []) {
            return;
        }
        $keys = $tokens = [];
        foreach ($messages as $message) {
            $keys[] = "{$queue->namespace}.claims.{$queue->name}.{$message->getPid()}";
            $keys[] = "{$queue->namespace}.owners.{$queue->name}.{$message->getPid()}";
            $tokens[] = $message->getReceipt() ?? '';
        }
        foreach (array_chunk($keys, self::REAP_LIMIT * 2) as $index => $chunk) {
            $this->script($this->commands, 'extend', $chunk, [self::CLAIM_TTL, ...\array_slice($tokens, $index * self::REAP_LIMIT, self::REAP_LIMIT)]);
        }
    }

    private function script(Connection $connection, string $name, array $keys, array $args): mixed
    {
        static $scripts = [];
        $script = $scripts[$name] ??= file_get_contents(__DIR__ . '/Redis/' . $name . '.lua');
        return $connection->execute($script, $keys, $args);
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

            $registry = "{$queue->namespace}.reservations.{$queue->name}";
            $expired = $this->script($this->commands, 'expired', [$registry], [self::REAP_LIMIT]);
            if ($expired !== []) {
                $this->script($this->commands, 'recover', [$registry, "{$queue->namespace}.queue.{$queue->name}", ...$expired], [self::REAP_LIMIT]);
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

    public function publish(Queue $queue, array $payload): bool
    {
        $key = "{$queue->namespace}.queue.{$queue->name}";
        $envelope = $this->codec->encode($this->envelope($queue, $payload));

        return $this->commands->leftPush($key, $envelope);
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        if ($payloads === []) {
            return true;
        }

        $encoded = [];
        foreach ($payloads as $payload) {
            $encoded[] = $this->codec->encode($this->envelope($queue, $payload));
        }

        $key = "{$queue->namespace}.queue.{$queue->name}";

        return $this->commands->leftPushMany($key, $encoded);
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
     * Take all jobs from the failed queue and requeue them.
     *
     * @param int|null $limit The amount of jobs to retry
     * @param int|null $maxAttempts Jobs requeued this many times are parked on
     *        the dead queue instead of looping forever; null retries unbounded.
     * @param int|null $newerThan Only jobs published within this many seconds
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
     * @param int $olderThan Seconds since publication before a heartbeat-less claim
     *        counts as stale
     * @param int|null $limit Maximum number of claims to requeue
     * @param int|null $maxAttempts Claims requeued this many times are parked
     *        on the dead queue; null reaps unbounded.
     * @param int|null $newerThan Only claims published within this many seconds
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

            $ownerKey = "{$queue->namespace}.owners.{$queue->name}.{$pid}";
            $owner = $this->commands->get($ownerKey);
            // Only legacy payloads expire while processing.
            $job = $this->getJob($queue, $pid);
            if ($job === false) {
                if (\is_string($owner)) {
                    throw new \RuntimeException('Queue delivery payload is missing');
                }
                $this->commands->listRemove($processing, $pid);
                continue;
            }

            if ($job->getTimestamp() > $cutoff
                || \is_string($this->commands->get("{$queue->namespace}.claims.{$queue->name}.{$pid}"))) {
                $retained++;
                continue;
            }

            $dead = ($maxAttempts !== null && $job->getAttempts() >= $maxAttempts)
                || ($newerThan !== null && $job->getTimestamp() < $now - $newerThan);
            $moved = $this->script($this->commands, 'reclaim', [
                $ownerKey, "{$queue->namespace}.claims.{$queue->name}.{$pid}",
                "{$queue->namespace}.jobs.{$queue->name}.{$pid}", $processing,
                "{$queue->namespace}.stats.{$queue->name}.processing",
                "{$queue->namespace}." . ($dead ? 'dead' : 'queue') . ".{$queue->name}",
            ], [\is_string($owner) ? $owner : '', $pid, $dead ? '' : $this->retryPayload($queue, $job), $queue->jobTtl]);
            if ($moved && !$dead) {
                $requeued++;
            } elseif (!$moved) {
                $retained++;
            }
        }

        if ($scan !== null) {
            $this->commands->set($key, "{$retained}:{$remaining}");
        }

        return $requeued;
    }

    /**
     * Requeue with a fresh pid and timestamp, carrying the attempt count
     * forward so retry() and reap() can park messages that never succeed.
     */
    private function requeue(Queue $queue, Message $job): void
    {
        $this->commands->leftPush("{$queue->namespace}.queue.{$queue->name}", $this->retryPayload($queue, $job));
    }

    private function retryPayload(Queue $queue, Message $job): string
    {
        $payload = [
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $job->getPayload(),
            'attempts' => $job->getAttempts() + 1,
        ];
        return $this->codec->encode($payload);
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
