<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Message\Maintenance as MaintenanceMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Sweep;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;

require_once __DIR__ . '/../../../app/init.php';

final class SweepTest extends TestCase
{
    private const string REGION = 'east';

    private const string ELSEWHERE = 'west';

    private const string QUEUE = 'v1-deletes';

    private const string MAINTENANCE = 'v1-maintenance';

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

    public function testBackedUpQueueDefersTheSliceAtTheNextBlock(): void
    {
        $due = $this->week->modify('+3 hours');
        $rows = $this->blocks($due);
        $this->clock = new TestClock($due);
        // 50 queued and nothing draining: the first block takes it past BACKLOG, so the second cannot start.
        $publisher = new QueuePublisher($this->clock, 0, 50);

        ($this->sweep($publisher))($this->message($due));

        $second = ($this->slot($due->getTimestamp()) + self::SLOTS) * Sweep::BLOCK;
        $this->assertSame($this->sorted(\array_column(\array_slice($rows, 0, 60), 0)), $this->sorted($this->projects($publisher)));
        $this->assertSame([$this->message($due, cursor: (string) ($second - 1))->toArray()], $publisher->payloads(self::MAINTENANCE));
        $this->assertSame((float) ($due->getTimestamp() + Sweep::WAIT), (float) $this->clock->now()->format('U.u'));
    }

    public function testQueueDrainingWithinTheWaitContinuesTheSlice(): void
    {
        $due = $this->week->modify('+3 hours');
        $rows = $this->blocks($due);
        $this->clock = new TestClock($due);
        // 150 queued, six drained a second: under BACKLOG after the wait, and back at 150 after each block of 60.
        $publisher = new QueuePublisher($this->clock, 6, 150);

        ($this->sweep($publisher))($this->message($due));

        $this->assertSame($this->sorted(\array_column($rows, 0)), $this->sorted($this->projects($publisher)));
        $this->assertSame([], $publisher->payloads(self::MAINTENANCE));

        $start = (float) $due->getTimestamp();
        $starts = \array_values(\array_unique(\array_map(fn (float $time): int => (int) ($time - $start), $publisher->times(self::QUEUE))));
        $this->assertSame([Sweep::WAIT, 2 * Sweep::WAIT, 3 * Sweep::WAIT], $starts);
    }

    public function testCursorResumesAfterTheBlocksAlreadyEnqueued(): void
    {
        $due = $this->week->modify('+3 hours');
        $rows = $this->blocks($due);
        $this->clock = new TestClock($due);
        $second = ($this->slot($due->getTimestamp()) + self::SLOTS) * Sweep::BLOCK;

        $publisher = $this->publisher();
        ($this->sweep($publisher))($this->message($due, cursor: (string) ($second - 1)));

        $this->assertSame($this->sorted(\array_column(\array_slice($rows, 60), 0)), $this->sorted($this->projects($publisher)));
    }

    public function testPagesDeferAndResumeWithinTheirPass(): void
    {
        $due = $this->week->modify('+3 hours');
        $this->clock = new TestClock($due);

        $rows = [];
        for ($sequence = 1; $sequence <= 120; $sequence++) {
            $rows[] = [($sequence <= 60 ? 'old-' : 'never-') . $sequence, $sequence, $sequence <= 60 ? $due->modify('-60 days') : null, self::REGION];
        }
        $this->database = Projects::seed(new Uuid7Memory(), $rows);
        $message = new MaintenanceMessage(slot: 0, slots: 1, due: $due->getTimestamp(), period: $this->cycle()->seconds(), region: self::REGION);

        // The accessedAt pass takes the queue from 50 past BACKLOG, so the never-accessed pass defers from its start.
        $deferred = new QueuePublisher($this->clock, 0, 50);
        ($this->sweep($deferred))($message);

        $this->assertSame($this->sorted(\array_column(\array_slice($rows, 0, 60), 0)), $this->sorted($this->projects($deferred)));
        $this->assertSame([(new MaintenanceMessage(slot: 0, slots: 1, due: $due->getTimestamp(), period: $this->cycle()->seconds(), region: self::REGION, pass: 1))->toArray()], $deferred->payloads(self::MAINTENANCE));

        $resumed = $this->publisher();
        ($this->sweep($resumed))(new MaintenanceMessage(slot: 0, slots: 1, due: $due->getTimestamp(), period: $this->cycle()->seconds(), region: self::REGION, pass: 0, cursor: 'old-55'));

        $this->assertSame($this->sorted(\array_column(\array_slice($rows, 55), 0)), $this->sorted($this->projects($resumed)));

        $resumed = $this->publisher();
        ($this->sweep($resumed))(new MaintenanceMessage(slot: 0, slots: 1, due: $due->getTimestamp(), period: $this->cycle()->seconds(), region: self::REGION, pass: 1, cursor: 'never-100'));

        $this->assertSame($this->sorted(\array_column(\array_slice($rows, 100), 0)), $this->sorted($this->projects($resumed)));
    }

    public function testPageCursorStaysWithinItsPass(): void
    {
        $due = $this->week->modify('+3 hours');
        $this->clock = new TestClock($due);

        // Interleaved, so the never-accessed pass has projects sequenced before the accessedAt pass's cursor.
        $rows = [];
        for ($sequence = 1; $sequence <= 120; $sequence++) {
            $old = $sequence % 2 === 1;
            $rows[] = [($old ? 'old-' : 'never-') . $sequence, $sequence, $old ? $due->modify('-60 days') : null, self::REGION];
        }
        $this->database = Projects::seed(new Uuid7Memory(), $rows);

        $publisher = $this->publisher();
        ($this->sweep($publisher))(new MaintenanceMessage(slot: 0, slots: 1, due: $due->getTimestamp(), period: $this->cycle()->seconds(), region: self::REGION, pass: 0, cursor: 'old-55'));

        $expected = \array_filter(\array_column($rows, 0), fn (string $id): bool => \str_starts_with($id, 'never-') || (int) \substr($id, 4) > 55);
        $this->assertSame($this->sorted(\array_values($expected)), $this->sorted($this->projects($publisher)));
        $this->assertContains('never-2', $this->projects($publisher));
    }

    public function testRefusedDeferralStopsTheSweep(): void
    {
        $due = $this->week->modify('+3 hours');
        $this->blocks($due);
        $this->clock = new TestClock($due);
        // Backed up with nothing draining, so the first publish attempt is the deferral itself.
        $publisher = new QueuePublisher($this->clock, 0, 150);
        $publisher->refuse = 1;

        $failed = null;
        try {
            ($this->sweep($publisher))($this->message($due));
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failed);
        $this->assertSame([], $publisher->payloads(self::QUEUE));
        $this->assertSame([], $publisher->payloads(self::MAINTENANCE));
        $this->assertSame((float) ($due->getTimestamp() + Sweep::WAIT), (float) $this->clock->now()->format('U.u'));
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

        $publisher = $this->publisher();
        $publisher->refuse = 3;

        $failed = null;
        try {
            ($this->sweep($publisher))($this->message($due));
        } catch (\RuntimeException $error) {
            $failed = $error;
        }

        $this->assertInstanceOf(\RuntimeException::class, $failed);
        $this->assertCount(2, $this->projects($publisher));
        $this->assertSame([], $publisher->payloads(self::MAINTENANCE));
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

    private function message(\DateTimeImmutable $due, string $cursor = ''): MaintenanceMessage
    {
        return new MaintenanceMessage(
            slot: $this->slot($due->getTimestamp()),
            slots: self::SLOTS,
            due: $due->getTimestamp(),
            period: $this->cycle()->seconds(),
            region: self::REGION,
            cursor: $cursor,
        );
    }

    /**
     * Seeds three blocks of the slot due at $due, 60 long-inactive projects each.
     *
     * @return list<array{0: string, 1: int, 2: ?\DateTimeImmutable, 3: string}>
     */
    private function blocks(\DateTimeImmutable $due): array
    {
        $slot = $this->slot($due->getTimestamp());

        $rows = [];
        foreach ([$slot, $slot + self::SLOTS, $slot + 2 * self::SLOTS] as $block) {
            for ($offset = 1; $offset <= 60; $offset++) {
                $sequence = $block * Sweep::BLOCK + $offset;
                $rows[] = ['old-' . $sequence, $sequence, $due->modify('-60 days'), self::REGION];
            }
        }
        $this->database = Projects::seed(new Memory(), $rows);

        return $rows;
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
        return new Sweep(
            $this->database,
            new DeletePublisher($publisher, new Queue(self::QUEUE)),
            new MaintenancePublisher($publisher, new Queue(self::MAINTENANCE)),
            $this->clock,
        );
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
