<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Usage;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Schedule\Scheduler;

final class UsageTest extends TestCase
{
    private const int INTERVAL = 60;

    public function testEveryProjectBecomesItsOwnScheduleAlongsideTheSampler(): void
    {
        $scheduler = new Scheduler(source: $this->source(10), syncSeconds: 60, snapshotSeconds: 60);
        $scheduler->reconcile(true);

        $this->assertSame(11, $scheduler->count(), 'ten projects plus the concurrency sampler');
    }

    /**
     * Anchoring each project on its own creation time is what turns the
     * hourly stampede -- every project enqueued at once -- into a trickle.
     */
    public function testProjectsAreSpreadAcrossTheInterval(): void
    {
        $source = $this->source(self::INTERVAL);
        $offsets = [];

        foreach ($source->snapshot() as $row) {
            if ($row->id === Usage::CONCURRENCY) {
                continue;
            }

            $dues = $source->make($row)->trigger->occurrencesBetween(
                new \DateTimeImmutable('@1800000000'),
                new \DateTimeImmutable('@1800000060'),
            );

            $offsets[] = (int) $dues[0]->format('U') % self::INTERVAL;
        }

        $this->assertCount(self::INTERVAL, $offsets);
        $this->assertSame($offsets, \array_unique($offsets), 'no two projects share an offset');
    }

    public function testTheSamplerIsNotAnchoredToAnyProject(): void
    {
        $source = $this->source(1);
        $entry = $source->make(new \Utopia\Schedule\Source\Row(id: Usage::CONCURRENCY, version: '1'));

        $this->assertNull($entry->payload, 'the sampler carries no project');
    }

    /**
     * The version must not move when a project is merely accessed, or every
     * sync would re-make every active project.
     */
    public function testRowsAreVersionedOnConfigChangesNotAccess(): void
    {
        foreach ($this->source(1)->snapshot() as $row) {
            if ($row->id === Usage::CONCURRENCY) {
                continue;
            }

            $this->assertSame('2026-01-01T00:00:00.000+00:00', $row->version);
        }
    }

    private function source(int $projects): Usage
    {
        return new class ($this->createStub(Database::class), self::INTERVAL, 'fra', $projects) extends Usage {
            public function __construct(Database $dbForPlatform, int $seconds, string $region, private int $projects)
            {
                parent::__construct($dbForPlatform, $seconds, $region);
            }

            /**
             * @param array<mixed> $queries
             * @return array<Document>
             */
            protected function page(array $queries): array
            {
                $page = [];

                for ($i = 0; $i < $this->projects; $i++) {
                    $page[] = new Document([
                        '$id' => 'project' . $i,
                        '$sequence' => (string) ($i + 1),
                        // one second apart, so the anchors spread
                        '$createdAt' => \gmdate('Y-m-d\TH:i:s.v\Z', 1700000000 + $i),
                        '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
                    ]);
                }

                $this->projects = 0; // a single page

                return $page;
            }
        };
    }
}
