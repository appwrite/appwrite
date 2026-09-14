<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Stats;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Source\Row;

final class StatsTest extends TestCase
{
    public function testSnapshotListsConcurrencyAndActiveProjectsOfTheRegion(): void
    {
        $database = new StatsDatabase([
            $this->project('recent', 'default', '-1 hour'),
            $this->project('stale', 'default', '-2 days'),
            $this->project('elsewhere', 'fra', '-1 hour'),
        ]);

        $ids = $this->ids((new Stats($database, 3600))->snapshot());

        $this->assertContains(Stats::CONCURRENCY, $ids);
        $this->assertContains('recent', $ids);
        $this->assertNotContains('stale', $ids);
        $this->assertNotContains('elsewhere', $ids);
    }

    public function testSinceListsProjectsAccessedAfterTheMoment(): void
    {
        $database = new StatsDatabase([
            $this->project('before', 'default', '-10 minutes'),
            $this->project('after', 'default', '-1 minute'),
        ]);

        $ids = $this->ids((new Stats($database, 3600))->since(new \DateTimeImmutable('-5 minutes')));

        $this->assertSame(['after'], $ids);
    }

    public function testSnapshotPagesThroughEveryProject(): void
    {
        $projects = [];
        for ($i = 0; $i < 2500; $i++) {
            $projects[] = $this->project("project{$i}", 'default', '-1 hour');
        }
        $database = new StatsDatabase($projects);

        $ids = $this->ids((new Stats($database, 3600))->snapshot());

        $this->assertCount(2501, $ids);
        $this->assertCount(2501, array_unique($ids));
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
        $this->assertCount(1, $concurrency->trigger->occurrencesBetween($start, $end));

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

    private function project(string $id, string $region, string $accessed): Document
    {
        return new Document([
            '$id' => $id,
            'region' => $region,
            'accessedAt' => (new \DateTime($accessed))->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * @param iterable<Row> $rows
     * @return list<string>
     */
    private function ids(iterable $rows): array
    {
        return array_map(fn (Row $row): string => $row->id, iterator_to_array($rows, false));
    }
}

/**
 * Answers project listings the way the platform database would: region and
 * accessedAt filters, `$sequence` order, limit and cursor pagination.
 */
final class StatsDatabase extends Database
{
    /** @var list<Document> */
    private array $projects = [];

    /** @param list<Document> $projects */
    public function __construct(array $projects)
    {
        foreach ($projects as $sequence => $project) {
            $this->projects[] = new Document(array_merge($project->getArrayCopy(), ['$sequence' => (string) ($sequence + 1)]));
        }
    }

    public function skipFilters(callable $callback, ?array $filters = null): mixed
    {
        return $callback();
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        $limit = PHP_INT_MAX;
        $after = 0;
        $projects = $this->projects;

        foreach ($queries as $query) {
            switch ($query->getMethod()) {
                case Query::TYPE_EQUAL:
                    $projects = array_filter($projects, fn (Document $p): bool => in_array($p->getAttribute($query->getAttribute()), $query->getValues(), true));
                    break;
                case Query::TYPE_GREATER_EQUAL:
                    $projects = array_filter($projects, fn (Document $p): bool => $p->getAttribute($query->getAttribute()) >= $query->getValue());
                    break;
                case Query::TYPE_LIMIT:
                    $limit = $query->getValue();
                    break;
                case Query::TYPE_CURSOR_AFTER:
                    $after = (int) $query->getValue()->getSequence();
                    break;
            }
        }

        $projects = array_filter($projects, fn (Document $p): bool => (int) $p->getSequence() > $after);
        usort($projects, fn (Document $a, Document $b): int => (int) $a->getSequence() <=> (int) $b->getSequence());

        return array_slice($projects, 0, $limit);
    }
}
