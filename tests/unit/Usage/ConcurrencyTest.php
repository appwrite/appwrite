<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Concurrency;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;

final class ConcurrencyTest extends TestCase
{
    /** Pinned so exactly one whole bucket, 11:55–12:00, has closed behind the lag. */
    private const string NOW = '2026-09-08 12:07:30';
    private const string BUCKET = '2026-09-08 11:55:00';
    private const string PREVIOUS_SAMPLE = '2026-09-08 11:50:00';

    public function testLevelIsTheWindowedSumNotTheCarriedGauge(): void
    {
        $written = $this->sample(
            gauge: [self::metric('356187', 9999, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('356187', 50, '2026-09-08 05:50:00'),   // older than the 6h window
                self::metric('356187', 3, '2026-09-08 08:00:00'),
                self::metric('356187', -1, self::BUCKET),
            ],
        );

        $this->assertSame([['tenant' => '356187', 'value' => 2, 'time' => self::BUCKET]], $written);
    }

    public function testLeakedOpensAgeOutOfTheWindow(): void
    {
        // The +40 sits exactly one window back, so it is outside (window is open at the start).
        $written = $this->sample(
            gauge: [self::metric('356187', 40, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('356187', 40, '2026-09-08 05:55:00'),
                self::metric('356187', 0, self::BUCKET),
            ],
        );

        $this->assertSame([['tenant' => '356187', 'value' => 0, 'time' => self::BUCKET]], $written);
    }

    public function testEachCatchUpBucketGetsItsOwnWindow(): void
    {
        $written = $this->sample(
            gauge: [self::metric('356187', 0, '2026-09-08 11:45:00')],
            events: [
                self::metric('356187', 7, '2026-09-08 05:55:00'),   // inside 11:50's window, outside 11:55's
                self::metric('356187', 2, '2026-09-08 11:50:00'),
                self::metric('356187', 1, self::BUCKET),
            ],
        );

        $this->assertSame([
            ['tenant' => '356187', 'value' => 9, 'time' => '2026-09-08 11:50:00'],
            ['tenant' => '356187', 'value' => 3, 'time' => self::BUCKET],
        ], $written);
    }

    public function testBucketWithoutDeltasIsNotSampled(): void
    {
        // The window still holds the +3, but nothing happened in 11:55.
        $written = $this->sample(
            gauge: [self::metric('356187', 3, self::PREVIOUS_SAMPLE)],
            events: [self::metric('356187', 3, self::PREVIOUS_SAMPLE)],
        );

        $this->assertSame([], $written);
    }

    public function testDenseReadIsPagedUntilExhausted(): void
    {
        // Page one fills mid-way through 11:55, so that bucket is dropped and
        // re-read whole on page two rather than sampled short.
        $written = $this->sample(
            gauge: [self::metric('356187', 0, '2026-09-08 11:45:00')],
            events: [
                self::metric('356187', 1, '2026-09-08 11:50:00'),
                self::metric('478539', 1, '2026-09-08 11:50:00'),
                self::metric('356187', 1, self::BUCKET),
                self::metric('478539', -1, self::BUCKET),
            ],
            pageSize: 3,
        );

        $this->assertSame([
            ['tenant' => '356187', 'value' => 1, 'time' => '2026-09-08 11:50:00'],
            ['tenant' => '356187', 'value' => 2, 'time' => self::BUCKET],
            ['tenant' => '478539', 'value' => 1, 'time' => '2026-09-08 11:50:00'],
            ['tenant' => '478539', 'value' => 0, 'time' => self::BUCKET],
        ], $written);
    }

    public function testDenseHistoryBeforeTheFirstBucketDoesNotStall(): void
    {
        // The window's history alone overruns a page. Reading it in one capped
        // query returns nothing the first bucket can be sampled from, and since
        // the resume point never moves, every later run reads the same page.
        $written = $this->sample(
            gauge: [self::metric('356187', 0, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('356187', 1, '2026-09-08 06:05:00'),
                self::metric('356187', 1, '2026-09-08 06:10:00'),
                self::metric('356187', 1, self::BUCKET),
            ],
            pageSize: 2,
        );

        $this->assertSame([['tenant' => '356187', 'value' => 3, 'time' => self::BUCKET]], $written);
    }

    public function testSingleBucketOverThePageSizeIsNotSampled(): void
    {
        // No page size gets past one bucket that fills a page, so the fold
        // writes nothing instead of sampling it short.
        $written = $this->sample(
            gauge: [self::metric('356187', 0, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('356187', 1, self::BUCKET),
                self::metric('478539', 1, self::BUCKET),
                self::metric('605738', 1, self::BUCKET),
            ],
            pageSize: 2,
        );

        $this->assertSame([], $written);
    }

    /**
     * @param list<Metric> $gauge rows every TYPE_GAUGE read returns
     * @param list<Metric> $events the whole event stream; reads are served from it
     *                             time-ordered and cut to the page size, as ClickHouse would
     * @return list<array{tenant: string, value: int, time: string}> gauge rows the fold wrote
     */
    private function sample(array $gauge, array $events, int $pageSize = 50_000): array
    {
        $usage = $this->createStub(Usage::class);

        \usort($events, fn (Metric $a, Metric $b) => (string) $a->getAttribute('time') <=> (string) $b->getAttribute('time'));

        $usage->method('findAcrossTenants')->willReturnCallback(
            function (array $queries, ?string $type) use ($gauge, $events, $pageSize): array {
                if ($type === Usage::TYPE_GAUGE) {
                    return $gauge;
                }

                $from = $to = null;
                foreach ($queries as $query) {
                    if ($query->getAttribute() !== 'time') {
                        continue;
                    }
                    if ($query->getMethod() === Method::GreaterThanEqual) {
                        $from = (string) $query->getValue();
                    }
                    if ($query->getMethod() === Method::LessThan) {
                        $to = (string) $query->getValue();
                    }
                }

                $page = [];
                foreach ($events as $event) {
                    $time = (string) $event->getAttribute('time');
                    if (($from !== null && $time < $from) || ($to !== null && $time >= $to)) {
                        continue;
                    }
                    $page[] = $event;
                    if (\count($page) >= $pageSize) {
                        break;
                    }
                }

                return $page;
            }
        );

        $written = [];
        $usage->method('addBatch')->willReturnCallback(function (array $rows) use (&$written): bool {
            foreach ($rows as $index => $row) {
                // The ClickHouse adapter rejects a tenant that is not a non-empty
                // string. Tenants are project sequences, so an array key holding
                // one is an int, and a laxer double lets that reach production.
                if (!\is_string($row['tenant']) || $row['tenant'] === '') {
                    throw new \RuntimeException(
                        "Metric #{$index}: 'tenant' is required (non-empty string) when shared tables are enabled"
                    );
                }

                $written[] = [
                    'tenant' => $row['tenant'],
                    'value' => $row['value'],
                    'time' => $row['time']->format('Y-m-d H:i:s'),
                ];
            }

            return true;
        });

        (new Concurrency($pageSize))->sample($usage, new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        return $written;
    }

    private static function metric(string $tenant, int $value, string $time): Metric
    {
        return new Metric(['tenant' => $tenant, 'value' => $value, 'time' => $time]);
    }
}
