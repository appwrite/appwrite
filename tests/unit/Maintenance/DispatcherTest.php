<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Dispatcher;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Store\Memory as MemoryStore;

final class DispatcherTest extends TestCase
{
    private const string QUEUE = 'v1-maintenance';

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

        $this->dispatch(new MemoryStore(), $publisher, $end);

        $expected = [];
        for ($due = $this->start->getTimestamp(); $due <= $end; $due += Cycle::INTERVAL) {
            $expected[] = ['slot' => \intdiv($due, 300) % 2016, 'slots' => 2016, 'due' => $due, 'period' => self::PERIOD];
        }

        $this->assertCount(13, $expected);
        $this->assertSame($expected, $publisher->payloads(self::QUEUE));
    }

    public function testUnpublishedMomentIsDeliveredAgain(): void
    {
        $due = $this->start->getTimestamp();
        $store = new MemoryStore();
        $queue = new QueuePublisher($this->clock, 0);
        $refusing = new class ($queue) implements Synchronous {
            public bool $refuse = true;

            public function __construct(private readonly QueuePublisher $queue)
            {
            }

            #[\Override]
            public function publish(Queue $queue, array $payload): bool
            {
                return !$this->refuse && $this->queue->publish($queue, $payload);
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
            {
                return !$this->refuse && $this->queue->publishMany($queue, $payloads);
            }

            #[\Override]
            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            #[\Override]
            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return $this->queue->getQueueSize($queue, $failedJobs);
            }

            #[\Override]
            public function getFailedCount(Queue $queue): int
            {
                return 0;
            }
        };

        $failed = null;
        try {
            $this->dispatch($store, $refusing, $due);
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failed);
        $this->assertSame([], $queue->payloads(self::QUEUE));
        $this->assertLessThanOrEqual((float) $due, $store->load()?->coveredUntil);

        $this->clock->advance(120);
        $refusing->refuse = false;
        $this->dispatch($store, $refusing, $due);

        $this->assertSame([$due], \array_column($queue->payloads(self::QUEUE), 'due'));
        $this->assertGreaterThan((float) $due, $store->load()?->coveredUntil);
    }

    /**
     * Runs the scheduler as ScheduleMaintenance does, until the moment due at $until has been handed over.
     */
    private function dispatch(MemoryStore $store, Synchronous $publisher, int $until): void
    {
        $dispatcher = new Dispatcher(new MaintenancePublisher($publisher, new Queue(self::QUEUE)), $this->cycle);
        $scheduler = $this->cycle->scheduler($store, clock: $this->clock);

        $scheduler->run(function (array $occurrences) use ($dispatcher, $scheduler, $until): void {
            $dispatcher($occurrences);
            if ($occurrences[\count($occurrences) - 1]->due->getTimestamp() >= $until) {
                $scheduler->stop();
            }
        });
    }
}
