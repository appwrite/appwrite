<?php

namespace Utopia\Queue\Broker;

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

    private bool $closed = false;
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

    public function receive(Queue $queue, int $timeout): ?Message
    {
        if ($this->isClosed()) {
            return null;
        }

        try {
            $nextMessage = $this->receive->rightPopArray("{$queue->namespace}.queue.{$queue->name}", $timeout);
            if ($this->reconnectAttempt > 0) {
                $this->triggerReconnectSuccessCallback($queue, $this->reconnectAttempt);
            }

            $this->reconnectBackoffMs = self::RECONNECT_BACKOFF_MS;
            $this->reconnectAttempt = 0;
        } catch (\RedisException|\RedisClusterException $e) {
            if ($this->isClosed()) {
                return null;
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

            return null;
        }

        if (!$nextMessage) {
            return null;
        }

        $nextMessage['timestamp'] = (int) $nextMessage['timestamp'];

        $message = new Message($nextMessage);
        $pid = $message->getPid();

        // Claim: store the job, mark it processing, bump received stats.
        $this->receive->setArray("{$queue->namespace}.jobs.{$queue->name}.{$pid}", $nextMessage, $queue->jobTtl);
        $this->receive->leftPush("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->receive->increment("{$queue->namespace}.stats.{$queue->name}.total");
        $this->receive->increment("{$queue->namespace}.stats.{$queue->name}.processing");

        return $message;
    }

    public function commit(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();

        $this->commands->remove("{$queue->namespace}.jobs.{$queue->name}.{$pid}");
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.success");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
    }

    public function reject(Queue $queue, Message $message): void
    {
        $pid = $message->getPid();

        $this->commands->leftPush("{$queue->namespace}.failed.{$queue->name}", $pid);
        $this->commands->increment("{$queue->namespace}.stats.{$queue->name}.failed");
        $this->commands->listRemove("{$queue->namespace}.processing.{$queue->name}", $pid);
        $this->commands->decrement("{$queue->namespace}.stats.{$queue->name}.processing");
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

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        $key = "{$queue->namespace}.queue.{$queue->name}";
        $envelope = $this->envelope($queue, $payload);

        return $priority
            ? $this->commands->rightPushArray($key, $envelope)
            : $this->commands->leftPushArray($key, $envelope);
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        if ($payloads === []) {
            return true;
        }

        $encoded = [];
        foreach ($payloads as $payload) {
            $encoded[] = (string) json_encode($this->envelope($queue, $payload));
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
     * Requeue claims whose worker died between receive() and commit/reject —
     * their messages sit on the processing list, invisible to consumers and to
     * retry(), until this reclaims them.
     *
     * Claims carry no timestamp of their own, so staleness is judged from the
     * message's enqueue timestamp: pass an $olderThan comfortably above the
     * longest possible handler runtime (for Kubernetes Jobs, the Job's
     * activeDeadlineSeconds) so an in-flight message can never be requeued
     * into a duplicate run.
     *
     * @param int $olderThan Seconds since enqueue before a claim counts as stale
     * @param int|null $limit Maximum number of claims to requeue
     * @param int|null $maxAttempts Claims requeued this many times are parked
     *        on the dead queue; null reaps unbounded.
     * @param int|null $newerThan Only claims enqueued within this many seconds
     *        are requeued; older ones are parked on the dead queue.
     * @return int The number of claims requeued
     */
    public function reap(Queue $queue, int $olderThan = 90000, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): int
    {
        $processingList = "{$queue->namespace}.processing.{$queue->name}";
        $now = time();
        $cutoff = $now - $olderThan;
        $requeued = 0;

        $claims = $this->commands->listRange($processingList, $this->commands->listSize($processingList), 0);

        foreach ($claims as $pid) {
            if ($limit !== null && $requeued >= $limit) {
                break;
            }

            if (!\is_string($pid)) {
                continue;
            }

            // The payload expired: the claim is unrecoverable, drop it.
            $job = $this->getJob($queue, $pid);
            if ($job === false) {
                $this->commands->listRemove($processingList, $pid);
                continue;
            }

            if ($job->getTimestamp() > $cutoff) {
                continue;
            }

            if (($maxAttempts !== null && $job->getAttempts() >= $maxAttempts)
                || ($newerThan !== null && $job->getTimestamp() < $now - $newerThan)) {
                $this->commands->listRemove($processingList, $pid);
                $this->commands->leftPush("{$queue->namespace}.dead.{$queue->name}", $pid);
                continue;
            }

            $this->requeue($queue, $job);
            $this->commands->listRemove($processingList, $pid);
            $requeued++;
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
        $this->commands->leftPushArray("{$queue->namespace}.queue.{$queue->name}", $payload);
    }

    private function getJob(Queue $queue, string $pid): Message|false
    {
        $value = $this->commands->get("{$queue->namespace}.jobs.{$queue->name}.{$pid}");

        // get() yields a decoded array or raw JSON depending on the driver;
        // missing/expired jobs come back null or false.
        if (\is_string($value)) {
            $value = json_decode($value, true);
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
