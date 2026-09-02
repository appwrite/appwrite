<?php

namespace Utopia\Queue\Broker;

use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class Pool implements Publisher, Consumer
{
    public function __construct(
        private ?UtopiaPool $publisher = null,
        private ?UtopiaPool $consumer = null,
    ) {}

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function retry(Queue $queue, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): void
    {
        $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    /**
     * {@see Redis::reap()} — requires the pooled publisher to be a broker that
     * implements it.
     */
    public function reap(Queue $queue, int $olderThan = 90000, ?int $limit = null, ?int $maxAttempts = null, ?int $newerThan = null): int
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->delegate($this->publisher, __FUNCTION__, \func_get_args());
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        return $this->delegate($this->consumer, __FUNCTION__, \func_get_args());
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->delegate($this->consumer, __FUNCTION__, \func_get_args());
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->delegate($this->consumer, __FUNCTION__, \func_get_args());
    }

    /**
     * Tell the broker the handler is still working on this message.
     *
     * Present so a pooled consumer does not silently lose ack extension.
     * {@see \Utopia\Queue\Adapter\Swoole::withAckExtension()} probes the
     * consumer it was handed, which for a pooled worker is this class and not
     * the broker underneath -- so without these two methods the heartbeat found
     * nothing to call and quietly degraded to the behaviour it exists to fix:
     * ackWait back to being a hard ceiling on job duration, with a redelivery
     * running concurrently with the attempt still in progress.
     *
     * Capability is probed per lease rather than declared, because the pool may
     * hold a broker with no notion of extension (Redis), and this class is the
     * consumer for those too.
     *
     * Correct while the pool leases the same broker to a message's receive()
     * and its extend() -- true at size 1, which is the documented wiring, and
     * the same assumption commit() and reject() already make. At a larger size
     * a lease can land on a broker that never held this message, whose in-flight
     * map has no entry for it; {@see \Utopia\Queue\Broker\Nats::extend()} is
     * silent in that case by design.
     */
    public function extend(Queue $queue, Message $message): void
    {
        $this->consumer?->use(function (Publisher|Consumer $adapter) use ($queue, $message): void {
            $extend = [$adapter, 'extend'];

            if (\is_callable($extend)) {
                $extend($queue, $message);
            }
        });
    }

    /**
     * How often {@see self::extend()} should be called, or null when the leased
     * broker cannot extend at all.
     *
     * Null rather than a fallback number: there is no interval that is safe to
     * guess. One longer than the real ackWait extends nothing while looking like
     * it does, which is the same silent degradation in a more convincing
     * costume. The caller skips the heartbeat entirely instead.
     */
    public function extendInterval(): ?float
    {
        return $this->consumer?->use(function (Publisher|Consumer $adapter): ?float {
            $interval = [$adapter, 'extendInterval'];

            return \is_callable($interval) ? (float) $interval() : null;
        });
    }

    /**
     * Run idle upkeep across both pools.
     *
     * Safe to call at any time, including while a receive loop is running: the
     * sweep reaches only resources sitting idle in the pool, never the one a
     * caller currently holds. That is the whole difference from calling
     * {@see Nats::tick()} directly, which needs exclusive access.
     *
     * A pooled broker is the case the keepalive exists for — a publisher slot
     * can sit untouched for longer than the server's ping deadline and be
     * reaped without anyone noticing until the next publish.
     */
    public function maintain(): void
    {
        $this->publisher?->maintain();

        // Distinct pools; a broker configured with only one leaves the other null.
        if ($this->consumer instanceof UtopiaPool && $this->consumer !== $this->publisher) {
            $this->consumer->maintain();
        }
    }

    public function close(): void
    {
        // TODO: Implement closing all connections in the pool
    }

    /**
     * @param array<mixed> $args
     */
    protected function delegate(?UtopiaPool $pool, string $method, array $args): mixed
    {
        return $pool?->use(fn(Publisher|Consumer $adapter) => $adapter->$method(...$args));
    }
}
