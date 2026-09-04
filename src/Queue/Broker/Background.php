<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Utopia\Queue\Publisher\Asynchronous;
use Utopia\Queue\Publisher\BufferFullException;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * Wraps a synchronous publisher and adds asynchronous, background dispatch on
 * top of a Swoole coroutine — so it satisfies both the Synchronous and
 * Asynchronous contracts.
 *
 * enqueue() pushes the publish onto a bounded channel and returns; one or more
 * reader coroutines loop over the channel and delegate each dispatch to the
 * wrapped synchronous publisher. The channel capacity is the back-pressure
 * bound — once it fills, enqueue() blocks the producing coroutine until a reader
 * drains a slot, so a slow broker throttles producers instead of piling up
 * unbounded work. $timeout caps that wait: enqueue() throws BufferFullException
 * if no slot frees within it; -1 (the default) waits indefinitely.
 *
 * Set $maxBatchInterval and $maxBatchSize together to coalesce consecutive
 * messages for the same queue and priority into enqueueMany() calls. A partial
 * batch is flushed when its oldest message reaches the interval, and shutdown
 * always flushes accepted messages before the readers exit.
 *
 * $coroutines sets how many reader coroutines dispatch concurrently. Values above
 * 1 only make sense when the wrapped publisher tolerates concurrent use across
 * coroutines: a single-connection broker (e.g. a bare Redis) must not be shared
 * — wrap a connection Pool instead, so each dispatch leases its own connection.
 * More than one coroutine also gives up FIFO dispatch order.
 *
 * Telemetry (no-op by default) reports the buffer depth as an observable gauge.
 * Dispatch counts and failures aren't metered here — the wrapped synchronous
 * publisher already sees every publish and can report those itself.
 *
 * publish() bypasses the channel and delegates synchronously.
 */
class Background implements Synchronous, Asynchronous
{
    private readonly Channel $channel;

    private readonly WaitGroup $waitGroup;

    private readonly int $coroutines;

    private bool $started = false;

    private int $readers = 0;

    private int $activeEnqueues = 0;

    public function __construct(
        private readonly Synchronous $publisher,
        int $capacity = 512,
        int $coroutines = 1,
        private readonly float $timeout = -1,
        Telemetry $telemetry = new NoTelemetry(),
        private readonly ?float $maxBatchInterval = null,
        private readonly ?int $maxBatchSize = null,
    ) {
        if (($maxBatchInterval === null) !== ($maxBatchSize === null)) {
            throw new \InvalidArgumentException('maxBatchInterval and maxBatchSize must be configured together.');
        }

        if ($maxBatchInterval !== null && $maxBatchInterval <= 0) {
            throw new \InvalidArgumentException('maxBatchInterval must be greater than zero.');
        }

        if ($maxBatchSize !== null && $maxBatchSize < 1) {
            throw new \InvalidArgumentException('maxBatchSize must be at least one.');
        }

        $this->channel = new Channel(max(1, $capacity));
        $this->waitGroup = new WaitGroup();
        $this->coroutines = max(1, $coroutines);

        $telemetry->createObservableGauge(
            'messaging.publisher.buffer.depth',
            '{message}',
            'Publishes buffered awaiting background dispatch.',
        )->observe(function (callable $observe): void {
            $observe($this->channel->length(), []);
        });
    }

    /**
     * Spawn the reader coroutines that drain the channel into the wrapped
     * publisher. Call once from within a coroutine runtime; until then
     * enqueue() publishes synchronously.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (Coroutine::getCid() === -1) {
            throw new \RuntimeException('Background publisher must be started inside a coroutine runtime.');
        }

        $this->started = true;

        for ($i = 0; $i < $this->coroutines; $i++) {
            $this->waitGroup->add();

            $cid = Coroutine::create(function (): void {
                try {
                    $this->runReader();
                } finally {
                    $this->waitGroup->done();
                }
            });

            if ($cid === false) {
                $this->waitGroup->done();
                $this->started = false;

                for ($reader = 0; $reader < $this->readers; $reader++) {
                    $this->channel->push(null);
                }

                $this->waitGroup->wait();
                $this->readers = 0;

                throw new \RuntimeException('Failed to create a background publisher coroutine.');
            }

            $this->readers++;
        }
    }

    /**
     * Drain the channel and stop the readers, blocking until they have finished.
     * Messages already enqueued are published before the readers exit.
     */
    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        // Stop accepting background work before placing sentinels. Enqueues
        // already blocked on a full channel are allowed to finish first, so no
        // task can land behind a sentinel and be silently abandoned.
        $this->started = false;
        while ($this->activeEnqueues > 0) {
            Coroutine::sleep(0.001);
        }

        for ($i = 0; $i < $this->readers; $i++) {
            $this->channel->push(null); // one sentinel per reader; pop() returns non-Closure → loop ends
        }

        $this->waitGroup->wait();
        $this->readers = 0;
    }

    /**
     * Publish synchronously, blocking until the broker accepts the message.
     */
    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        return $this->publisher->publish($queue, $payload, $priority);
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        return $this->publisher->enqueueMany($queue, $payloads, $priority);
    }

    /**
     * Hand the publish to the background reader via the channel. Blocks when the
     * channel is full (back pressure), up to the configured timeout, then throws
     * BufferFullException if no slot frees in time. Falls back to a synchronous
     * publish when no reader loop is running.
     *
     * @throws BufferFullException when the buffer stays full past the timeout.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): void
    {
        if (!$this->started || Coroutine::getCid() === -1) {
            $this->publish($queue, $payload, $priority);

            return;
        }

        $this->activeEnqueues++;

        try {
            $accepted = $this->channel->push([
                'queue' => $queue,
                'payload' => $payload,
                'priority' => $priority,
                'enqueuedAt' => microtime(true),
            ], $this->timeout);
        } finally {
            $this->activeEnqueues--;
        }

        if ($accepted === false) {
            throw new BufferFullException('Publisher buffer full; enqueue timed out.');
        }
    }

    private function runReader(): void
    {
        $pending = null;

        while (true) {
            $task = $pending ?? $this->channel->pop();
            $pending = null;

            if (!\is_array($task)) {
                return;
            }

            if ($this->maxBatchInterval === null || $this->maxBatchSize === null) {
                $this->dispatch($task['queue'], [$task['payload']], $task['priority'], batched: false);
                continue;
            }

            $queue = $task['queue'];
            $priority = $task['priority'];
            $payloads = [$task['payload']];
            $deadline = $task['enqueuedAt'] + $this->maxBatchInterval;
            $stopping = false;

            while (\count($payloads) < $this->maxBatchSize) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }

                $next = $this->channel->pop($remaining);
                if ($next === false) {
                    break;
                }

                if (!\is_array($next)) {
                    $stopping = true;
                    break;
                }

                if ($next['queue']->name !== $queue->name
                    || $next['queue']->namespace !== $queue->namespace
                    || $next['priority'] !== $priority) {
                    $pending = $next;
                    break;
                }

                $payloads[] = $next['payload'];
            }

            $this->dispatch($queue, $payloads, $priority, batched: true);

            if ($stopping) {
                return;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function dispatch(Queue $queue, array $payloads, bool $priority, bool $batched): void
    {
        try {
            $published = $batched
                ? $this->publisher->enqueueMany($queue, $payloads, $priority)
                : $this->publisher->publish($queue, $payloads[0], $priority);

            if (!$published) {
                error_log('Background queue publisher failed to publish a message.');
            }
        } catch (\Throwable $error) {
            // Fire-and-forget: no producer to surface to, so log and move on.
            error_log('Uncaught error while publishing queue message: ' . $error->getMessage());
        }
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        $this->publisher->retry($queue, $limit);
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->publisher->getQueueSize($queue, $failedJobs);
    }
}
