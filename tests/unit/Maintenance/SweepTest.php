<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Cadence;
use Appwrite\Maintenance\Cohort;
use Appwrite\Maintenance\Slot;
use Appwrite\Maintenance\Sweep;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;

require_once __DIR__ . '/../../../app/init.php';

final class SweepTest extends TestCase
{
    private const string REGION = 'east';

    private const string ELSEWHERE = 'west';

    private const string QUEUE = 'v1-deletes';

    private const int BLOCKS = 34;

    private TestClock $clock;

    private Database $database;

    private string $cutoff;

    /** @var list<string> */
    private array $active = [];

    /** @var list<string> */
    private array $inactive = [];

    /** @var list<string> */
    private array $elsewhere = [];

    protected function setUp(): void
    {
        $this->clock = new TestClock(new \DateTimeImmutable('2026-03-02 00:00:00', new \DateTimeZone('UTC')));
        $this->cutoff = Sweep::cutoff($this->clock);
        $this->database = $this->seed(new Memory());
    }

    public function testDefaultIntervalReachesEachActiveProjectOncePerCycle(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $this->assertSame(24, $cadence->slots(Cohort::Active));

        $reached = [];
        foreach ($this->ticks($cadence, Cohort::Active) as $slot) {
            $publisher = $this->publisher();
            $this->sweep($publisher)->run(Cohort::Active, $slot, $this->cutoff);
            \array_push($reached, ...$this->projects($publisher));
        }

        $this->assertSame($this->sorted($this->active), $this->sorted($reached));
    }

    public function testHourlyIntervalReachesEveryActiveProjectEveryTick(): void
    {
        $cadence = Cadence::fromIntervals(3600, 604800);
        $this->assertSame(1, $cadence->slots(Cohort::Active));

        $anchor = $this->clock->now()->getTimestamp();
        foreach ([0, 1] as $tick) {
            $publisher = $this->publisher();
            $slot = Slot::at($anchor + $tick * $cadence->tick, $anchor, $cadence->tick, 1);

            $this->sweep($publisher)->run(Cohort::Active, $slot, $this->cutoff);

            $this->assertSame($this->sorted($this->active), $this->sorted($this->projects($publisher)), 'tick ' . $tick);
        }
    }

    public function testActiveCohortIgnoresAFullQueue(): void
    {
        $publisher = new QueuePublisher($this->clock, 0, 1000);
        $before = $this->clock->now();

        $outcome = $this->sweep($publisher)->run(Cohort::Active, Slot::full(), $this->cutoff);

        $this->assertSame($this->sorted($this->active), $this->sorted($this->projects($publisher)));
        $this->assertSame(\count($this->active), $outcome->enqueued);
        $this->assertSame(0, $outcome->waited);
        $this->assertSame(0, $outcome->dropped);
        $this->assertSame($before->format('U.u'), $this->clock->now()->format('U.u'));
        foreach ($publisher->payloads(self::QUEUE) as $payload) {
            $this->assertSame(DELETE_TYPE_MAINTENANCE, $payload['type']);
            $this->assertSame(self::REGION, $payload['project']['region']);
        }
    }

    public function testInactiveCohortCoversOldAndNullAccessedAtOncePerWeek(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $this->assertSame(168, $cadence->slots(Cohort::Inactive));

        $reached = [];
        foreach ($this->ticks($cadence, Cohort::Inactive) as $slot) {
            $publisher = new QueuePublisher($this->clock, 1000);
            $deadline = $this->clock->now()->getTimestamp() + $cadence->tick;

            $outcome = $this->sweep($publisher)->run(Cohort::Inactive, $slot, $this->cutoff, $deadline);

            $this->assertSame(0, $outcome->dropped);
            \array_push($reached, ...$this->projects($publisher));
        }

        $this->assertSame($this->sorted($this->inactive), $this->sorted($reached));
        $this->assertContains('old-2', $reached);
        $this->assertContains('never-1002', $reached);
    }

    public function testCohortsPartitionEveryProjectAtOneCutoff(): void
    {
        $cadence = Cadence::fromIntervals(86400, 604800);
        $reached = [Cohort::Active->value => [], Cohort::Inactive->value => []];

        foreach ([Cohort::Active, Cohort::Inactive] as $cohort) {
            foreach ($this->ticks($cadence, $cohort) as $slot) {
                $publisher = $this->publisher();
                $this->sweep($publisher)->run($cohort, $slot, $this->cutoff);
                \array_push($reached[$cohort->value], ...$this->projects($publisher));
            }
        }

        $this->assertContains('edge-5003', $reached[Cohort::Active->value]);
        $this->assertNotContains('edge-5003', $reached[Cohort::Inactive->value]);
        $this->assertContains('never-1002', $reached[Cohort::Inactive->value]);
        $this->assertNotContains('never-1002', $reached[Cohort::Active->value]);
        $this->assertSame(
            $this->sorted([...$this->active, ...$this->inactive]),
            $this->sorted([...$reached[Cohort::Active->value], ...$reached[Cohort::Inactive->value]]),
        );
    }

    public function testOtherRegionIsNeverEnqueued(): void
    {
        $reached = [];
        foreach ([Cohort::Active, Cohort::Inactive] as $cohort) {
            $publisher = $this->publisher();
            $this->sweep($publisher)->run($cohort, Slot::full(), $this->cutoff);
            \array_push($reached, ...$this->projects($publisher));
        }

        $this->assertSame([], \array_values(\array_intersect($reached, $this->elsewhere)));

        $elsewhere = [];
        foreach ([Cohort::Active, Cohort::Inactive] as $cohort) {
            $publisher = $this->publisher();
            (new Sweep($this->database, new DeletePublisher($publisher, new Queue(self::QUEUE)), $this->clock, self::ELSEWHERE))
                ->run($cohort, Slot::full(), $this->cutoff);
            \array_push($elsewhere, ...$this->projects($publisher));
        }

        $this->assertSame($this->sorted($this->elsewhere), $this->sorted($elsewhere));
    }

    public function testInactiveBackpressureWaitsForTheQueueToDrain(): void
    {
        $publisher = new QueuePublisher($this->clock, 50, 1000);
        $start = (float) $this->clock->now()->format('U.u');
        $deadline = $this->clock->now()->getTimestamp() + Cadence::HOUR;

        $outcome = $this->sweep($publisher)->run(Cohort::Inactive, Slot::full(), $this->cutoff, $deadline);

        $this->assertSame($this->sorted($this->inactive), $this->sorted($this->projects($publisher)));
        $this->assertSame(\count($this->inactive), $outcome->enqueued);
        $this->assertSame(0, $outcome->dropped);
        $this->assertGreaterThan(0, $outcome->waited);
        // 1000 queued at 50 a second falls below the backlog of 100 after 18 seconds.
        $this->assertGreaterThan(18.0, (float) $this->clock->now()->format('U.u') - $start);
    }

    public function testInactiveDropsTheRemainingBlocksAtTheDeadline(): void
    {
        // Two publishes fill the queue to the backlog and nothing drains it.
        $publisher = new QueuePublisher($this->clock, 0, Sweep::BACKLOG - 2);
        $deadline = $this->clock->now()->getTimestamp() + 5;

        // Slot 0 of 3 owns blocks 0, 3, 6, ... 33: twelve of them.
        $outcome = $this->sweep($publisher)->run(Cohort::Inactive, new Slot(0, 3), $this->cutoff, $deadline);

        $this->assertSame(['old-2', 'never-3002'], $this->projects($publisher));
        $this->assertSame(2, $outcome->enqueued);
        $this->assertSame(10, $outcome->dropped);
        $this->assertTrue($outcome->truncated);
        $this->assertGreaterThan(0, $outcome->waited);
        $this->assertGreaterThanOrEqual($deadline, $this->clock->now()->getTimestamp());
    }

    public function testInactiveBlockDroppedInOneCycleIsReachedFirstInTheNext(): void
    {
        // Slot 1 of 12 owns blocks 1, 13 and 25.
        $anchor = $this->clock->now()->getTimestamp();
        $first = Slot::at($anchor + (4 * 12 + 1) * Cadence::HOUR, $anchor, Cadence::HOUR, 12);
        $second = Slot::at($anchor + (5 * 12 + 1) * Cadence::HOUR, $anchor, Cadence::HOUR, 12);
        $this->assertSame(1, $first->index);
        $this->assertSame(1, $second->index);

        $backlogged = new QueuePublisher($this->clock, 0, Sweep::BACKLOG - 1);
        $outcome = $this->sweep($backlogged)->run(Cohort::Inactive, $first, $this->cutoff, $this->clock->now()->getTimestamp() + 5);

        $this->assertSame(['never-13002'], $this->projects($backlogged));
        $this->assertSame(2, $outcome->dropped);

        $publisher = $this->publisher();
        $outcome = $this->sweep($publisher)->run(Cohort::Inactive, $second, $this->cutoff, $this->clock->now()->getTimestamp() + Cadence::HOUR);

        $this->assertSame(['never-25002', 'never-1002', 'never-13002'], $this->projects($publisher));
        $this->assertSame(0, $outcome->dropped);
    }

    public function testHashWalkStopsAtTheDeadlineWithoutCountingBlocks(): void
    {
        $this->database = $this->seed(new Uuid7Memory());
        $publisher = new QueuePublisher($this->clock, 0, Sweep::BACKLOG);

        $outcome = $this->sweep($publisher)->run(Cohort::Inactive, new Slot(0, 3), $this->cutoff, $this->clock->now()->getTimestamp() + 5);

        $this->assertSame([], $this->projects($publisher));
        $this->assertSame(0, $outcome->dropped);
        $this->assertTrue($outcome->truncated);
        $this->assertGreaterThan(0, $outcome->waited);
    }

    public function testUuid7SequencesSelectBySlotHash(): void
    {
        $this->database = $this->seed(new Uuid7Memory());
        $this->assertSame(ColumnType::Uuid7, $this->database->getIdAttributeType());

        $cadence = Cadence::fromIntervals(86400, 604800);
        $reached = [];
        $owners = [];
        $slotsByBlock = [];
        foreach ($this->ticks($cadence, Cohort::Active) as $slot) {
            $publisher = $this->publisher();
            $this->sweep($publisher)->run(Cohort::Active, $slot, $this->cutoff);

            foreach ($this->projects($publisher) as $id) {
                $project = $this->database->skipFilters(fn (): Document => $this->database->getDocument('projects', $id), APP_PROJECTS_SUBQUERIES);
                $this->assertSame($slot->index, Sweep::owner($project, $slot->count, ColumnType::Uuid7), $id);
                $owners[$slot->index] = true;
                $slotsByBlock[\intdiv((int) $project->getSequence(), Sweep::BLOCK)][$slot->index] = true;
                $reached[] = $id;
            }
        }

        $this->assertSame($this->sorted($this->active), $this->sorted($reached));
        $this->assertGreaterThan(1, \count($owners));
        // A UUID7 sequence has no integer order to block on, so neighbours by sequence must not travel together.
        $this->assertNotEmpty(\array_filter($slotsByBlock, fn (array $slots): bool => \count($slots) > 1));
    }

    public function testProjectFilterSelectsOnlyThatProject(): void
    {
        $expectations = [
            Cohort::Active->value => ['active-5001' => ['active-5001'], 'edge-5003' => ['edge-5003'], 'never-5002' => [], 'elsewhere-5500' => [], 'missing' => []],
            Cohort::Inactive->value => ['never-5002' => ['never-5002'], 'old-6002' => ['old-6002'], 'active-5001' => [], 'edge-5003' => [], 'elsewhere-5500' => [], 'missing' => []],
        ];

        foreach ($expectations as $cohort => $projects) {
            foreach ($projects as $project => $expected) {
                $publisher = $this->publisher();

                $this->sweep($publisher)->run(Cohort::from($cohort), Slot::full(), $this->cutoff, null, $project);

                $this->assertSame($expected, $this->projects($publisher), $cohort . ' ' . $project);
            }
        }
    }

    /**
     * Every slot of one cycle, as consecutive hourly ticks reach them.
     *
     * @return list<Slot>
     */
    private function ticks(Cadence $cadence, Cohort $cohort): array
    {
        $anchor = $this->clock->now()->getTimestamp();
        $slots = [];
        for ($tick = 0; $tick < $cadence->slots($cohort); $tick++) {
            $slots[] = Slot::at($anchor + $tick * $cadence->tick, $anchor, $cadence->tick, $cadence->slots($cohort));
        }

        $this->assertSame(\range(0, $cadence->slots($cohort) - 1), \array_map(fn (Slot $slot): int => $slot->index, $slots));

        return $slots;
    }

    private function publisher(): QueuePublisher
    {
        return new QueuePublisher($this->clock, 1000);
    }

    private function sweep(QueuePublisher $publisher): Sweep
    {
        return new Sweep($this->database, new DeletePublisher($publisher, new Queue(self::QUEUE)), $this->clock, self::REGION);
    }

    /**
     * @return list<string>
     */
    private function projects(QueuePublisher $publisher): array
    {
        return \array_map(fn (array $payload): string => $payload['project']['$id'], $publisher->payloads(self::QUEUE));
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        \sort($ids);

        return $ids;
    }

    /**
     * Each block holds a recently accessed project, an inactive one (31 days
     * old in even blocks, never accessed in odd ones) and one in another
     * region; every fifth block adds one accessed exactly at the cutoff.
     * Blocks 7 and 8 also hold projects on their boundaries.
     */
    private function seed(Adapter $adapter): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = (new Database($adapter, new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_console')
            ->setAuthorization($authorization);
        $database->create();

        // Projects are written and read back with APP_PROJECTS_SUBQUERIES skipped, as Sweep reads them, so no
        // collection a subquery joins has to exist, whichever subqueries the projects collection declares.
        $collections = Config::getParam('collections', [])['console'];
        $database->createCollection(Collection::create(
            id: 'projects',
            attributes: $collections['projects']['attributes'],
            indexes: $collections['projects']['indexes'],
        ));

        $now = $this->clock->now();
        $recent = DateTime::format(\DateTime::createFromImmutable($now->modify('-1 day')));
        $old = DateTime::format(\DateTime::createFromImmutable($now->modify('-31 days')));

        $this->active = [];
        $this->inactive = [];
        $this->elsewhere = [];
        $rows = [];
        for ($block = 0; $block < self::BLOCKS; $block++) {
            $base = $block * Sweep::BLOCK;
            $rows[] = ['active', $base + 1, $recent, self::REGION];
            $rows[] = $block % 2 === 0 ? ['old', $base + 2, $old, self::REGION] : ['never', $base + 2, null, self::REGION];
            if ($block % 5 === 0) {
                $rows[] = ['edge', $base + 3, $this->cutoff, self::REGION];
            }
            $rows[] = ['elsewhere', $base + 500, $block % 2 === 0 ? $recent : $old, self::ELSEWHERE];
        }
        $rows[] = ['active', 7999, $recent, self::REGION];
        $rows[] = ['old', 8000, $old, self::REGION];

        $database->skipValidation(function () use ($database, $rows): void {
            foreach ($rows as [$kind, $sequence, $accessedAt, $region]) {
                $id = $kind . '-' . $sequence;
                $database->skipFilters(fn (): Document => $database->createDocument('projects', new Document([
                    '$id' => $id,
                    '$sequence' => (string) $sequence,
                    'name' => $id,
                    'region' => $region,
                    'accessedAt' => $accessedAt,
                    'teamId' => 'team',
                    'teamInternalId' => '1',
                    'database' => 'database_db_' . $region,
                ])), APP_PROJECTS_SUBQUERIES);

                match (true) {
                    $region !== self::REGION => $this->elsewhere[] = $id,
                    $kind === 'old', $kind === 'never' => $this->inactive[] = $id,
                    default => $this->active[] = $id,
                };
            }
        });

        return $database;
    }
}
