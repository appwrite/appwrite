<?php

declare(strict_types=1);

namespace Utopia\Tests\Scopes;

use Utopia\Pools\Adapter;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

trait ConnectionTestScope
{
    abstract protected function getAdapter(): Adapter;

    abstract protected function execute(callable $callback): mixed;

    /**
     * A connection only exists as something checked out of a pool, so there is
     * no standalone construction to test.
     *
     * @return Connection<string>
     */
    private function checkedOutConnection(string $name = 'test'): Connection
    {
        return new Pool($this->getAdapter(), $name, 2, fn(): string => 'x', timeout: 0.0)->pop();
    }

    public function testConnectionIdIsNamespacedByPool(): void
    {
        $this->execute(function (): void {
            $connection = $this->checkedOutConnection('alpha');

            $this->assertStringStartsWith('alpha-', $connection->id);
        });
    }

    public function testConnectionExposesItsResource(): void
    {
        $this->execute(function (): void {
            $this->assertSame('x', $this->checkedOutConnection()->resource);
        });
    }

    public function testConnectionReclaim(): void
    {
        $this->execute(function (): void {
            $pool = new Pool($this->getAdapter(), 'test', 2, fn(): string => 'x', timeout: 0.0);

            $this->assertSame(2, $pool->count());

            $connection1 = $pool->pop();

            $this->assertSame(1, $pool->count());

            $connection2 = $pool->pop();

            $this->assertSame(0, $pool->count());

            $connection1->reclaim();

            $this->assertSame(1, $pool->count());

            $connection2->reclaim();

            $this->assertSame(2, $pool->count());
        });
    }

    public function testConnectionDestroy(): void
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

            $connection1->destroy();
            $connection2->destroy();

            // Capacity is freed immediately; replacements are created lazily by the
            // next pop() rather than inside destroy().
            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('y', $connection1->resource);
            $this->assertSame('y', $connection2->resource);
        });
    }

    public function testDroppingAPoolFreesTheResourcesItWasHolding(): void
    {
        $this->execute(function (): void {
            TrackedResource::$freed = 0;

            (function (): void {
                $pool = new Pool($this->getAdapter(), 'lifetime', 3, fn(): TrackedResource => new TrackedResource(), timeout: 1.0);

                // Check every slot out at once so three distinct resources exist,
                // then hand them all back: the pool is left holding them idle in
                // its adapter's storage, which is where they used to become
                // unreachable but uncollectable.
                $connections = [$pool->pop(), $pool->pop(), $pool->pop()];

                foreach ($connections as $connection) {
                    $connection->reclaim();
                }
            })();

            gc_collect_cycles();

            // A strong connection-to-pool reference closes a cycle that the
            // collector cannot trace, because with the Swoole adapter one edge
            // runs through a Coroutine\Channel and lives in C memory. Every pool
            // and pooled resource then survived for the life of the process.
            $this->assertSame(3, TrackedResource::$freed);
        });
    }

    public function testAConnectionOutlivesItsPoolWithoutReclaimingFailing(): void
    {
        $this->execute(function (): void {
            // The pool is unreferenced the moment pop() returns, so a weakly held
            // owner is already gone by the time the connection is handed back.
            $connection = $this->checkedOutConnection('orphan');

            gc_collect_cycles();

            $connection->reclaim();
            $connection->destroy();

            // Returning capacity to a pool that no longer exists is a no-op, but
            // the connection still owns its resource.
            $this->assertSame('x', $connection->resource);
        });
    }
}

final class TrackedResource
{
    public static int $freed = 0;

    public function __destruct()
    {
        ++self::$freed;
    }
}
