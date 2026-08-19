<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Concurrency;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Usage\Fakes\Adapter;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;

final class ConcurrencyTest extends TestCase
{
    public function testDeltasFoldIntoARunningLevelPerTenant(): void
    {
        $adapter = new Adapter();
        $adapter->crossTenantRows[Usage::TYPE_GAUGE] = [
            // lastLevels(): tenant 7 carries a level of 5, tenant 9 none.
            [$this->row('7', 5, '2026-04-09 11:55:00')],
            // lastSampleAt()
            [$this->row('7', 5, '2026-04-09 11:55:00')],
        ];
        $adapter->crossTenantRows[Usage::TYPE_EVENT] = [[
            $this->row('7', 2, '2026-04-09 12:00:00'),
            $this->row('9', 3, '2026-04-09 12:00:00'),
            $this->row('7', -1, '2026-04-09 12:05:00'),
        ]];

        $written = (new Concurrency())->sample(new Usage($adapter));

        $this->assertSame(3, $written);
        $this->assertCount(1, $adapter->batches);
        $this->assertSame(Usage::TYPE_GAUGE, $adapter->batches[0]['type']);

        $samples = $adapter->batches[0]['metrics'];
        // 5 + 2 = 7, then a fresh tenant at 3, then 7 - 1 = 6.
        $this->assertSame([7, 3, 6], array_column($samples, 'value'));
        $this->assertSame(['7', '9', '7'], array_column($samples, 'tenant'));
    }

    /** A level is a count of open connections, so lost deltas must not go negative. */
    public function testLostDeltasClampTheLevelAtZero(): void
    {
        $adapter = new Adapter();
        $adapter->crossTenantRows[Usage::TYPE_EVENT] = [[
            $this->row('7', -12, '2026-04-09 12:00:00'),
        ]];

        (new Concurrency())->sample(new Usage($adapter));

        $this->assertSame([0], array_column($adapter->batches[0]['metrics'], 'value'));
    }

    public function testNoDeltasWritesNothing(): void
    {
        $adapter = new Adapter();

        $this->assertSame(0, (new Concurrency())->sample(new Usage($adapter)));
        $this->assertSame([], $adapter->batches);
    }

    public function testRowsWithoutATenantAreSkipped(): void
    {
        $adapter = new Adapter();
        $adapter->crossTenantRows[Usage::TYPE_EVENT] = [[
            $this->row('', 4, '2026-04-09 12:00:00'),
            $this->row('7', 4, '2026-04-09 12:00:00'),
        ]];

        (new Concurrency())->sample(new Usage($adapter));

        $this->assertSame(['7'], array_column($adapter->batches[0]['metrics'], 'tenant'));
    }

    /**
     * With no prior sample the window opens one scheduling interval back, but
     * never shorter than one bucket -- a sub-bucket window would close before
     * any whole bucket had elapsed and sample nothing, forever.
     */
    public function testFirstRunWindowIsFlooredAtTheBucketSize(): void
    {
        putenv('_APP_STATS_RESOURCES_INTERVAL=60');

        try {
            $adapter = new Adapter();
            $adapter->crossTenantRows[Usage::TYPE_EVENT] = [[
                $this->row('7', 1, '2026-04-09 12:00:00'),
            ]];

            $this->assertSame(1, (new Concurrency())->sample(new Usage($adapter)));

            $event = null;
            foreach ($adapter->crossTenantCalls as $call) {
                if ($call['type'] === Usage::TYPE_EVENT) {
                    $event = $call;
                }
            }

            $this->assertNotNull($event, 'the delta read never happened');

            $bounds = [];
            foreach ($event['queries'] as $query) {
                // `time` also carries the interval grouping, which has no values.
                if ($query->getAttribute() === 'time' && $query->getValues() !== []) {
                    $bounds[$query->getMethod()] = (string) $query->getValues()[0];
                }
            }

            $span = strtotime($bounds['lessThan']) - strtotime($bounds['greaterThanEqual']);
            $this->assertGreaterThanOrEqual(300, $span);
        } finally {
            putenv('_APP_STATS_RESOURCES_INTERVAL');
        }
    }

    private function row(string $tenant, int $value, string $time): Metric
    {
        return new Metric([
            'tenant' => $tenant,
            'metric' => METRIC_REALTIME_CONNECTIONS,
            'value' => $value,
            'time' => $time,
        ]);
    }
}
