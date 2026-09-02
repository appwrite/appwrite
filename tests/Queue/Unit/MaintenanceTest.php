<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Idle upkeep. A broker parked in a pool between publishes gets no traffic, and
 * NATS closes a connection after two missed 120s pings — so without a periodic
 * sweep the next publish writes into a socket the server already dropped. These
 * cover the sweep itself; the clock that drives it lives in the Swoole adapter.
 */
final class MaintenanceTest extends TestCase
{
    public function testMaintainSweepsTheBoundConsumer(): void
    {
        $consumer = new MaintainableConsumer();
        $adapter = new MaintenanceAdapter($consumer);

        $adapter->maintain();

        $this->assertSame(1, $consumer->sweeps);
    }

    public function testMaintainSkipsConsumersThatCannotBeSwept(): void
    {
        $adapter = new MaintenanceAdapter(new PlainConsumer());

        // A Redis broker has no idle upkeep to do and exposes no maintain().
        // The sweep must be a no-op for it rather than an error, so a worker can
        // run it unconditionally without knowing which broker it was given.
        $adapter->maintain();

        $this->expectNotToPerformAssertions();
    }

    public function testMaintainDoesNotSweepAConsumerThatOnlyTicks(): void
    {
        $consumer = new TickOnlyConsumer();
        $adapter = new MaintenanceAdapter($consumer);

        $adapter->maintain();

        // tick() reads the socket and needs exclusive access; the consume loop
        // has it. Only maintain() — which reaches idle resources alone — is safe
        // to call from a sweep, so a tick-only consumer must be left alone.
        $this->assertSame(0, $consumer->ticks);
    }

    public function testMaintainReportsAFailedSweepInsteadOfSwallowingIt(): void
    {
        $adapter = new MaintenanceAdapter(new FailingConsumer());

        $reported = [];
        $adapter->maintain(function (?Message $message, \Throwable $error) use (&$reported): void {
            $reported[] = [$message, $error->getMessage()];
        });

        // A pool that cannot keep its idle connections alive will hand the next
        // caller a dead one. That is the silent loss this exists to prevent, so
        // it must not itself fail silently.
        $this->assertCount(1, $reported);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $reported[0][0]);
        $this->assertSame('pool is unreachable', $reported[0][1]);
    }

    public function testMaintainSurvivesAFailedSweepAndAFailingReporter(): void
    {
        $adapter = new MaintenanceAdapter(new FailingConsumer());

        // Never throws: upkeep failing must not take the worker down with it,
        // and neither must a reporting hook that fails while reporting it.
        $adapter->maintain(static function (): never {
            throw new \RuntimeException('the reporter is down too');
        });

        $this->expectNotToPerformAssertions();
    }

    public function testMaintainSweepsEveryConsumerNotOnlyTheFirstToFail(): void
    {
        $failing = new FailingConsumer();
        $healthy = new MaintainableConsumer();
        $adapter = new MaintenanceAdapter($failing, [$healthy]);

        $adapter->maintain(static function (): void {});

        // One broker's bad day must not cost the others their upkeep.
        $this->assertSame(1, $healthy->sweeps);
    }
}

/**
 * Exposes the sweep with a controllable consumer set, so maintenance can be
 * driven without a runtime and without a broker.
 */
final class MaintenanceAdapter extends Adapter
{
    /** @param list<Consumer> $extra */
    public function __construct(Consumer $consumer, private readonly array $extra = [])
    {
        parent::__construct($consumer, 1);
    }

    public function start(): self
    {
        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }

    #[\Override]
    protected function maintenanceTargets(): array
    {
        return [...parent::maintenanceTargets(), ...$this->extra];
    }
}

abstract class MaintenanceConsumer implements Consumer
{
    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return 0;
    }

    public function close(): void {}
}

/** A pooled broker: sweeping it reaches only idle resources, so it is safe. */
final class MaintainableConsumer extends MaintenanceConsumer
{
    public int $sweeps = 0;

    public function maintain(): void
    {
        ++$this->sweeps;
    }
}

/** A broker with nothing to keep alive, e.g. Redis. */
final class PlainConsumer extends MaintenanceConsumer {}

/** A single-connection broker: keeping it alive needs exclusive access. */
final class TickOnlyConsumer extends MaintenanceConsumer
{
    public int $ticks = 0;

    public function tick(): void
    {
        ++$this->ticks;
    }
}

final class FailingConsumer extends MaintenanceConsumer
{
    public function maintain(): never
    {
        throw new \RuntimeException('pool is unreachable');
    }
}
