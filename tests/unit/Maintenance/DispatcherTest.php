<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Dispatcher;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Store\Memory as MemoryStore;

final class DispatcherTest extends TestCase
{
    private const string QUEUE = 'v1-maintenance';

    private const string REGION = 'east';

    private const int PERIOD = 604800;

    private \DateTimeImmutable $start;

    private TestClock $clock;

    private Cycle $cycle;

    protected function setUp(): void
    {
        $this->start = new \DateTimeImmutable('2026-03-02 06:00:00', new \DateTimeZone('UTC'));
        $this->clock = new TestClock($this->start->modify('-30 seconds'));
        $this->cycle = new Cycle(self::PERIOD);
    }

    public function testAnHourOfTicksPublishesOneMessagePerMoment(): void
    {
        $publisher = new QueuePublisher($this->clock, 0);
        $end = $this->start->getTimestamp() + 3600;

        $this->dispatch(new MemoryStore(), $this->dispatcher($publisher), $end);

        $expected = [];
        for ($due = $this->start->getTimestamp(); $due <= $end; $due += Cycle::INTERVAL) {
            $expected[] = ['slot' => \intdiv($due, 300) % 2016, 'slots' => 2016, 'due' => $due, 'period' => self::PERIOD, 'region' => self::REGION, 'pass' => 0, 'cursor' => ''];
        }

        $this->assertCount(13, $expected);
        $this->assertSame($expected, $publisher->payloads(self::QUEUE));
    }

    public function testUnpublishedMomentIsDeliveredAgain(): void
    {
        $due = $this->start->getTimestamp();
        $store = new MemoryStore();
        $queue = new QueuePublisher($this->clock, 0);
        $queue->refuse = 1;
        $dispatcher = $this->dispatcher($queue);

        $failed = null;
        try {
            $this->dispatch($store, $dispatcher, $due);
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failed);
        $this->assertSame([], $queue->payloads(self::QUEUE));
        $this->assertLessThanOrEqual((float) $due, $store->load()?->coveredUntil);

        $this->clock->advance(120);
        $queue->refuse = null;
        $this->dispatch($store, $dispatcher, $due);

        $this->assertSame([$due], \array_column($queue->payloads(self::QUEUE), 'due'));
        $this->assertGreaterThan((float) $due, $store->load()?->coveredUntil);
    }

    public function testRedeliveredTickPublishesEachMomentOnce(): void
    {
        $store = new MemoryStore();
        $queue = new QueuePublisher($this->clock, 0);
        $dispatcher = $this->dispatcher($queue);

        // The scheduler first starts at now, so let a backlog of moments build up before the first tick.
        $this->dispatch($store, $dispatcher, $this->start->getTimestamp());
        $this->clock->advance(Cycle::INTERVAL * 6);
        $published = \count($queue->payloads(self::QUEUE));

        // The fourth moment of the catch-up tick is refused, after three were published.
        $queue->refuse = $published + 4;
        $failed = null;
        try {
            $this->dispatch($store, $dispatcher, $this->start->getTimestamp() + Cycle::INTERVAL * 6);
        } catch (\RuntimeException $error) {
            $failed = $error;
        }
        $this->assertInstanceOf(\RuntimeException::class, $failed);
        $this->assertCount($published + 3, $queue->payloads(self::QUEUE));

        $queue->refuse = null;
        $this->dispatch($store, $dispatcher, $this->start->getTimestamp() + Cycle::INTERVAL * 6);

        $dues = \array_column($queue->payloads(self::QUEUE), 'due');
        $expected = \range($this->start->getTimestamp(), $this->start->getTimestamp() + Cycle::INTERVAL * 6, Cycle::INTERVAL);
        $this->assertSame($expected, $dues);
    }

    private function dispatcher(QueuePublisher $queue): Dispatcher
    {
        return new Dispatcher(new MaintenancePublisher($queue, new Queue(self::QUEUE)), $this->cycle, self::REGION);
    }

    /**
     * Runs the scheduler as ScheduleMaintenance does, until the moment due at $until has been handed over.
     */
    private function dispatch(MemoryStore $store, Dispatcher $dispatcher, int $until): void
    {
        $scheduler = $this->cycle->scheduler($store, clock: $this->clock);

        $scheduler->run(function (array $occurrences) use ($dispatcher, $scheduler, $until): void {
            $dispatcher($occurrences);
            if ($occurrences[\count($occurrences) - 1]->due->getTimestamp() >= $until) {
                $scheduler->stop();
            }
        });
    }
}
