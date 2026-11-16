<?php

declare(strict_types=1);

namespace Tests\Unit\Alert;

use Appwrite\Alert\Meter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Usage\Usage;

final class MeterTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, list<string>, float}>
     */
    public static function databaseTypes(): \Iterator
    {
        yield 'tablesdb' => [DATABASE_TYPE_TABLESDB, [METRIC_DATABASES_OPERATIONS_READS], 1];
        yield 'legacy' => [DATABASE_TYPE_LEGACY, [METRIC_DATABASES_OPERATIONS_READS], 1];
        yield 'documentsdb' => [DATABASE_TYPE_DOCUMENTSDB, [METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB], 2];
        yield 'vectorsdb' => [DATABASE_TYPE_VECTORSDB, [METRIC_DATABASES_OPERATIONS_READS_VECTORSDB], 4];
    }

    /**
     * @param list<string> $metrics
     */
    #[DataProvider('databaseTypes')]
    public function testDatabaseRuleReadsItsEngine(string $type, array $metrics, float $expected): void
    {
        $usage = $this->operations();

        $value = $this->meter($usage)->measure($this->rule(METRIC_DATABASES_OPERATIONS_READS, 'database'), new \DateTimeImmutable(), $type);

        $this->assertSame($expected, $value);
        $this->assertSame($metrics, \array_column($usage->reads, 'metric'));
        $this->assertContains('equal:resourceId=main', $usage->reads[0]['queries']);
    }

    public function testProjectRuleAddsEveryEngine(): void
    {
        $usage = $this->operations();

        $value = $this->meter($usage)->measure($this->rule(METRIC_DATABASES_OPERATIONS_READS, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(7.0, $value, PHP_FLOAT_EPSILON);
        $this->assertSame([Usage::TYPE_EVENT, Usage::TYPE_EVENT, Usage::TYPE_EVENT], \array_column($usage->reads, 'type'));
        foreach ($usage->reads as $read) {
            $this->assertCount(2, $read['queries'], 'A project-wide counter is filtered by time alone');
            $this->assertStringStartsWith('greaterThanEqual:time=', $read['queries'][0]);
            $this->assertStringStartsWith('lessThan:time=', $read['queries'][1]);
        }
    }

    public function testBucketStorageReadsTheResourceGauge(): void
    {
        $usage = new RecordingTenant([METRIC_STORAGE => 64]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_STORAGE, 'bucket'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(64.0, $value, PHP_FLOAT_EPSILON);
        $this->assertSame(['equal:resourceType=bucket', 'equal:resourceId=main'], \array_slice($usage->reads[0]['queries'], 2));
        $this->assertSame([METRIC_STORAGE], \array_column($usage->reads, 'metric'));
    }

    public function testProjectStorageAddsEachFamilysTotal(): void
    {
        $usage = new RecordingTenant([
            METRIC_FILES_STORAGE => 1,
            METRIC_DATABASES_STORAGE => 2,
            METRIC_DATABASES_STORAGE_DOCUMENTSDB => 4,
            METRIC_DATABASES_STORAGE_VECTORSDB => 8,
            METRIC_DEPLOYMENTS_STORAGE => 16,
            METRIC_BUILDS_STORAGE => 32,
            METRIC_STORAGE => 1000,
        ]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_STORAGE, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(63.0, $value, PHP_FLOAT_EPSILON);
        $this->assertNotContains(METRIC_STORAGE, \array_column($usage->reads, 'metric'));
        $this->assertSame(['equal:resourceType=deployment'], \array_slice($usage->reads[4]['queries'], 2));
    }

    public function testUntaggedGaugeIsReadUnfiltered(): void
    {
        $usage = new RecordingTenant([METRIC_MAU => 12]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_MAU, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(12.0, $value, PHP_FLOAT_EPSILON);
        $this->assertSame([METRIC_MAU], \array_column($usage->reads, 'metric'));
        $this->assertCount(2, $usage->reads[0]['queries'], 'An untagged gauge is filtered by time alone');
    }

    public function testGrowthIsTheRiseSinceTheWindowOpened(): void
    {
        $usage = new RecordingTenant([
            METRIC_USERS => static fn (array $queries): int => $queries[0]->getMethod()->value === 'lessThan' ? 18 : 30,
        ]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_USERS, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(12.0, $value, PHP_FLOAT_EPSILON);
        $this->assertStringStartsWith('lessThan:time=', $usage->reads[1]['queries'][0]);
        $this->assertCount(1, $usage->reads[1]['queries'], 'The baseline is any reading before the window');
    }

    public function testNegativeTotalsReadAsZero(): void
    {
        $value = $this->meter(new RecordingTenant([METRIC_NETWORK_OUTBOUND => -5]))->measure($this->rule(METRIC_NETWORK_OUTBOUND, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(0.0, $value, PHP_FLOAT_EPSILON);
    }

    public function testEveryRegisteredMetricIsMeasured(): void
    {
        foreach ($this->registry()['metrics'] as $metric => $definition) {
            foreach (\array_keys($definition['resources']) as $resourceType) {
                $value = $this->meter(new RecordingTenant())->measure($this->rule($metric, $resourceType), new \DateTimeImmutable());
                $this->assertNull($value, "{$metric} on {$resourceType} without rows has no value");
            }
        }
    }

    public function testNoRowsIsNoValueAndAZeroRowIsZero(): void
    {
        $this->assertNull($this->meter(new RecordingTenant())->measure($this->rule(METRIC_NETWORK_REQUESTS, 'project'), new \DateTimeImmutable()));
        $zero = $this->meter(new RecordingTenant([METRIC_NETWORK_REQUESTS => 0]))->measure($this->rule(METRIC_NETWORK_REQUESTS, 'project'), new \DateTimeImmutable());
        $this->assertNotNull($zero, 'A row totalling 0 is a reading of 0');
        $this->assertEqualsWithDelta(0.0, $zero, PHP_FLOAT_EPSILON);
        // One engine with rows is a reading, though the others have none.
        $this->assertEqualsWithDelta(2.0, $this->meter(new RecordingTenant([METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB => 2]))->measure($this->rule(METRIC_DATABASES_OPERATIONS_READS, 'project'), new \DateTimeImmutable()), PHP_FLOAT_EPSILON);
    }

    public function testUsageIsReadBehindTheLag(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 12:00:00');
        $usage = new RecordingTenant([METRIC_NETWORK_REQUESTS => 1, METRIC_MAU => 1]);
        $meter = new Meter($usage, $this->registry(), lag: 120);

        $meter->measure($this->rule(METRIC_NETWORK_REQUESTS, 'project'), $now);
        $meter->measure($this->rule(METRIC_MAU, 'project'), $now);

        // The counter's window is [now - lag - window, now - lag).
        $this->assertSame(['greaterThanEqual:time=2026-10-30 10:58:00.000', 'lessThan:time=2026-10-30 11:58:00.000'], $usage->reads[0]['queries']);
        // The gauge is the latest sample at or before now - lag, no older than the gauge age limit.
        $this->assertSame(['lessThanEqual:time=2026-10-30 11:58:00.000', 'greaterThanEqual:time=2026-10-29 11:58:00.000'], $usage->reads[1]['queries']);
    }

    public function testUnknownMetricIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->meter(new RecordingTenant())->measure($this->rule('bandwidth.outbound', 'project'), new \DateTimeImmutable());
    }

    public function testUnrecordedScopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->meter(new RecordingTenant())->measure($this->rule(METRIC_NETWORK_OUTBOUND, 'bucket'), new \DateTimeImmutable());
    }

    private function operations(): RecordingTenant
    {
        return new RecordingTenant([
            METRIC_DATABASES_OPERATIONS_READS => 1,
            METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB => 2,
            METRIC_DATABASES_OPERATIONS_READS_VECTORSDB => 4,
        ]);
    }

    private function meter(RecordingTenant $usage): Meter
    {
        return new Meter($usage, $this->registry());
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(): array
    {
        return include __DIR__ . '/../../../app/config/alerts.php';
    }

    private function rule(string $metric, string $resourceType): Document
    {
        return new Document([
            'metric' => $metric,
            'resourceType' => $resourceType,
            'resourceId' => 'main',
            'resourceInternalId' => '7',
            'window' => 3600,
        ]);
    }
}
