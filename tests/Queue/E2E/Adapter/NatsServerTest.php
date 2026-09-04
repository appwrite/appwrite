<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\NATS\Connection;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * Drives the NATS broker through the full Server + Swoole adapter run-loop
 * (worker: tests/Queue/servers/Nats/worker.php). Exercises enqueue -> receive ->
 * handler -> commit/reject across every payload shape, plus priority and retry.
 */
final class NatsServerTest extends Base
{
    protected function getPublisher(): Synchronous
    {
        // A fresh connection per publisher (Base publishes from multiple coroutines;
        // a NATS connection is single-owner, so never share one).
        return new Nats(fn(): Connection => Connection::connect('nats://127.0.0.1:14225'), maxDeliver: 3);
    }

    protected function getQueue(): Queue
    {
        return new Queue('nats');
    }
}
