<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Stats;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Schedule\Source\Row;

final class StatsTest extends TestCase
{
    public function testSnapshotListsConcurrencyAndProjects(): void
    {
        $source = new Stats(new StatsDatabase(['alpha', 'beta']), 3600);

        $ids = array_map(fn (Row $row): string => $row->id, iterator_to_array($source->snapshot(), false));

        $this->assertSame([Stats::CONCURRENCY, 'alpha', 'beta'], $ids);
    }

    public function testSpreadsProjectsAcrossTheInterval(): void
    {
        $source = new Stats(new StatsDatabase([]), 3600);
        $start = new \DateTimeImmutable('2026-09-14 10:00:00', new \DateTimeZone('UTC'));
        $end = $start->modify('+3600 seconds');

        $concurrency = $source->make(new Row(Stats::CONCURRENCY, ''));
        $alpha = $source->make(new Row('alpha', ''));
        $beta = $source->make(new Row('beta', ''));

        $this->assertNull($concurrency->payload);
        $this->assertEquals([$start], $concurrency->trigger->occurrencesBetween($start, $end));

        $this->assertInstanceOf(Document::class, $alpha->payload);
        $this->assertSame('alpha', $alpha->payload->getId());
        $this->assertCount(1, $alpha->trigger->occurrencesBetween($start, $end));
        $this->assertCount(1, $beta->trigger->occurrencesBetween($start, $end));
        $this->assertNotEquals(
            $alpha->trigger->occurrencesBetween($start, $end),
            $beta->trigger->occurrencesBetween($start, $end),
        );
        $this->assertEquals(
            $alpha->trigger->occurrencesBetween($start, $end),
            $source->make(new Row('alpha', ''))->trigger->occurrencesBetween($start, $end),
        );
    }
}

final class StatsDatabase extends Database
{
    /** @param list<string> $ids */
    public function __construct(private readonly array $ids)
    {
    }

    public function skipFilters(callable $callback, ?array $filters = null): mixed
    {
        return $callback();
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        return array_map(fn (string $id): Document => new Document(['$id' => $id]), $this->ids);
    }
}
