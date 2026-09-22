<?php

declare(strict_types=1);

namespace Utopia\Tests\Scopes;

use Exception;
use Utopia\Pools\Adapter;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

trait PoolTestScope
{
    abstract protected function getAdapter(): Adapter;

    abstract protected function execute(callable $callback): mixed;

    /**
     * @var Pool<string>
     */
    protected Pool $poolObject;

    protected function setUpPool(): void
    {
        $this->poolObject = new Pool($this->getAdapter(), 'test', 5, fn(): string => 'x', timeout: 0.0);
    }

    public function testPoolGetName(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame('test', $this->poolObject->name);
        });
    }

    public function testPoolGetSize(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->size);
        });
    }

    public function testPoolPop(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertSame(4, $this->poolObject->count());

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertSame('x', $connection->resource);

            // Pop remaining 4 connections
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            // Pool should be empty, next pop should throw
            $this->expectException(Exception::class);
            $this->poolObject->pop();
        });
    }

    public function testPoolUse(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());
            $this->poolObject->use(function ($resource): void {
                $this->assertSame(4, $this->poolObject->count());
                $this->assertSame('x', $resource);
            });

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolPush(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertSame(4, $this->poolObject->count());

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertSame('x', $connection->resource);

            $this->assertInstanceOf(Pool::class, $this->poolObject->push($connection));

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolCount(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertSame(4, $this->poolObject->count());

            $this->poolObject->push($connection);

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolReclaim(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(2, $this->poolObject->count());

            $this->poolObject->reclaim();

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolIsEmpty(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(true, $this->poolObject->isEmpty());
        });
    }

    public function testPoolIsFull(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(true, $this->poolObject->isFull());

            $connection = $this->poolObject->pop();

            $this->assertSame(false, $this->poolObject->isFull());

            $this->poolObject->push($connection);

            $this->assertSame(true, $this->poolObject->isFull());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(false, $this->poolObject->isFull());

            $this->poolObject->reclaim();

            $this->assertSame(true, $this->poolObject->isFull());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(false, $this->poolObject->isFull());
        });
    }

    public function testPoolDestroy(): void
    {
        $this->execute(function (): void {
            $i = 0;
            $object = new Pool($this->getAdapter(), 'testDestroy', 2, function () use (&$i): string {
                ++$i;

                return $i <= 2 ? 'x' : 'y';
            }, timeout: 0.0);

            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('x', $connection1->resource);
            $this->assertSame('x', $connection2->resource);

            $object->destroy();

            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('y', $connection1->resource);
            $this->assertSame('y', $connection2->resource);
        });
    }

    public function testPopWaitsAcquireTimeoutThenThrows(): void
    {
        $this->execute(function (): void {
            // timeout is the whole budget: one wait, no retry loop on top.
            $pool = new Pool($this->getAdapter(), 'test-budget', 1, fn(): string => 'x', timeout: 0.25);
            $pool->pop();

            $start = microtime(true);

            try {
                $pool->pop();
                $this->fail('Should have thrown');
            } catch (Exception $e) {
                $this->assertStringContainsString('could not provide a connection', $e->getMessage());
            }

            // Generously bounded: the point is that it is one budget, not
            // attempts x (wait + sleep) as it used to be.
            $this->assertLessThan(3.0, microtime(true) - $start);
        });
    }

    public function testCreationFailureSurfacesToTheCaller(): void
    {
        $this->execute(function (): void {
            // Creation is no longer retried inside the pool, and a failed create
            // does not fall through to a wait. The caller gets init's own
            // exception, untouched, so it keeps the type it needs to act on.
            $calls = 0;
            $pool = new Pool($this->getAdapter(), 'test-create-fails', 1, function () use (&$calls): string {
                ++$calls;
                throw new Exception('connect refused');
            }, timeout: 0.0);

            try {
                $pool->pop();
                $this->fail('Should have thrown');
            } catch (Exception $e) {
                $this->assertSame('connect refused', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }

            $this->assertSame(1, $calls);
            $this->assertSame(1, $pool->count());
        });
    }

    public function testPopReleasesReservedSlotWhenCreationThrowsError(): void
    {
        $this->execute(function (): void {
            // pop() reserves capacity before the connection exists. An Error
            // escaping the catch used to keep that slot, draining the pool one
            // failed pop at a time.
            $pool = new Pool($this->getAdapter(), 'test-error-leak', 2, function (): string {
                throw new \TypeError('Connection init failed');
            }, timeout: 0.0);
            // More attempts than slots, so a kept reservation shows up as lost
            // capacity by the end. Counted rather than using fail() inside the
            // loop, so a missing throw cannot be confused with the caught one.
            $thrown = 0;
            for ($i = 0; $i < 5; ++$i) {
                try {
                    $pool->pop();
                } catch (\Throwable $e) {
                    ++$thrown;
                    // An Error propagates as itself rather than being wrapped.
                    $this->assertInstanceOf(\TypeError::class, $e);
                }
            }

            $this->assertSame(5, $thrown);
            $this->assertSame(2, $pool->count());
        });
    }

    public function testDoubleDestroyDoesNotInflateCapacity(): void
    {
        $this->execute(function (): void {
            // destroy() used to decrement reserved unconditionally, so destroying
            // the same connection twice drove the count below the truth and let the
            // pool create beyond its size.
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-double-destroy', 2, function () use (&$created): string {
                ++$created;

                return 'x';
            }, timeout: 0.0);

            $connection = $pool->pop();
            $pool->destroy($connection);
            $pool->destroy($connection);

            $this->assertSame(2, $pool->count());

            $pool->pop();
            $pool->pop();

            try {
                $pool->pop();
                $this->fail('Should have thrown: the pool is at capacity');
            } catch (Exception) {
                // expected
            }

            // One before the destroys, two after, and never a third slot.
            $this->assertSame(3, $created);
        });
    }

    public function testPoolEmptyErrorIncludesActiveCount(): void
    {
        $this->execute(function (): void {
            $this->setUpPool(); // size 5
            // Pop all 5
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            try {
                $this->poolObject->pop();
                $this->fail('Should have thrown');
            } catch (Exception $e) {
                $this->assertStringContainsString('active 5', $e->getMessage());
            }
        });
    }

    public function testUseDestroysConnectionWhenRecoveryFails(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-on-error', 2, function () use (&$created): \Stringable {
                ++$created;

                return new readonly class ('resource-' . $created, $created === 1) implements \Stringable {
                    public function __construct(private string $name, private bool $failRecovery) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): void
                    {
                        if ($this->failRecovery) {
                            throw new \RuntimeException('Recovery failed');
                        }
                    }
                };
            }, timeout: 0.0);
            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function (\Stringable $resource): void {
                $this->assertSame('resource-2', (string) $resource);
            });
        });
    }

    public function testUseDestroysConnectionWhenRecoveryReturnsFalse(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-on-false-recovery', 2, function () use (&$created): \Stringable {
                ++$created;

                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): bool
                    {
                        return false;
                    }
                };
            }, timeout: 0.0);
            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function (\Stringable $resource): void {
                $this->assertSame('resource-2', (string) $resource);
            });
        });
    }

    public function testUseRecoversAndReusesConnectionWhenRecoverySucceeds(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-recover-and-reuse', 2, function () use (&$created): \Stringable {
                ++$created;

                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): bool
                    {
                        return true;
                    }
                };
            }, timeout: 0.0);
            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $pool->use(function (\Stringable $resource) use (&$created): void {
                $this->assertSame('resource-1', (string) $resource);
                $this->assertSame(1, $created);
            });
        });
    }

    public function testUseDestroysObjectConnectionWithoutRecoveryHooks(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-without-recovery', 2, function () use (&$created): \Stringable {
                ++$created;

                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }
                };
            }, timeout: 0.0);
            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function (\Stringable $resource): void {
                $this->assertSame('resource-2', (string) $resource);
            });
        });
    }

    public function testUseDestroysNativeResourceConnectionAfterCallbackFailure(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-native-resource', 2, function () use (&$created) {
                ++$created;
                $resource = fopen('php://temp', 'r+');
                if ($resource === false) {
                    throw new \RuntimeException('Failed to open stream');
                }

                fwrite($resource, 'resource-' . $created);
                rewind($resource);

                return $resource;
            }, timeout: 0.0);
            try {
                $pool->use(function ($resource): void {
                    $this->assertSame('resource-1', stream_get_contents($resource));
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function ($resource): void {
                $this->assertSame('resource-2', stream_get_contents($resource));
            });
        });
    }

    public function testUseForgetsConnectionWhenDestroyCleanupFails(): void
    {
        $this->execute(function (): void {
            $adapter = new class extends Stack {
                public bool $failSynchronized = false;

                public function synchronized(callable $callback): mixed
                {
                    if ($this->failSynchronized) {
                        $this->failSynchronized = false;
                        throw new \RuntimeException('Lock failed');
                    }

                    return parent::synchronized($callback);
                }
            };

            $created = 0;
            $pool = new Pool($adapter, 'test-forget-on-destroy-failure', 1, function () use (&$created): \Stringable {
                ++$created;

                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }
                };
            }, timeout: 0.0);
            try {
                $pool->use(function (\Stringable $resource) use ($adapter): void {
                    $this->assertSame('resource-1', (string) $resource);
                    $adapter->failSynchronized = true;
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException $exception) {
                $this->assertSame('Callback failed', $exception->getMessage());
            }

            $pool->use(function (\Stringable $resource) use (&$created): void {
                $this->assertSame('resource-2', (string) $resource);
                $this->assertSame(2, $created);
            });
        });
    }

    public function testUsePreservesCallbackExceptionWhenReplacementFails(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-preserve-callback-error', 1, function () use (&$created): \Stringable {
                ++$created;
                if ($created > 1) {
                    throw new \TypeError('Replacement failed');
                }

                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): never
                    {
                        throw new \RuntimeException('Recovery failed');
                    }
                };
            }, timeout: 0.0);
            $error = null;
            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \LogicException('Callback failed');
                });
            } catch (\LogicException $error) {
            }

            $this->assertInstanceOf(\LogicException::class, $error);
            $this->assertSame('Callback failed', $error->getMessage());
            $this->assertSame(1, $pool->count());
        });
    }

    public function testPoolTelemetry(): void
    {
        $this->execute(function (): void {
            $telemetry = new TestTelemetry();
            $this->poolObject = new Pool($this->getAdapter(), 'test', 5, fn(): string => 'x', timeout: 0.0, telemetry: $telemetry);

            $this->assertArrayHasKey('pool.connection.open.count', $telemetry->observableGauges);
            $this->assertArrayHasKey('pool.connection.active.count', $telemetry->observableGauges);
            $this->assertArrayHasKey('pool.connection.idle.count', $telemetry->observableGauges);
            $this->assertArrayHasKey('pool.connection.capacity.count', $telemetry->observableGauges);
            $this->assertArrayNotHasKey('pool.connection.wait_time', $telemetry->histograms);
            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            // Observable gauges report their value at collection time, so read them on demand.
            $read = function (string $name) use ($telemetry): float|int {
                /** @var object{callbacks: array<int, \Closure>} $gauge */
                $gauge = $telemetry->observableGauges[$name];
                $value = 0;
                foreach ($gauge->callbacks as $callback) {
                    $callback(function (float|int $observed) use (&$value): void {
                        $value = $observed;
                    });
                }

                return $value;
            };

            $this->assertSame(5, $this->poolObject->count());

            $connections = [];
            for ($i = 0; $i < 3; ++$i) {
                $connections[] = $this->poolObject->pop();
            }

            $this->assertSame(3, $read('pool.connection.open.count'));
            $this->assertSame(3, $read('pool.connection.active.count'));
            $this->assertSame(0, $read('pool.connection.idle.count'));
            $this->assertSame(3, $read('pool.connection.capacity.count'));

            /** @var object{values: array<int, float|int>} $waitHistogram */
            $waitHistogram = $telemetry->histograms['pool.connection.wait_time'];
            $this->assertCount(3, $waitHistogram->values);
            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            // Reclaim one connection: it returns to the pool as idle.
            $this->poolObject->reclaim(array_pop($connections));

            $this->assertSame(3, $read('pool.connection.open.count'));
            $this->assertSame(2, $read('pool.connection.active.count'));
            $this->assertSame(1, $read('pool.connection.idle.count'));
            $this->assertSame(3, $read('pool.connection.capacity.count'));

            // Reclaim the rest.
            foreach ($connections as $connection) {
                $this->poolObject->reclaim($connection);
            }

            $this->assertSame(3, $read('pool.connection.open.count'));
            $this->assertSame(0, $read('pool.connection.active.count'));
            $this->assertSame(3, $read('pool.connection.idle.count'));
            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testMultiplePoolsShareGaugesButEmitDistinctSeries(): void
    {
        $this->execute(function (): void {
            // Adapters cache observable gauges by name, so every pool that registers
            // 'pool.connection.*.count' shares one instrument. Each pool must still emit its own
            // series; a single-callback gauge would drop all but the last pool to bind.
            $telemetry = new TestTelemetry();

            $alpha = new Pool($this->getAdapter(), 'alpha', 5, fn(): string => 'x', timeout: 0.0, telemetry: $telemetry);
            $beta = new Pool($this->getAdapter(), 'beta', 5, fn(): string => 'x', timeout: 0.0, telemetry: $telemetry);

            $alpha->pop();
            $beta->pop();
            $beta->pop();

            /** @var object{callbacks: array<int, \Closure>} $gauge */
            $gauge = $telemetry->observableGauges['pool.connection.active.count'];

            $series = [];
            foreach ($gauge->callbacks as $callback) {
                $callback(function (float|int $value, iterable $attributes = []) use (&$series): void {
                    $pool = null;
                    foreach ($attributes as $key => $attr) {
                        if ($key === 'pool' && \is_string($attr)) {
                            $pool = $attr;
                        }
                    }
                    $this->assertIsString($pool);
                    $series[$pool] = $value;
                });
            }

            $this->assertSame(['alpha' => 1, 'beta' => 2], $series);
        });
    }

    public function testPoolUseDurationTelemetryIsCreatedOnFirstUse(): void
    {
        $this->execute(function (): void {
            $telemetry = new TestTelemetry();
            $this->poolObject = new Pool($this->getAdapter(), 'test', 5, fn(): string => 'x', timeout: 0.0, telemetry: $telemetry);

            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            $this->poolObject->use(function ($resource): void {
                $this->assertSame('x', $resource);
            });

            $this->assertArrayHasKey('pool.connection.use_time', $telemetry->histograms);
            /** @var object{values: array<int, float|int>} $useHistogram */
            $useHistogram = $telemetry->histograms['pool.connection.use_time'];
            $this->assertCount(1, $useHistogram->values);
        });
    }

    /**
     * A pool of resources that count their own ticks, so a sweep is observable.
     *
     * @param  \Closure(): void|null  $onTick  Runs inside tick(), to fail one.
     * @return Pool<TickingResource>
     */
    private function tickingPool(string $name, int $size, ?\Closure $onTick = null): Pool
    {
        $created = 0;

        return new Pool($this->getAdapter(), $name, $size, function () use (&$created, $onTick): TickingResource {
            ++$created;

            return new TickingResource($created, $onTick);
        }, timeout: 0.0);
    }

    public function testMaintainTicksEveryIdleResource(): void
    {
        $this->execute(function (): void {
            $pool = $this->tickingPool('test-maintain-ticks', 3);

            // Create three, then hand them all back so the whole set is idle.
            $connections = [$pool->pop(), $pool->pop(), $pool->pop()];
            foreach ($connections as $connection) {
                $pool->reclaim($connection);
            }

            $pool->maintain();

            foreach ($connections as $connection) {
                $this->assertSame(1, $connection->resource->ticks);
            }

            // The sweep is not a checkout: every resource is still available.
            $this->assertSame(3, $pool->count());
        });
    }

    public function testMaintainLeavesCheckedOutResourcesUntouched(): void
    {
        $this->execute(function (): void {
            $pool = $this->tickingPool('test-maintain-active', 2);

            $idle = $pool->pop();
            $active = $pool->pop();
            $pool->reclaim($idle);

            $pool->maintain();

            // The active one has a caller driving it; that is the traffic the
            // tick substitutes for, and ticking it would race that caller.
            $this->assertSame(1, $idle->resource->ticks);
            $this->assertSame(0, $active->resource->ticks);
        });
    }

    public function testMaintainIgnoresResourcesThatCannotTick(): void
    {
        $this->execute(function (): void {
            $pool = new Pool($this->getAdapter(), 'test-maintain-no-tick', 2, fn(): string => 'x', timeout: 0.0);

            $connection = $pool->pop();
            $pool->reclaim($connection);

            // A string resource has no tick(); the sweep must be a no-op rather
            // than an error, so a host can call it on every pool it owns.
            $pool->maintain();

            $this->assertSame(2, $pool->count());
            $pool->use(function ($resource): void {
                $this->assertSame('x', $resource);
            });
        });
    }

    public function testMaintainDiscardsResourceWhoseTickThrows(): void
    {
        $this->execute(function (): void {
            $pool = $this->tickingPool('test-maintain-discard', 2, function (): void {
                throw new \RuntimeException('server closed the connection');
            });

            $dead = $pool->pop();
            $pool->reclaim($dead);

            $pool->maintain();

            // The dead resource is gone but its slot is not: the pool must not
            // shrink by one on every sweep.
            $this->assertSame(2, $pool->count());

            // And the next caller gets a freshly created resource, not the one
            // that just failed to keep itself alive.
            $deadSerial = $dead->resource->serial;
            $pool->use(function (TickingResource $resource) use ($deadSerial): void {
                $this->assertNotSame($deadSerial, $resource->serial);
            });
        });
    }
}
