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

    public static function setUpBeforeClass(): void
    {
        // app/init defines these when the suite boots through it; needed when this file runs alone.
        \defined('METRIC_REALTIME_CONNECTIONS') || \define('METRIC_REALTIME_CONNECTIONS', 'realtime.connections');
        \defined('REALTIME_CONCURRENCY_INTERVAL') || \define('REALTIME_CONCURRENCY_INTERVAL', '5m');
        \defined('REALTIME_CONCURRENCY_LAG_SECONDS') || \define('REALTIME_CONCURRENCY_LAG_SECONDS', 300);
    }

    public function testLevelIsTheWindowedSumNotTheCarriedGauge(): void
    {
        $written = $this->sample(
            gauge: [self::metric('t1', 9999, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('t1', 50, '2026-09-08 05:50:00'),   // older than the 6h window
                self::metric('t1', 3, '2026-09-08 08:00:00'),
                self::metric('t1', -1, self::BUCKET),
            ],
        );

        $this->assertSame([['tenant' => 't1', 'value' => 2, 'time' => self::BUCKET]], $written);
    }

    public function testLeakedOpensAgeOutOfTheWindow(): void
    {
        // The +40 sits exactly one window back, so it is outside (window is open at the start).
        $written = $this->sample(
            gauge: [self::metric('t1', 40, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('t1', 40, '2026-09-08 05:55:00'),
                self::metric('t1', 0, self::BUCKET),
            ],
        );

        $this->assertSame([['tenant' => 't1', 'value' => 0, 'time' => self::BUCKET]], $written);
    }

    public function testEachCatchUpBucketGetsItsOwnWindow(): void
    {
        $written = $this->sample(
            gauge: [self::metric('t1', 0, '2026-09-08 11:45:00')],
            events: [
                self::metric('t1', 7, '2026-09-08 05:55:00'),   // inside 11:50's window, outside 11:55's
                self::metric('t1', 2, '2026-09-08 11:50:00'),
                self::metric('t1', 1, self::BUCKET),
            ],
        );

        $this->assertSame([
            ['tenant' => 't1', 'value' => 9, 'time' => '2026-09-08 11:50:00'],
            ['tenant' => 't1', 'value' => 3, 'time' => self::BUCKET],
        ], $written);
    }

    public function testBucketWithoutDeltasIsNotSampled(): void
    {
        // The window still holds the +3, but nothing happened in 11:55.
        $written = $this->sample(
            gauge: [self::metric('t1', 3, self::PREVIOUS_SAMPLE)],
            events: [self::metric('t1', 3, self::PREVIOUS_SAMPLE)],
        );

        $this->assertSame([], $written);
    }

    public function testDenseReadIsPagedUntilExhausted(): void
    {
        // Page one fills mid-way through 11:55, so that bucket is dropped and
        // re-read whole on page two rather than sampled short.
        $written = $this->sample(
            gauge: [self::metric('t1', 0, '2026-09-08 11:45:00')],
            events: [
                self::metric('t1', 1, '2026-09-08 11:50:00'),
                self::metric('t2', 1, '2026-09-08 11:50:00'),
                self::metric('t1', 1, self::BUCKET),
                self::metric('t2', -1, self::BUCKET),
            ],
            pageSize: 3,
        );

        $this->assertSame([
            ['tenant' => 't1', 'value' => 1, 'time' => '2026-09-08 11:50:00'],
            ['tenant' => 't1', 'value' => 2, 'time' => self::BUCKET],
            ['tenant' => 't2', 'value' => 1, 'time' => '2026-09-08 11:50:00'],
            ['tenant' => 't2', 'value' => 0, 'time' => self::BUCKET],
        ], $written);
    }

    public function testDenseHistoryBeforeTheFirstBucketDoesNotStall(): void
    {
        // The window's history alone overruns a page. Reading it in one capped
        // query returns nothing the first bucket can be sampled from, and since
        // the resume point never moves, every later run reads the same page.
        $written = $this->sample(
            gauge: [self::metric('t1', 0, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('t1', 1, '2026-09-08 06:05:00'),
                self::metric('t1', 1, '2026-09-08 06:10:00'),
                self::metric('t1', 1, self::BUCKET),
            ],
            pageSize: 2,
        );

        $this->assertSame([['tenant' => 't1', 'value' => 3, 'time' => self::BUCKET]], $written);
    }

    public function testSingleBucketOverThePageSizeIsNotSampled(): void
    {
        // No page size gets past one bucket that fills a page, so the fold
        // writes nothing instead of sampling it short.
        $written = $this->sample(
            gauge: [self::metric('t1', 0, self::PREVIOUS_SAMPLE)],
            events: [
                self::metric('t1', 1, self::BUCKET),
                self::metric('t2', 1, self::BUCKET),
                self::metric('t3', 1, self::BUCKET),
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
            foreach ($rows as $row) {
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
