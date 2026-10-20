<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Backlog;
use Appwrite\Maintenance\Sweep;
use Appwrite\Schedule\Source\Maintenance;
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
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Store\Memory as MemoryStore;

require_once __DIR__ . '/../../../app/init.php';

final class SweepTest extends TestCase
{
    private const string REGION = 'east';

    private const string ELSEWHERE = 'west';

    private const string QUEUE = 'v1-deletes';

    private const int SLOTS = 2016;

    private const int SPACING = 1009;

    private \DateTimeImmutable $week;

    private TestClock $clock;

    private Database $database;

    /** @var array<string, int> inactive project => due of the slice that owns it */
    private array $inactive = [];

    /** @var list<string> */
    private array $excluded = [];

    protected function setUp(): void
    {
        $this->week = new \DateTimeImmutable('2026-03-02 00:00:00', new \DateTimeZone('UTC'));
        $this->clock = new TestClock($this->week);
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
        // Block 2068 is past SLOTS blocks, so it wraps round to slot 52.
        $this->assertArrayHasKey('old-' . (1 + 205 * self::SPACING), $reached);
        $this->assertArrayNotHasKey('edge-' . (1 + 3 * self::SPACING), $reached);
        foreach ($publisher->payloads(self::QUEUE) as $payload) {
            $this->assertSame(DELETE_TYPE_MAINTENANCE, $payload['type']);
            $this->assertSame(self::REGION, $payload['project']['region']);
        }
    }

    public function testSliceAndCutoffComeFromTheOccurrence(): void
    {
        $due = $this->week->modify('+35 minutes');
        $this->clock = new TestClock($due->modify('+5 days'));
        $slot = $this->slot($due->getTimestamp());
        $base = $slot * Sweep::BLOCK;
        $wrapped = ($slot + self::SLOTS) * Sweep::BLOCK;
        $neighbour = (($slot + 1) % self::SLOTS) * Sweep::BLOCK;

        $this->database = $this->seed(new Memory(), [
            ['recent-' . ($base + 1), $base + 1, $due->modify('-29 days'), self::REGION],
            ['old-' . ($base + 2), $base + 2, $due->modify('-31 days'), self::REGION],
            ['never-' . ($base + 3), $base + 3, null, self::REGION],
            ['elsewhere-' . ($base + 4), $base + 4, null, self::ELSEWHERE],
            ['old-' . ($wrapped + 5), $wrapped + 5, $due->modify('-31 days'), self::REGION],
            ['old-' . ($neighbour + 6), $neighbour + 6, $due->modify('-31 days'), self::REGION],
        ]);

        $publisher = $this->publisher();
        ($this->sweep($publisher))([$this->occurrence($due)]);

        $this->assertSame(
            $this->sorted(['old-' . ($base + 2), 'never-' . ($base + 3), 'old-' . ($wrapped + 5)]),
            $this->sorted($this->projects($publisher)),
        );
    }

    public function testDocumentSequencesSpreadByHash(): void
    {
        $this->database = $this->population(new Uuid7Memory(), 72, fn (string $id, int $sequence): int => \crc32($id) % self::SLOTS);
        $this->assertSame(ColumnType::Uuid7, $this->database->getIdAttributeType());

        $publisher = $this->publisher();
        $reached = $this->week($this->sweep($publisher), $publisher);

        $this->assertSame($this->keyed($this->inactive), $this->keyed(\array_map(fn (array $dues): int => $dues[0], $reached)));
        $this->assertSame([], \array_keys(\array_filter($reached, fn (array $dues): bool => \count($dues) > 1)));
        $this->assertSame([], \array_values(\array_intersect(\array_keys($reached), $this->excluded)));
    }

    public function testBacklogDefersTheSliceUntilTheQueueDrains(): void
    {
        $due = $this->week->modify('+1 hour');
        $this->clock = new TestClock($due->modify('-30 seconds'));
        $expected = $this->slice($due, 4);
        $this->database = $this->seed(new Memory(), [
            ...$this->rows($expected, $due),
            ...$this->rows($this->slice($due->modify('+5 minutes'), 3, 'later'), $due),
        ]);

        $store = new MemoryStore();
        $publisher = new QueuePublisher($this->clock, 1, 150);

        $sweep = $this->sweep($publisher);
        $scheduler = $this->scheduler($store);
        $deferred = null;
        try {
            $scheduler->run(function (array $occurrences) use ($sweep, $scheduler): void {
                $sweep($occurrences);
                $scheduler->stop();
            });
        } catch (Backlog $backlog) {
            $deferred = $backlog;
        }

        $this->assertInstanceOf(Backlog::class, $deferred);
        $this->assertSame([], $this->projects($publisher));
        $this->assertLessThanOrEqual((float) $due->getTimestamp(), $store->load()?->coveredUntil);

        $this->clock->advance(200);

        $sweep = $this->sweep($publisher);
        $scheduler = $this->scheduler($store);
        $scheduler->run(function (array $occurrences) use ($sweep, $scheduler): void {
            $sweep($occurrences);
            $scheduler->stop();
        });

        $this->assertSame($this->sorted(\array_keys($expected)), $this->sorted($this->projects($publisher)));
        $this->assertGreaterThan((float) $due->getTimestamp(), $store->load()?->coveredUntil);
    }

    public function testRedeliveredTickDoesNotRepeatAHandledSlice(): void
    {
        $first = $this->week->modify('+2 hours');
        $second = $first->modify('+5 minutes');
        $this->clock = new TestClock($first->modify('-30 seconds'));
        $firstSlice = $this->slice($first, 6, 'first');
        $secondSlice = $this->slice($second, 3, 'second');
        $this->database = $this->seed(new Memory(), [...$this->rows($firstSlice, $first), ...$this->rows($secondSlice, $first)]);

        $store = new MemoryStore();
        // 95 queued: the first slice's six take it to the backlog before the second slice is reached.
        $publisher = new QueuePublisher($this->clock, 1, Sweep::BACKLOG - 5);
        $sweep = $this->sweep($publisher);

        $scheduler = $this->scheduler($store);
        $scheduler->reconcile();
        $this->assertSame([], $scheduler->tick());
        $this->assertTrue($scheduler->commit());

        $this->clock->advance(340);

        $handed = [];
        $deferred = null;
        try {
            $scheduler->run(function (array $occurrences) use ($sweep, $scheduler, &$handed): void {
                \array_push($handed, ...\array_map(fn (Occurrence $occurrence): int => $occurrence->due->getTimestamp(), $occurrences));
                $sweep($occurrences);
                $scheduler->stop();
            });
        } catch (Backlog $backlog) {
            $deferred = $backlog;
        }

        $this->assertInstanceOf(Backlog::class, $deferred);
        $this->assertSame([$first->getTimestamp(), $second->getTimestamp()], $handed);
        $this->assertSame($this->sorted(\array_keys($firstSlice)), $this->sorted($this->projects($publisher)));

        $this->clock->advance(150);
        $before = \count($this->projects($publisher));

        $handed = [];
        $scheduler = $this->scheduler($store);
        $scheduler->run(function (array $occurrences) use ($sweep, $scheduler, &$handed): void {
            \array_push($handed, ...\array_map(fn (Occurrence $occurrence): int => $occurrence->due->getTimestamp(), $occurrences));
            $sweep($occurrences);
            $scheduler->stop();
        });

        $this->assertSame([$first->getTimestamp(), $second->getTimestamp()], $handed);
        $this->assertSame($this->sorted(\array_keys($secondSlice)), $this->sorted(\array_slice($this->projects($publisher), $before)));
        $this->assertSame(
            $this->sorted([...\array_keys($firstSlice), ...\array_keys($secondSlice)]),
            $this->sorted($this->projects($publisher)),
        );
        $this->assertGreaterThan((float) $second->getTimestamp(), $store->load()?->coveredUntil);
    }

    /**
     * Sweeps every slice of the week in order, one occurrence per call, with the clock following the dues.
     *
     * @return array<string, list<int>> project => dues of the slices that enqueued it
     */
    private function week(Sweep $sweep, QueuePublisher $publisher): array
    {
        $reached = [];
        for ($slice = 0; $slice < self::SLOTS; $slice++) {
            $due = $this->week->modify('+' . ($slice * Sweep::INTERVAL) . ' seconds');
            $this->clock->advance((float) $due->getTimestamp() - (float) $this->clock->now()->format('U.u') + 1);

            $before = \count($publisher->payloads(self::QUEUE));
            $sweep([$this->occurrence($due)]);

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

        return $this->seed($adapter, $rows);
    }

    /**
     * Inactive projects in the first block the slot of $due owns, half of them never accessed.
     *
     * @return array<string, int> project => sequence
     */
    private function slice(\DateTimeImmutable $due, int $count, string $prefix = 'slice'): array
    {
        $base = $this->slot($due->getTimestamp()) * Sweep::BLOCK;
        $projects = [];
        for ($offset = 1; $offset <= $count; $offset++) {
            $projects[$prefix . '-' . ($base + $offset)] = $base + $offset;
        }

        return $projects;
    }

    /**
     * @param array<string, int> $projects
     * @return list<array{0: string, 1: int, 2: ?\DateTimeImmutable, 3: string}>
     */
    private function rows(array $projects, \DateTimeImmutable $due): array
    {
        $rows = [];
        foreach ($projects as $id => $sequence) {
            $rows[] = [$id, $sequence, $sequence % 2 === 0 ? null : $due->modify('-60 days'), self::REGION];
        }

        return $rows;
    }

    /**
     * Where the week's slices start: the due at which the slot comes round.
     */
    private function due(int $slot): \DateTimeImmutable
    {
        $offset = ($slot - $this->slot($this->week->getTimestamp()) + self::SLOTS) % self::SLOTS;

        return $this->week->modify('+' . ($offset * Sweep::INTERVAL) . ' seconds');
    }

    private function slot(int $due): int
    {
        return \intdiv($due, Sweep::INTERVAL) % self::SLOTS;
    }

    private function occurrence(\DateTimeImmutable $due): Occurrence
    {
        return new Occurrence(Maintenance::ID, $due, null, (string) Sweep::INTERVAL);
    }

    private function scheduler(MemoryStore $store): Scheduler
    {
        return new Scheduler(
            source: new Maintenance(Sweep::INTERVAL),
            store: $store,
            tickSeconds: 60,
            recoverSeconds: 3600,
            leaseSeconds: 600,
            clock: $this->clock,
        );
    }

    private function publisher(): QueuePublisher
    {
        return new QueuePublisher($this->clock, 1000);
    }

    private function sweep(QueuePublisher $publisher): Sweep
    {
        return new Sweep($this->database, new DeletePublisher($publisher, new Queue(self::QUEUE)), self::REGION, Sweep::INTERVAL, self::SLOTS);
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

    /**
     * @param list<array{0: string, 1: int, 2: ?\DateTimeImmutable, 3: string}> $rows
     */
    private function seed(Adapter $adapter, array $rows): Database
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

        $timezone = new \DateTimeZone(\date_default_timezone_get());

        $database->skipValidation(function () use ($database, $rows, $timezone): void {
            foreach ($rows as [$id, $sequence, $accessedAt, $region]) {
                $database->skipFilters(fn (): Document => $database->createDocument('projects', new Document([
                    '$id' => $id,
                    '$sequence' => (string) $sequence,
                    'name' => $id,
                    'region' => $region,
                    'accessedAt' => $accessedAt === null ? null : DateTime::format(\DateTime::createFromImmutable($accessedAt)->setTimezone($timezone)),
                    'teamId' => 'team',
                    'teamInternalId' => '1',
                    'database' => 'database_db_' . $region,
                ])), APP_PROJECTS_SUBQUERIES);
            }
        });

        return $database;
    }
}
