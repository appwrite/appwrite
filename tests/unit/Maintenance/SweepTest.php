<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Sweep;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;

require_once __DIR__ . '/../../../app/init.php';

final class SweepTest extends TestCase
{
    private const string REGION = 'east';

    private const string ELSEWHERE = 'west';

    private const string QUEUE = 'v1-deletes';

    private const int SLOTS = 48;

    private const int SPACING = 101;

    private \DateTimeImmutable $week;

    private TestClock $clock;

    private Database $database;

    /** @var array<string, int> inactive project => due of the slice that owns it */
    private array $inactive = [];

    /** @var list<string> */
    private array $excluded = [];

    private string|false $key;

    protected function setUp(): void
    {
        // Projects default smtp and oAuthProviders through the encrypt filter on every write and read.
        $this->key = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        $this->week = new \DateTimeImmutable('2026-03-02 00:00:00', new \DateTimeZone('UTC'));
        $this->clock = new TestClock($this->week);
    }

    protected function tearDown(): void
    {
        \putenv($this->key === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->key);
    }

    public function testWeekOfSlicesReachesEachInactiveProjectOnce(): void
    {
        $this->database = $this->population(new Memory(), 240, fn (string $id, int $sequence): int => \intdiv($sequence, Sweep::BLOCK) % self::SLOTS);

        $publisher = $this->publisher();
        $reached = $this->week($this->sweep($publisher), $publisher);

        $this->assertSame($this->keyed($this->inactive), $this->keyed(\array_map(fn (array $dues): int => $dues[0], $reached)));
        $this->assertSame([], \array_keys(\array_filter($reached, fn (array $dues): bool => \count($dues) > 1)));
        $this->assertSame([], \array_values(\array_intersect(\array_keys($reached), $this->excluded)));
        $this->assertArrayHasKey('stale-' . (1 + 4 * self::SPACING), $reached);
        // Block 207 is past SLOTS blocks, so it wraps round to slot 15.
        $this->assertArrayHasKey('old-' . (1 + 205 * self::SPACING), $reached);
        $this->assertArrayNotHasKey('edge-' . (1 + 3 * self::SPACING), $reached);
        foreach ($publisher->payloads(self::QUEUE) as $payload) {
            $this->assertSame(DELETE_TYPE_MAINTENANCE, $payload['type']);
            $this->assertSame(self::REGION, $payload['project']['region']);
            // Everything the worker needs to find the project and its database survives the narrowed read.
            $this->assertSame('database_db_' . self::REGION, $payload['project']['database']);
            $this->assertSame('1', $payload['project']['teamInternalId']);
            $this->assertNotEmpty($payload['project']['$createdAt']);
        }
    }

    public function testSliceAndCutoffComeFromTheMessage(): void
    {
        $due = $this->week->modify('+35 minutes');
        // Late enough that a cutoff taken from the clock would count the recent project as inactive.
        $this->clock = new TestClock($due->modify('+3 hours'));
        $slot = $this->slot($due->getTimestamp());
        $base = $slot * Sweep::BLOCK;
        $wrapped = ($slot + self::SLOTS) * Sweep::BLOCK;
        $neighbour = (($slot + 1) % self::SLOTS) * Sweep::BLOCK;

        $this->database = Projects::seed(new Memory(), [
            ['recent-' . ($base + 1), $base + 1, $due->modify('-30 days +1 hour'), self::REGION],
            ['old-' . ($base + 2), $base + 2, $due->modify('-31 days'), self::REGION],
            ['never-' . ($base + 3), $base + 3, null, self::REGION],
            ['elsewhere-' . ($base + 4), $base + 4, null, self::ELSEWHERE],
            ['old-' . ($wrapped + 5), $wrapped + 5, $due->modify('-31 days'), self::REGION],
            ['old-' . ($neighbour + 6), $neighbour + 6, $due->modify('-31 days'), self::REGION],
        ]);

        $publisher = $this->publisher();
        ($this->sweep($publisher))($this->message($due));

        $this->assertSame(
            $this->sorted(['old-' . ($base + 2), 'never-' . ($base + 3), 'old-' . ($wrapped + 5)]),
            $this->sorted($this->projects($publisher)),
        );
    }

    public function testDocumentSequencesSpreadByHash(): void
    {
        $this->database = $this->population(new Uuid7Memory(), 72, fn (string $id, int $sequence): int => \crc32($id) % self::SLOTS);

        $publisher = $this->publisher();
        $reached = $this->week($this->sweep($publisher), $publisher);

        $this->assertSame($this->keyed($this->inactive), $this->keyed(\array_map(fn (array $dues): int => $dues[0], $reached)));
        $this->assertSame([], \array_keys(\array_filter($reached, fn (array $dues): bool => \count($dues) > 1)));
        $this->assertSame([], \array_values(\array_intersect(\array_keys($reached), $this->excluded)));
    }

    public function testEachBlockWaitsForTheDeletesQueueToDrain(): void
    {
        $due = $this->week->modify('+3 hours');
        $slot = $this->slot($due->getTimestamp());

        $rows = [];
        foreach ([$slot, $slot + self::SLOTS, $slot + 2 * self::SLOTS] as $block) {
            for ($offset = 1; $offset <= 60; $offset++) {
                $sequence = $block * Sweep::BLOCK + $offset;
                $rows[] = ['old-' . $sequence, $sequence, $due->modify('-60 days'), self::REGION];
            }
        }
        $this->database = Projects::seed(new Memory(), $rows);

        $start = (float) $due->getTimestamp();
        $this->clock = new TestClock($due);
        // 150 queued, one drained a second: under BACKLOG after 51 seconds, and back over it after each block of 60.
        $publisher = new QueuePublisher($this->clock, 1, 150);

        ($this->sweep($publisher))($this->message($due));

        $this->assertSame($this->sorted(\array_column($rows, 0)), $this->sorted($this->projects($publisher)));

        $starts = \array_values(\array_unique(\array_map(fn (float $time): int => (int) ($time - $start), $publisher->times(self::QUEUE))));
        $this->assertSame([60, 120, 180], $starts);
    }

    public function testUnpublishedMessageStopsTheSweep(): void
    {
        $due = $this->week->modify('+4 hours');
        $this->clock = new TestClock($due);
        $base = $this->slot($due->getTimestamp()) * Sweep::BLOCK;

        $rows = [];
        for ($offset = 1; $offset <= 6; $offset++) {
            $rows[] = ['old-' . ($base + $offset), $base + $offset, $due->modify('-60 days'), self::REGION];
        }
        $this->database = Projects::seed(new Memory(), $rows);

        $refusing = new class () implements Synchronous {
            public int $attempts = 0;

            /** @var list<string> */
            public array $accepted = [];

            #[\Override]
            public function publish(Queue $queue, array $payload): bool
            {
                if (++$this->attempts === 3) {
                    return false;
                }
                $this->accepted[] = $payload['project']['$id'];

                return true;
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
            {
                return false;
            }

            #[\Override]
            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            #[\Override]
            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }

            #[\Override]
            public function getFailedCount(Queue $queue): int
            {
                return 0;
            }
        };

        $failed = null;
        try {
            (new Sweep($this->database, new DeletePublisher($refusing, new Queue(self::QUEUE)), self::REGION, $this->clock))($this->message($due));
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failed);
        $this->assertSame(3, $refusing->attempts);
        $this->assertCount(2, $refusing->accepted);
    }

    public function testMessageOlderThanACycleIsSkipped(): void
    {
        $due = $this->week->modify('+5 hours');
        $base = $this->slot($due->getTimestamp()) * Sweep::BLOCK;
        $this->database = Projects::seed(new Memory(), [['old-' . ($base + 1), $base + 1, $due->modify('-60 days'), self::REGION]]);

        $this->clock = new TestClock($due->modify('+' . ($this->cycle()->seconds() + 1) . ' seconds'));
        $superseded = $this->publisher();
        ($this->sweep($superseded))($this->message($due));

        $this->clock = new TestClock($due->modify('+' . $this->cycle()->seconds() . ' seconds'));
        $late = $this->publisher();
        ($this->sweep($late))($this->message($due));

        $this->assertSame([], $this->projects($superseded));
        $this->assertSame(['old-' . ($base + 1)], $this->projects($late));
    }

    /**
     * Sweeps every slice of the week in order, one message per call, with the clock following the dues.
     *
     * @return array<string, list<int>> project => dues of the slices that enqueued it
     */
    private function week(Sweep $sweep, QueuePublisher $publisher): array
    {
        $reached = [];
        for ($slice = 0; $slice < self::SLOTS; $slice++) {
            $due = $this->week->modify('+' . ($slice * Cycle::INTERVAL) . ' seconds');
            $this->clock->advance((float) $due->getTimestamp() - (float) $this->clock->now()->format('U.u') + 1);

            $before = \count($publisher->payloads(self::QUEUE));
            $sweep($this->message($due));

            foreach (\array_slice($this->projects($publisher), $before) as $id) {
                $reached[$id][] = $due->getTimestamp();
            }
        }

        return $reached;
    }

    /**
     * $count projects, one per block, SPACING sequences apart. They cycle through: recently accessed, accessed 40 days
     * ago, never accessed, accessed exactly at the cutoff of the slice that owns them, one second before that cutoff,
     * and never accessed in another region. Every seventh also has a never-accessed neighbour one sequence later.
     *
     * @param \Closure(string, int): int $owner the slot a project belongs to
     */
    private function population(Adapter $adapter, int $count, \Closure $owner): Database
    {
        $this->inactive = [];
        $this->excluded = [];
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $sequence = 1 + $index * self::SPACING;
            $kind = ['active', 'old', 'never', 'edge', 'stale', 'elsewhere'][$index % 6];
            $id = $kind . '-' . $sequence;
            $cutoff = $this->due($owner($id, $sequence))->modify('-' . Sweep::INACTIVITY . ' seconds');

            [$accessedAt, $region] = match ($kind) {
                'active' => [$this->week->modify('-1 day'), self::REGION],
                'old' => [$this->week->modify('-40 days'), self::REGION],
                'never' => [null, self::REGION],
                'edge' => [$cutoff, self::REGION],
                'stale' => [$cutoff->modify('-1 second'), self::REGION],
                'elsewhere' => [null, self::ELSEWHERE],
            };
            $rows[] = [$id, $sequence, $accessedAt, $region];

            if (\in_array($kind, ['old', 'never', 'stale'], true)) {
                $this->inactive[$id] = $this->due($owner($id, $sequence))->getTimestamp();
            } else {
                $this->excluded[] = $id;
            }

            if ($index % 7 === 0) {
                $pair = 'pair-' . ($sequence + 1);
                $rows[] = [$pair, $sequence + 1, null, self::REGION];
                $this->inactive[$pair] = $this->due($owner($pair, $sequence + 1))->getTimestamp();
            }
        }

        return Projects::seed($adapter, $rows);
    }

    /**
     * Where the week's slices start: the due at which the slot comes round.
     */
    private function due(int $slot): \DateTimeImmutable
    {
        $offset = ($slot - $this->slot($this->week->getTimestamp()) + self::SLOTS) % self::SLOTS;

        return $this->week->modify('+' . ($offset * Cycle::INTERVAL) . ' seconds');
    }

    private function slot(int $due): int
    {
        return \intdiv($due, Cycle::INTERVAL) % self::SLOTS;
    }

    private function message(\DateTimeImmutable $due): MaintenanceMessage
    {
        return new MaintenanceMessage(
            slot: $this->slot($due->getTimestamp()),
            slots: self::SLOTS,
            due: $due->getTimestamp(),
            period: $this->cycle()->seconds(),
        );
    }

    private function cycle(): Cycle
    {
        return new Cycle(self::SLOTS * Cycle::INTERVAL);
    }

    private function publisher(): QueuePublisher
    {
        return new QueuePublisher($this->clock, 1000);
    }

    private function sweep(QueuePublisher $publisher): Sweep
    {
        return new Sweep($this->database, new DeletePublisher($publisher, new Queue(self::QUEUE)), self::REGION, $this->clock);
    }

    /**
     * @return list<string>
     */
    private function projects(QueuePublisher $publisher): array
    {
        return \array_map(fn (array $payload): string => $payload['project']['$id'], $publisher->payloads(self::QUEUE));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        \sort($values);

        return $values;
    }

    /**
     * @param array<string, int> $values
     * @return array<string, int>
     */
    private function keyed(array $values): array
    {
        \ksort($values);

        return $values;
    }
}
