<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Adapter\Pool;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;

/**
 * A connection records a timeout and applies it to the statements it builds
 * afterwards; it never asks the server for one. The pooled adapter must
 * therefore hold the timeout itself and apply it to each connection as it is
 * checked out, rather than checking one out to carry the value.
 */
class PoolTimeoutTest extends TestCase
{
    public function testSetTimeoutDoesNotOpenAConnection(): void
    {
        $opened = 0;
        $adapter = new Pool(new UtopiaPool(new Stack(), 'unreachable', 1, function () use (&$opened) {
            $opened++;

            throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        }, timeout: 0.0));

        $adapter->setTimeout(300000);

        $this->assertSame(0, $opened);
        $this->assertSame(300000, $adapter->getTimeout());
    }

    /**
     * The pool outlives the handle that configured it, so a connection carries
     * whatever the last handle to hold it asked for. A timeout applied once, at
     * the moment it was set, leaves every later checkout unbounded.
     */
    public function testTimeoutIsAppliedToEveryCheckout(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(300000);
        $adapter->getSupportForTimeouts();

        $this->assertSame([Database::EVENT_ALL => 300000], $connection->timeouts);

        $connection->timeouts = [];
        $adapter->getSupportForTimeouts();

        $this->assertSame([Database::EVENT_ALL => 300000], $connection->timeouts);
    }

    public function testClearedTimeoutIsClearedOnEveryCheckout(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(300000);
        $adapter->getSupportForTimeouts();
        $adapter->clearTimeout(Database::EVENT_ALL);
        $adapter->getSupportForTimeouts();

        $this->assertSame([], $connection->timeouts);
        $this->assertSame(0, $connection->getTimeout());
        $this->assertSame(0, $adapter->getTimeout());
    }

    public function testPerEventTimeoutReachesTheConnection(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(300000);
        $adapter->setTimeout(5000, Database::EVENT_DOCUMENT_READ);
        $adapter->getSupportForTimeouts();

        $this->assertSame([
            Database::EVENT_DOCUMENT_READ => 5000,
            Database::EVENT_ALL => 300000,
        ], $connection->timeouts, 'The global timeout is applied last so the connection ends on the scalar the pool reports');
        $this->assertSame(300000, $connection->getTimeout());
    }

    /**
     * The concrete adapters keep one timeout scalar, which Postgres writes into
     * `SET statement_timeout` and Mongo into `maxTimeMS` for every statement.
     * Forwarding a per-event clear verbatim zeroed that scalar, so clearing the
     * timeout on one event left everything else running unbounded.
     */
    public function testClearingOneEventLeavesTheGlobalTimeoutInPlace(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(300000);
        $adapter->setTimeout(5000, Database::EVENT_DOCUMENT_READ);
        $adapter->clearTimeout(Database::EVENT_DOCUMENT_READ);
        $adapter->getSupportForTimeouts();

        $this->assertSame([Database::EVENT_ALL => 300000], $connection->timeouts);
        $this->assertSame(300000, $connection->getTimeout(), 'Postgres and Mongo bound every statement by this scalar, so clearing one event must not zero it');
        $this->assertSame(300000, $adapter->getTimeout());
    }

    /**
     * A pool is shared: the same connection is handed to a handle built for a
     * long migration with no timeout and to a request handle bounded at 5s. A
     * handle holding no timeout must reset what it is given, or it inherits the
     * bound the last holder left and a migration is killed mid-run.
     */
    public function testHandleWithNoTimeoutResetsTheConnectionItIsGiven(): void
    {
        $connection = new TimeoutRecordingMemory();
        $pool = new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0);

        $bounded = new Pool($pool);
        $bounded->setAuthorization(new Authorization());
        $bounded->setTimeout(5000);
        $bounded->getSupportForTimeouts();

        $unbounded = new Pool($pool);
        $unbounded->setAuthorization(new Authorization());
        $unbounded->getSupportForTimeouts();

        $this->assertSame([], $connection->timeouts, 'A handle that asked for no timeout must not run under the last holder\'s');
        $this->assertSame(0, $connection->getTimeout());
    }

    /**
     * The pool's own map is what a checkout replays, so clearing every timeout
     * has to empty it. The inherited implementation walks the events it finds
     * in the adapter's `$transformations`, and this one delegates `before()`,
     * so its own array holds nothing but `EVENT_ALL` — a per-event timeout
     * survived the clear and came back on the next checkout.
     */
    public function testClearingEveryTimeoutDropsPerEventEntriesToo(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(300000);
        $adapter->setTimeout(5000, Database::EVENT_DOCUMENT_READ);
        $adapter->clearTimeouts();
        $adapter->getSupportForTimeouts();

        $this->assertSame([], $connection->timeouts, 'A timeout the caller cleared must not come back on the next checkout');
        $this->assertSame(0, $adapter->getTimeout());
    }

    /**
     * A transaction pins one connection for its whole body and does not check
     * out again before the commit, so a timeout changed inside it has to reach
     * that connection there or not at all - the rest of the body would
     * otherwise run under the timeout the caller just replaced.
     */
    public function testTimeoutChangedInsideATransactionReachesThePinnedConnection(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(5000);

        $insideBody = [];
        $adapter->withTransaction(function () use ($adapter, $connection, &$insideBody): string {
            $adapter->setTimeout(300000);
            $insideBody['raised'] = $connection->timeouts;

            $adapter->clearTimeout(Database::EVENT_ALL);
            $insideBody['cleared'] = $connection->timeouts;

            return 'row-written';
        });

        $this->assertSame([Database::EVENT_ALL => 300000], $insideBody['raised'], 'The rest of the body runs on this connection, so the new timeout must reach it before the commit');
        $this->assertSame([], $insideBody['cleared']);
    }

    /**
     * A caller whose own default is zero is asking for no timeout, which is
     * what a factory building a handle without one passes. It reaches no
     * connection as a timeout the connection would refuse.
     */
    public function testZeroIsHeldAsNoTimeout(): void
    {
        $connection = new TimeoutRecordingMemory();
        $adapter = new Pool(new UtopiaPool(new Stack(), 'memory', 1, fn () => $connection, timeout: 0.0));

        $adapter->setAuthorization(new Authorization());
        $adapter->setTimeout(0);
        $adapter->getSupportForTimeouts();

        $this->assertSame([], $connection->timeouts);
        $this->assertSame(0, $adapter->getTimeout());
    }
}

/**
 * Stands in for a connection: records what the pooled adapter applied to it,
 * the way a concrete adapter records a timeout for the statements it builds.
 */
class TimeoutRecordingMemory extends Memory
{
    /**
     * @var array<string, int>
     */
    public array $timeouts = [];

    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void
    {
        $this->timeouts[$event] = $milliseconds;
        $this->timeout = $milliseconds;
    }

    public function clearTimeout(string $event): void
    {
        unset($this->timeouts[$event]);

        parent::clearTimeout($event);
    }
}
