<?php

declare(strict_types=1);

namespace Utopia\Pools;

use Exception;
use InvalidArgumentException;
use Utopia\Pools\Adapter as PoolAdapter;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;

/**
 * A fixed-capacity pool of lazily created resources.
 *
 * Configuration is constructor-only, and there are no retry knobs. `timeout`
 * bounds how long pop() waits for a connection to become available; creating
 * one takes as long as `init` takes, because the pool cannot interrupt a
 * blocking connect. Give `init` its own connect timeout if you need the total
 * bounded.
 *
 * If creation fails the exception propagates untouched. Retrying a failed
 * connect is `init`'s business, because only the caller knows whether the
 * failure is worth retrying and on what schedule — compose
 * `utopia-php/circuit-breaker` there rather than counting attempts here.
 *
 * @template TResource
 */
class Pool
{
    /**
     * Connections currently checked out, by connection id.
     *
     * @var array<string, Connection<TResource>>
     */
    private array $active = [];

    /**
     * Capacity accounted for: resources that exist, plus creations in flight.
     * Never exceeds $size.
     */
    private int $reserved = 0;

    /**
     * When each checked-out connection was requested, so the pool can measure
     * use time itself instead of callers passing timestamps back in.
     *
     * @var array<string, float>
     */
    private array $checkedOutAt = [];

    private readonly Histogram $waitDuration;

    private readonly Histogram $useDuration;

    /** @var array<non-empty-string, int|string> */
    private readonly array $telemetryAttributes;

    /**
     * @param  PoolAdapter  $adapter  Storage and synchronisation for idle resources.
     * @param  string  $name  Identifies the pool in errors, connection ids and telemetry.
     * @param  int  $size  Maximum resources in existence at once.
     * @param  \Closure(): TResource  $init  Creates one resource. Own any retry policy here.
     * @param  float  $timeout  Seconds pop() may wait for an available connection.
     *                          Excludes time spent inside $init, which the pool cannot bound.
     */
    public function __construct(
        private readonly PoolAdapter $adapter,
        public readonly string $name,
        public readonly int $size,
        private readonly \Closure $init,
        public readonly float $timeout,
        ?Telemetry $telemetry = null,
    ) {
        if ($size < 1) {
            throw new InvalidArgumentException("Pool '{$name}' size must be at least 1, got {$size}.");
        }

        if ($timeout < 0) {
            throw new InvalidArgumentException("Pool '{$name}' timeout cannot be negative, got {$timeout}.");
        }

        // Start empty: resources are created on demand, never pre-filled.
        $this->adapter->initialize($size);

        $telemetry ??= new NoTelemetry();
        $advisory = ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]];
        $this->waitDuration = $telemetry->createHistogram(name: 'pool.connection.wait_time', unit: 's', advisory: $advisory);
        $this->useDuration = $telemetry->createHistogram(name: 'pool.connection.use_time', unit: 's', advisory: $advisory);
        $this->telemetryAttributes = ['pool' => $name, 'size' => $size];

        // Connection counts are gauges: only their value at export time matters, so observe
        // them lazily at collection rather than recording on every pop/push/reclaim.
        $this->observeGauge($telemetry, 'pool.connection.active.count', fn(): int => \count($this->active));
        $this->observeGauge($telemetry, 'pool.connection.idle.count', fn(): int => $this->adapter->count());
        $this->observeGauge($telemetry, 'pool.connection.open.count', fn(): int => \count($this->active) + $this->adapter->count());
        $this->observeGauge($telemetry, 'pool.connection.capacity.count', fn(): int => $this->reserved);
    }

    /**
     * Register a connection-count observation on a (name-shared) gauge.
     *
     * @param  callable(): (float|int)  $sample
     */
    private function observeGauge(Telemetry $telemetry, string $name, callable $sample): void
    {
        $telemetry->createObservableGauge($name)
            ->observe(fn(callable $observe) => $observe($sample(), $this->telemetryAttributes));
    }

    /**
     * Execute a callback with a managed connection.
     *
     * The connection is returned to the pool afterwards, or discarded if the
     * callback threw. This is the intended entry point; pop() and push() are
     * exposed for callers that must manage the lifetime themselves.
     *
     * @template T
     *
     * @param  callable(TResource): T  $callback  Receives the connection resource.
     * @return T
     */
    public function use(callable $callback): mixed
    {
        $connection = null;
        $failed = false;

        try {
            $connection = $this->pop();

            return $callback($connection->resource);
        } catch (\Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            if ($connection instanceof \Utopia\Pools\Connection) {
                $this->release($connection, $failed);
            }
        }
    }

    /**
     * Return a connection after use, reclaiming it or discarding it if the
     * caller's work failed and the resource cannot be recovered.
     *
     * @param  Connection<TResource>  $connection
     */
    public function release(Connection $connection, bool $failed = false): static
    {
        if (! $failed) {
            return $this->reclaim($connection);
        }

        if ($this->recover($connection)) {
            try {
                return $this->reclaim($connection);
            } catch (\Throwable) {
                try {
                    return $this->destroy($connection);
                } catch (\Throwable) {
                    return $this->forget($connection);
                }
            }
        }

        try {
            return $this->destroy($connection);
        } catch (\Throwable) {
            return $this->forget($connection);
        }
    }

    /**
     * Last-resort cleanup when destroy() itself fails: the connection must never
     * stay tracked as active, or its slot is lost to the pool forever.
     *
     * @param  Connection<TResource>  $connection
     */
    private function forget(Connection $connection): static
    {
        $untrack = function () use ($connection): void {
            if (isset($this->active[$connection->id])) {
                unset($this->active[$connection->id]);
                unset($this->checkedOutAt[$connection->id]);
                --$this->reserved;
            }
        };

        try {
            $this->adapter->synchronized($untrack);
        } catch (\Throwable) {
            $untrack();
        }
        $this->adapter->unblock();

        return $this;
    }

    /**
     * Ask a resource to make itself reusable after a failure.
     *
     * @param  Connection<TResource>  $connection
     */
    private function recover(Connection $connection): bool
    {
        $resource = $connection->resource;

        if (! \is_object($resource)) {
            return ! \is_resource($resource);
        }

        try {
            $recovered = false;

            if (method_exists($resource, 'reset')) {
                $recovered = true;
                if ($resource->reset() === false) {
                    return false;
                }
            }

            if (method_exists($resource, 'reconnect')) {
                $recovered = true;
                if ($resource->reconnect() === false) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return $recovered;
    }

    /**
     * Ask every idle resource to run its own upkeep.
     *
     * A pooled connection nobody checks out is not a connection nothing happens
     * to: the server on the other end is still counting silence, and protocols
     * that reap an idle peer (NATS closes after two missed pings) hand the next
     * caller a socket that is already gone. The resource knows how to keep
     * itself alive; what it lacks is a clock. The pool has no clock either, so
     * a host that wants idle resources kept alive calls this on an interval
     * shorter than the shortest server-side deadline it is holding.
     *
     * Duck-typed on `tick()` for the same reason {@see self::recover()} probes
     * for `reset()`/`reconnect()`: pools depends on no driver, so the capability
     * is discovered rather than declared. Resources without one are left alone,
     * which is what makes this safe to call on every pool a host owns.
     *
     * Resources are taken and returned one at a time rather than under a single
     * lock, because a tick writes to the network and holding the pool's lock
     * across that would stall every pop() for the duration. The cost is that a
     * resource checked out mid-sweep is simply not ticked this round — it has an
     * active caller, which is the traffic the tick exists to substitute for.
     */
    public function maintain(): static
    {
        /** @var list<Connection<TResource>> $taken */
        $taken = [];

        try {
            // Drain before ticking. Adapters are free to choose their own order
            // — Stack is LIFO, Swoole's channel is FIFO — so popping and pushing
            // one at a time hands the same resource straight back on a LIFO
            // adapter and leaves every other one unswept.
            for ($remaining = $this->adapter->count(); $remaining > 0; --$remaining) {
                $connection = $this->adapter->pop(0.0);

                if (! $connection instanceof Connection) {
                    break; // a concurrent pop() drained the idle set ahead of us
                }

                $taken[] = $connection;
            }

            // foreach iterates a snapshot, so dropping each entry as it is dealt
            // with leaves $taken holding exactly what the finally still owes.
            foreach ($taken as $index => $connection) {
                unset($taken[$index]);

                try {
                    $resource = $connection->resource;

                    if (\is_object($resource) && method_exists($resource, 'tick')) {
                        $resource->tick();
                    }
                } catch (\Throwable) {
                    // A resource that cannot keep itself alive must not go back
                    // on the idle set: returning it hands the failure to the
                    // next caller as a mid-request error instead of a connect.
                    $this->releaseIdleSlot();

                    continue;
                }

                // Returned as soon as its own tick is done rather than at the
                // end, so the idle set refills while the sweep is still running.
                $this->adapter->push($connection);
            }
        } finally {
            // A resource stranded outside the pool is capacity lost for the
            // life of the process, so an interrupted sweep still hands back
            // everything it is holding.
            foreach ($taken as $connection) {
                $this->adapter->push($connection);
            }
        }

        return $this;
    }

    /**
     * Give back the capacity of a connection that was dropped from the idle set.
     *
     * {@see self::destroy()} cannot serve here: it releases capacity only for
     * connections in $active, and an idle one by definition is not. The
     * resource still exists though, so its slot is still counted in $reserved
     * and has to be given back or the pool shrinks by one every sweep.
     */
    private function releaseIdleSlot(): void
    {
        $this->adapter->synchronized(function (): void {
            --$this->reserved;
        });
        $this->adapter->unblock();
    }

    /**
     * Take a connection, creating one if there is spare capacity and otherwise
     * waiting up to timeout for one to come back.
     *
     * @return Connection<TResource>
     *
     * @throws Exception When no connection becomes available within the budget.
     */
    public function pop(): Connection
    {
        $start = microtime(true);
        $deadline = $start + $this->timeout;

        try {
            do {
                $slot = $this->adapter->synchronized(function (): bool {
                    if ($this->adapter->count() === 0 && $this->reserved < $this->size) {
                        ++$this->reserved;

                        return true;
                    }

                    return false;
                });

                if ($slot === true) {
                    // The slot is reserved before the resource exists, so every path out
                    // of this block has to release it or the capacity is lost for the
                    // lifetime of the process.
                    $handedOver = false;

                    try {
                        $connection = $this->createConnection();
                        $this->track($connection, $start);
                        $handedOver = true;

                        // Creation failures propagate untouched rather than falling
                        // through to a wait. Waiting for someone else's connection after
                        // our own create failed is a retry in disguise, and callers keep
                        // the original exception type to act on.
                        return $connection;
                    } finally {
                        if (! $handedOver) {
                            $this->adapter->synchronized(function (): void {
                                --$this->reserved;
                            });
                            $this->adapter->unblock();
                        }
                    }
                }

                $connection = $this->adapter->pop(max(0.0, $deadline - microtime(true)));

                if ($connection instanceof Connection) {
                    $this->track($connection, $start);

                    return $connection;
                }

                // Empty-handed. That is not the same as out of time: capacity may
                // have been freed by a caller that discarded its connection rather
                // than returning one, and this acquisition can now create its own.
                // Re-evaluate against the SAME deadline — the budget the caller
                // asked for is never extended by looping.
                if (microtime(true) >= $deadline) {
                    break;
                }
            } while (true);

            throw new Exception(\sprintf(
                "Pool '%s' could not provide a connection within %ss (size %d, active %d, idle %d)",
                $this->name,
                $this->timeout,
                $this->size,
                \count($this->active),
                $this->adapter->count(),
            ));
        } finally {
            $this->waitDuration->record(microtime(true) - $start, $this->telemetryAttributes);
        }
    }

    /**
     * @param  Connection<TResource>  $connection
     */
    private function track(Connection $connection, float $requestedAt): void
    {
        $this->adapter->synchronized(function () use ($connection, $requestedAt): void {
            $this->active[$connection->id] = $connection;
            $this->checkedOutAt[$connection->id] = $requestedAt;
        });
    }

    /**
     * Record how long a connection was out, once, on whichever path returns it.
     */
    private function recordUse(string $id): void
    {
        if (! isset($this->checkedOutAt[$id])) {
            return;
        }

        $this->useDuration->record(microtime(true) - $this->checkedOutAt[$id], $this->telemetryAttributes);
        unset($this->checkedOutAt[$id]);
    }

    /**
     * @return Connection<TResource>
     */
    private function createConnection(): Connection
    {
        return new Connection($this->name . '-' . uniqid(), ($this->init)(), $this);
    }

    /**
     * Hand a connection back to the idle set without inspecting it.
     *
     * @param  Connection<TResource>  $connection
     */
    public function push(Connection $connection): static
    {
        $this->adapter->synchronized(function () use ($connection): void {
            $this->recordUse($connection->id);
            // A push can resume a waiting borrower inline. Its track() must wait
            // until the previous checkout has been removed from active ownership.
            $this->adapter->push($connection);
            unset($this->active[$connection->id]);
        });

        return $this;
    }

    /**
     * Connections a caller could still obtain: idle plus not yet created.
     */
    public function count(): int
    {
        return $this->adapter->count() + ($this->size - $this->reserved);
    }

    /**
     * @param  Connection<TResource>|null  $connection  Reclaims every active connection when null.
     */
    public function reclaim(?Connection $connection = null): static
    {
        if ($connection instanceof \Utopia\Pools\Connection) {
            return $this->push($connection);
        }

        foreach ($this->active as $active) {
            $this->push($active);
        }

        return $this;
    }

    /**
     * Discard a connection and free its capacity. The replacement is created
     * lazily by the next pop(), so destroying never blocks on a connect and
     * never fails for reasons unrelated to the connection being discarded.
     *
     * @param  Connection<TResource>|null  $connection  Destroys every active connection when null.
     */
    public function destroy(?Connection $connection = null): static
    {
        if (!$connection instanceof \Utopia\Pools\Connection) {
            foreach (array_values($this->active) as $active) {
                $this->destroy($active);
            }

            return $this;
        }

        $this->recordUse($connection->id);

        // Only release capacity this pool is actually holding. Destroying the same
        // connection twice, or one belonging to another pool, must not drive
        // reserved below the truth and let the pool exceed its size.
        $this->adapter->synchronized(function () use ($connection): void {
            if (! isset($this->active[$connection->id])) {
                return;
            }

            unset($this->active[$connection->id]);
            --$this->reserved;
        });
        $this->adapter->unblock();

        return $this;
    }

    /**
     * No connection can be obtained without one being returned first.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Every connection the pool could hand out is available.
     */
    public function isFull(): bool
    {
        return $this->count() === $this->size;
    }
}
