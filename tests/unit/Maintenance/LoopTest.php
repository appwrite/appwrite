<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Dispatcher;
use Appwrite\Maintenance\Loop;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Store\Memory as MemoryStore;

final class LoopTest extends TestCase
{
    private const string QUEUE = 'v1-maintenance';

    private const int PERIOD = 604800;

    /** Well inside a 30 second termination grace period. */
    private const int GRACE = 5;

    private int $start;

    private TestClock $clock;

    private Cycle $cycle;

    private MemoryStore $store;

    private QueuePublisher $queue;

    protected function setUp(): void
    {
        $this->start = new \DateTimeImmutable('2026-03-02 06:00:00', new \DateTimeZone('UTC'))->getTimestamp();
        $this->clock = new TestClock(new \DateTimeImmutable('@' . ($this->start - 30)));
        $this->cycle = new Cycle(self::PERIOD);
        $this->store = new MemoryStore();
        $this->queue = new QueuePublisher($this->clock, 0);
    }

    public function testStoppedLeaderReleasesTheClaimAndAStandbyPublishesTheNextSlotOnTime(): void
    {
        // A due moment goes out on the tick after it, so the signal lands once the slot due at +600 is out.
        $this->runUntil('leader', $this->start + 601.5);

        $this->assertCount(3, $this->queue->payloads(self::QUEUE));
        $claim = $this->store->load();
        $this->assertSame('', $claim?->token);
        $this->assertEqualsWithDelta(0.0, $claim->expiresAt, PHP_FLOAT_EPSILON);
        $this->assertGreaterThan((float) ($this->start + 600), $claim->coveredUntil);
        $this->assertLessThan($this->start + 601.5 + self::GRACE, (float) $this->clock->now()->format('U.u'));

        $this->runUntil('standby', $this->start + 901.5);

        $dues = \array_column($this->queue->payloads(self::QUEUE), 'due');
        $this->assertSame([$this->start, $this->start + 300, $this->start + 600, $this->start + 900], $dues);
        $this->assertSame(
            \array_map(fn (int $due): int => \intdiv($due, Cycle::INTERVAL) % 2016, $dues),
            \array_column($this->queue->payloads(self::QUEUE), 'slot'),
        );
        $this->assertSame((float) ($this->start + 901), $this->queue->times(self::QUEUE)[3]);
    }

    public function testStandbyReplacesACrashedLeaderWithinAMinute(): void
    {
        // The leader publishes the slot due at the start, then dies without releasing its claim.
        $leader = $this->cycle->scheduler($this->store, 'leader', clock: $this->clock);
        $dispatcher = $this->dispatcher();
        $leader->reconcile();
        foreach ([31, 0] as $seconds) {
            $dispatcher($leader->tick());
            $leader->commit();
            $this->clock->advance($seconds);
        }
        $this->assertSame([(float) ($this->start + 1)], $this->queue->times(self::QUEUE));
        $this->assertSame('leader', $this->store->load()?->token);

        $this->runUntil('standby', $this->start + 301.5);

        $this->assertSame([$this->start, $this->start + 300], \array_column($this->queue->payloads(self::QUEUE), 'due'));
        $this->assertSame((float) ($this->start + 301), $this->queue->times(self::QUEUE)[1]);
    }

    public function testLoopStoppedBeforeItRunsNeverTakesTheClaim(): void
    {
        // A loop that ran anyway would never stop on its own; the deadline ends it.
        $now = (float) $this->clock->now()->format('U.u');
        $loop = $this->loop('leader', new SignalClock($this->clock, $now, $now + self::GRACE));
        $loop->stop();

        $loop->run($this->store);

        $this->assertTrue($loop->stopped());
        $this->assertNull($this->store->load());
        $this->assertSame([], $this->queue->payloads(self::QUEUE));
    }

    public function testStopEndsAPause(): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        $clock = new SignalClock($this->clock, $now + 1.5, $now + self::GRACE);
        $loop = $this->loop('leader', $clock);
        $clock->signal = $loop->stop(...);

        $loop->pause(60);

        $this->assertEqualsWithDelta($now + 2, (float) $this->clock->now()->format('U.u'), PHP_FLOAT_EPSILON);
    }

    private function runUntil(string $token, float $signal): void
    {
        $clock = new SignalClock($this->clock, $signal, $signal + self::GRACE);
        $loop = $this->loop($token, $clock);
        $clock->signal = $loop->stop(...);

        $loop->run($this->store);
    }

    private function loop(string $token, Clock $clock): Loop
    {
        return new Loop($this->cycle, $this->dispatcher(), $token, clock: $clock);
    }

    private function dispatcher(): Dispatcher
    {
        return new Dispatcher(new MaintenancePublisher($this->queue, new Queue(self::QUEUE)), $this->cycle, 'east');
    }
}
