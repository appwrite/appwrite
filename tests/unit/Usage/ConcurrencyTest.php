<?php

namespace Tests\Unit\Usage;

use Appwrite\Usage\Concurrency;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;

class ConcurrencyTest extends TestCase
{
    /** Pinned so exactly one whole bucket, 11:55–12:00, has closed behind the lag. */
    private const string NOW = '2026-09-08 12:07:30';
    private const string BUCKET = '2026-09-08 11:55:00';
    private const string PREVIOUS_SAMPLE = '2026-09-08 11:50:00';

    /** @var list<array{0: string|null, 1: list<Query>}> every findAcrossTenants call, in order */
    private array $reads = [];

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

    public function testEventReadCoversOneWindowBeforeTheFirstEmittedBucket(): void
    {
        $this->sample(gauge: [self::metric('t1', 0, self::PREVIOUS_SAMPLE)], events: []);

        $eventReads = \array_values(\array_filter($this->reads, fn ($r) => $r[0] === Usage::TYPE_EVENT));
        $this->assertCount(1, $eventReads);

        $bounds = [];
        foreach ($eventReads[0][1] as $query) {
            if ($query->getAttribute() === 'time' && \in_array($query->getMethod(), [Method::GreaterThanEqual, Method::LessThan], true)) {
                $bounds[$query->getMethod()->value] = $query->getValue();
            }
        }

        $this->assertSame([
            Method::GreaterThanEqual->value => '2026-09-08 06:00:00',
            Method::LessThan->value => '2026-09-08 12:00:00',
        ], $bounds);
    }

    /**
     * @param list<Metric> $gauge rows every TYPE_GAUGE read returns
     * @param list<Metric> $events rows every TYPE_EVENT read returns
     * @return list<array{tenant: string, value: int, time: string}> gauge rows the fold wrote
     */
    private function sample(array $gauge, array $events): array
    {
        $this->reads = [];
        $usage = $this->createStub(Usage::class);

        $usage->method('findAcrossTenants')->willReturnCallback(function (array $queries, ?string $type) use ($gauge, $events): array {
            $this->reads[] = [$type, $queries];

            return $type === Usage::TYPE_GAUGE ? $gauge : $events;
        });

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

        (new Concurrency())->sample($usage, new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        return $written;
    }

    private static function metric(string $tenant, int $value, string $time): Metric
    {
        return new Metric(['tenant' => $tenant, 'value' => $value, 'time' => $time]);
    }
}
