<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Pool;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * The NATS broker used through Broker\Pool — the pooled wiring cloud uses. The pool
 * leases one broker (and therefore one single-owner connection) per caller, which is
 * the recommended way to use Broker\Nats concurrently.
 */
final class NatsPoolTest extends Base
{
    protected function getPublisher(): Synchronous
    {
        $factory = fn(): Nats => new Nats(
            fn(): Connection => Connection::connect('nats://127.0.0.1:14225'),
            maxDeliver: 3,
        );
        $pool = new UtopiaPool(new Stack(), 'nats', 1, $factory, timeout: 0.0);

        return new Pool($pool, $pool);
    }

    protected function getQueue(): Queue
    {
        return new Queue('nats-pool');
    }

    /**
     * A publisher slot that nobody touches must still be publishable afterwards.
     *
     * This is the shape of the production failure: NATS pings every 120s and
     * closes after two go unanswered, and the client keepalive only ran while a
     * caller was inside a call — so a pooled publisher between publishes was
     * reaped on a timer nobody was driving, and the next publish wrote into a
     * dead socket and returned success.
     *
     * The server's 240s deadline cannot be reproduced in a test, so the client's
     * own ping budget stands in for it, scaled down: at a 0.3s interval and two
     * permitted outstanding pings, an idle connection is declared stale inside a
     * second unless each sweep also collects the answers to the pings it sends.
     * The loop below idles well past that.
     *
     * The assertion is on reconnects, not on the publish. A keepalive that
     * sends pings without ever reading the replies still ends with a working
     * publish — checkPings() quietly rebuilds the connection once the budget is
     * spent — so "the publish worked" cannot tell the two apart. What separates
     * them is that the broken version reaches that publish by churning through
     * reconnects of a connection that was healthy all along.
     */
    public function testIdlePooledPublisherSurvivesRepeatedMaintenance(): void
    {
        $reconnects = 0;
        // Full closures throughout, not arrow functions: an arrow function
        // captures by value, so an onReconnect defined inside one increments a
        // copy and the count silently stays zero however often it fires.
        $connect = function () use (&$reconnects): Connection {
            return Connection::connect(new ConnectionOptions(
                servers: 'nats://127.0.0.1:14225',
                pingInterval: 0.3,
                maxPingsOut: 2,
                onReconnect: function () use (&$reconnects): void {
                    ++$reconnects;
                },
            ));
        };
        $factory = fn(): Nats => new Nats($connect, maxDeliver: 3);
        $pool = new UtopiaPool(new Stack(), 'nats-idle', 1, $factory, timeout: 5.0);
        $broker = new Pool($pool, $pool);
        $queue = new Queue('nats-pool-idle');

        // Measured as a delta: the work stream outlives the test run, so an
        // absolute depth would depend on what earlier runs left behind.
        $before = $broker->getQueueSize($queue);

        // Open the connection, then leave it idle in the pool.
        $this->assertTrue($broker->publish($queue, ['seq' => 'first']));

        // ~3s of nothing but upkeep, an order of magnitude past the budget.
        for ($sweep = 0; $sweep < 12; $sweep++) {
            usleep(250_000);
            $broker->maintain();
        }

        // The connection was answered on every ping, so nothing about it was
        // ever stale and it must not have been rebuilt once.
        $this->assertSame(0, $reconnects, 'An idle connection the server is answering must not be recycled');

        // And it is still usable: the message really is on the stream, not
        // written into a socket the server has already dropped.
        $this->assertTrue($broker->publish($queue, ['seq' => 'second']));
        $this->assertSame($before + 2, $broker->getQueueSize($queue));
    }
}
