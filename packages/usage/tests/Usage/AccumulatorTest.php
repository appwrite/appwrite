<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Usage\Accumulator;
use Utopia\Usage\Adapter;
use Utopia\Usage\Usage;

/**
 * Records addBatch() calls so the Accumulator can be tested without a backend.
 * addBatch() returns whatever $succeed is set to, letting tests drive the
 * partial-failure path.
 */
class RecordingAdapter extends Adapter
{
    /** @var array<array{metrics: array<int, array<string, mixed>>, type: string}> */
    public array $batches = [];

    public bool $succeed = true;

    public function getName(): string
    {
        return 'recording';
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return ['healthy' => true];
    }

    public function setup(): void
    {
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        if ($this->succeed) {
            $this->batches[] = ['metrics' => $metrics, 'type' => $type];
        }
        return $this->succeed;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        return [];
    }

    public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int
    {
        return 0;
    }

    /**
     * @return array<string, int>
     */
    public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array
    {
        return [];
    }

    public function purge(string $tenant, array $queries = [], ?string $type = null): bool
    {
        return true;
    }

    /**
     * @return array<mixed>
     */
    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        return [];
    }

    public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int
    {
        return 0;
    }

    public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        return 0;
    }

    /**
     * @return array<mixed>
     */
    public function findDaily(string $tenant, array $queries = []): array
    {
        return [];
    }

    public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int
    {
        return 0;
    }

    /**
     * @return array<string, int>
     */
    public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array
    {
        return [];
    }
}

class AccumulatorTest extends TestCase
{
    private RecordingAdapter $adapter;

    private Accumulator $accumulator;

    protected function setUp(): void
    {
        $this->adapter = new RecordingAdapter();
        $this->accumulator = new Accumulator(new Usage($this->adapter));
    }

    public function testEventsSumByKey(): void
    {
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT);
        $this->accumulator->collect('t1', 'requests', 20, Usage::TYPE_EVENT);
        $this->accumulator->collect('t1', 'requests', 30, Usage::TYPE_EVENT);

        // Same metric + tags = 1 entry, values summed
        $this->assertEquals(1, $this->accumulator->count());

        $this->assertTrue($this->accumulator->flush());

        $this->assertCount(1, $this->adapter->batches);
        $this->assertEquals(Usage::TYPE_EVENT, $this->adapter->batches[0]['type']);
        $this->assertEquals(60, $this->adapter->batches[0]['metrics'][0]['value']);
    }

    public function testTagsPartitionEntries(): void
    {
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT, ['region' => 'us']);
        $this->accumulator->collect('t1', 'requests', 20, Usage::TYPE_EVENT, ['region' => 'eu']);

        // Distinct tags = distinct entries
        $this->assertEquals(2, $this->accumulator->count());
    }

    public function testTenantPartitionsEntries(): void
    {
        // Same metric + tags but different tenants must not collapse
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT);
        $this->accumulator->collect('t2', 'requests', 20, Usage::TYPE_EVENT);

        $this->assertEquals(2, $this->accumulator->count());

        $this->assertTrue($this->accumulator->flush());

        $tenants = array_column($this->adapter->batches[0]['metrics'], 'tenant');
        sort($tenants);
        $this->assertEquals(['t1', 't2'], $tenants);
    }

    public function testGaugesUseLastWriteWins(): void
    {
        $this->accumulator->collect('t1', 'storage', 100, Usage::TYPE_GAUGE);
        $this->accumulator->collect('t1', 'storage', 200, Usage::TYPE_GAUGE);
        $this->accumulator->collect('t1', 'storage', 300, Usage::TYPE_GAUGE);

        $this->assertEquals(1, $this->accumulator->count());

        $this->assertTrue($this->accumulator->flush());

        $this->assertEquals(Usage::TYPE_GAUGE, $this->adapter->batches[0]['type']);
        $this->assertEquals(300, $this->adapter->batches[0]['metrics'][0]['value']);
    }

    public function testFlushSeparatesEventsAndGauges(): void
    {
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT);
        $this->accumulator->collect('t1', 'storage', 100, Usage::TYPE_GAUGE);

        $this->assertTrue($this->accumulator->flush());

        // One batch per type
        $this->assertCount(2, $this->adapter->batches);
        $types = [$this->adapter->batches[0]['type'], $this->adapter->batches[1]['type']];
        $this->assertContains(Usage::TYPE_EVENT, $types);
        $this->assertContains(Usage::TYPE_GAUGE, $types);

        // Buffer cleared on success
        $this->assertEquals(0, $this->accumulator->count());
    }

    public function testFailedFlushRetainsBuffer(): void
    {
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT);

        $this->adapter->succeed = false;
        $this->assertFalse($this->accumulator->flush());

        // Nothing written, entry preserved for retry
        $this->assertCount(0, $this->adapter->batches);
        $this->assertEquals(1, $this->accumulator->count());

        // A later successful flush drains the buffer
        $this->adapter->succeed = true;
        $this->assertTrue($this->accumulator->flush());
        $this->assertEquals(0, $this->accumulator->count());
    }

    public function testFlushEmptyBuffer(): void
    {
        $this->assertTrue($this->accumulator->flush());
        $this->assertCount(0, $this->adapter->batches);
        $this->assertEquals(0, $this->accumulator->count());
    }

    public function testElapsedSignal(): void
    {
        $this->assertLessThan(1.0, $this->accumulator->elapsedSeconds());

        sleep(1);

        $this->assertGreaterThanOrEqual(1.0, $this->accumulator->elapsedSeconds());
    }

    public function testEmptyTenantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tenant cannot be empty');
        $this->accumulator->collect('', 'requests', 10, Usage::TYPE_EVENT);
    }

    public function testTenantZeroIsAccepted(): void
    {
        // "0" is a valid tenant id even though empty("0") is true in PHP
        $this->accumulator->collect('0', 'requests', 10, Usage::TYPE_EVENT);

        $this->assertEquals(1, $this->accumulator->count());

        $this->assertTrue($this->accumulator->flush());
        $this->assertEquals('0', $this->adapter->batches[0]['metrics'][0]['tenant']);
    }

    public function testEmptyMetricNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metric name cannot be empty');
        $this->accumulator->collect('t1', '', 10, Usage::TYPE_EVENT);
    }

    public function testKeyDistinguishesAmbiguousTenantMetricSplits(): void
    {
        // tenant "a" + metric "b:c" must not collide with tenant "a:b" + metric "c"
        $this->accumulator->collect('a', 'b:c', 10, Usage::TYPE_EVENT);
        $this->accumulator->collect('a:b', 'c', 20, Usage::TYPE_EVENT);

        $this->assertEquals(2, $this->accumulator->count());
    }

    public function testTagOrderDoesNotSplitEntries(): void
    {
        // Same logical tags in different insertion order must sum into one entry
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT, ['teamId' => 't', 'resourceId' => 'r']);
        $this->accumulator->collect('t1', 'requests', 20, Usage::TYPE_EVENT, ['resourceId' => 'r', 'teamId' => 't']);

        $this->assertEquals(1, $this->accumulator->count());

        $this->assertTrue($this->accumulator->flush());
        $this->assertEquals(30, $this->adapter->batches[0]['metrics'][0]['value']);
    }

    public function testGaugeNegativeValueThrows(): void
    {
        // Gauges are snapshots (e.g. storage); callers never opt in, so
        // negatives still throw by default.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value cannot be negative');
        $this->accumulator->collect('t1', 'storage', -1, Usage::TYPE_GAUGE);
    }

    public function testNegativeValueRejectedByDefaultForEvents(): void
    {
        // Default is strict for every metric, events included, so a buggy
        // negative count is caught unless the caller explicitly opts in.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value cannot be negative');
        $this->accumulator->collect('t1', 'requests', -1, Usage::TYPE_EVENT);
    }

    public function testEventNegativeValueAllowedWhenOptedIn(): void
    {
        // Genuine signed deltas (realtime connections emit +1/-1) opt in per
        // call with allowNegative, so a lone -1 persists rather than throws.
        $this->accumulator->collect('t1', 'realtime.connections', -1, Usage::TYPE_EVENT, allowNegative: true);

        $this->assertEquals(1, $this->accumulator->count());
        $this->assertTrue($this->accumulator->flush());
        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertEquals(-1, $entry['value']);
        // The opt-in flag rides along on the row so addBatch can honour it.
        $this->assertTrue($entry['allowNegative']);
    }

    public function testEventMixedSignsNetToDelta(): void
    {
        // +1,+1,-1 folds to a net delta of +1 for the metric.
        $this->accumulator->collect('t1', 'realtime.connections', 1, Usage::TYPE_EVENT, allowNegative: true);
        $this->accumulator->collect('t1', 'realtime.connections', 1, Usage::TYPE_EVENT, allowNegative: true);
        $this->accumulator->collect('t1', 'realtime.connections', -1, Usage::TYPE_EVENT, allowNegative: true);

        $this->assertEquals(1, $this->accumulator->count());
        $this->assertTrue($this->accumulator->flush());
        $this->assertEquals(1, $this->adapter->batches[0]['metrics'][0]['value']);
    }

    public function testFoldedEntryKeepsTheNegativeOptIn(): void
    {
        // The opt-in belongs to the folded row, not to whichever call opened
        // it. Here the entry is created by a plain positive with the flag off,
        // then a signed delta folds in and takes the net negative.
        $this->accumulator->collect('t1', 'realtime.connections', 1, Usage::TYPE_EVENT);
        $this->accumulator->collect('t1', 'realtime.connections', -3, Usage::TYPE_EVENT, allowNegative: true);

        $this->assertEquals(1, $this->accumulator->count());
        $this->assertTrue($this->accumulator->flush());

        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertEquals(-2, $entry['value']);

        // Without this the net row is written unauthorised and rejected at
        // validation. A failed batch keeps its entries buffered, so every
        // later flush would retry the same rejection.
        $this->assertTrue($entry['allowNegative']);
    }

    public function testFoldRetainsOptInRegardlessOfCallOrder(): void
    {
        // Same fold, opposite order: the entry is opened by the opted-in
        // delta and a plain positive folds in afterwards.
        $this->accumulator->collect('t1', 'realtime.connections', -3, Usage::TYPE_EVENT, allowNegative: true);
        $this->accumulator->collect('t1', 'realtime.connections', 1, Usage::TYPE_EVENT);

        $this->assertTrue($this->accumulator->flush());

        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertEquals(-2, $entry['value']);
        $this->assertTrue($entry['allowNegative']);
    }

    public function testInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->accumulator->collect('t1', 'requests', 10, 'invalid');
    }

    public function testCollectWithoutTimeOmitsField(): void
    {
        // Callers that don't set $time must not surface a null/empty time
        // in the buffered entry — the adapter interprets "no time" as
        // "use now() at write".
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT);

        $this->assertTrue($this->accumulator->flush());
        $this->assertArrayNotHasKey('time', $this->adapter->batches[0]['metrics'][0]);
    }

    public function testCollectThreadsQueuedTimeToBatch(): void
    {
        $emittedAt = new \DateTime('2026-04-15 12:34:56');
        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT, [], $emittedAt);

        $this->assertTrue($this->accumulator->flush());

        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertArrayHasKey('time', $entry);
        $this->assertSame($emittedAt, $entry['time']);
    }

    public function testEventCollectPreservesEarliestQueuedTime(): void
    {
        // Two collect() calls fold into one entry; the earliest queued
        // time survives so buckets don't slide forward on late arrivals.
        $earlier = new \DateTime('2026-04-15 12:00:00');
        $later = new \DateTime('2026-04-15 12:05:00');

        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT, [], $earlier);
        $this->accumulator->collect('t1', 'requests', 5, Usage::TYPE_EVENT, [], $later);

        $this->assertEquals(1, $this->accumulator->count());
        $this->assertTrue($this->accumulator->flush());

        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertEquals(15, $entry['value']);
        $this->assertSame($earlier, $entry['time']);
    }

    public function testEventCollectKeepsEarliestTimeOnOutOfOrderMerge(): void
    {
        // Out-of-order redelivery: the later time arrives FIRST, the earlier
        // time second. The merged bucket must carry the earlier time so
        // buckets never slide forward when late-arriving events fold in.
        $later = new \DateTime('2026-04-15 12:05:00');
        $earlier = new \DateTime('2026-04-15 12:00:00');

        $this->accumulator->collect('t1', 'requests', 10, Usage::TYPE_EVENT, [], $later);
        $this->accumulator->collect('t1', 'requests', 5, Usage::TYPE_EVENT, [], $earlier);

        $this->assertEquals(1, $this->accumulator->count());
        $this->assertTrue($this->accumulator->flush());

        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertEquals(15, $entry['value']);
        $this->assertSame($earlier, $entry['time']);
    }

    public function testGaugeCollectUsesLastWriteWinsForTime(): void
    {
        // Gauge collect() is last-write-wins on value; the queued time
        // supplied on the last call wins alongside it.
        $t1 = new \DateTime('2026-04-15 12:00:00');
        $t2 = new \DateTime('2026-04-15 12:05:00');

        $this->accumulator->collect('t1', 'storage', 100, Usage::TYPE_GAUGE, [], $t1);
        $this->accumulator->collect('t1', 'storage', 200, Usage::TYPE_GAUGE, [], $t2);

        $this->assertTrue($this->accumulator->flush());
        $entry = $this->adapter->batches[0]['metrics'][0];
        $this->assertEquals(200, $entry['value']);
        $this->assertSame($t2, $entry['time']);
    }
}
