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
            $this->assertCount(1, $read['queries'], 'A project-wide counter is filtered by time alone');
            $this->assertStringStartsWith('greaterThanEqual:time=', $read['queries'][0]);
        }
    }

    public function testBucketStorageReadsTheResourceGauge(): void
    {
        $usage = new RecordingTenant([METRIC_STORAGE => 64]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_STORAGE, 'bucket'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(64.0, $value, PHP_FLOAT_EPSILON);
        $this->assertSame([['metric' => METRIC_STORAGE, 'type' => Usage::TYPE_GAUGE, 'queries' => ['equal:resourceType=bucket', 'equal:resourceId=main']]], $usage->reads);
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
        $this->assertSame(['equal:resourceType=deployment'], $usage->reads[4]['queries']);
    }

    public function testUntaggedGaugeIsReadUnfiltered(): void
    {
        $usage = new RecordingTenant([METRIC_MAU => 12]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_MAU, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(12.0, $value, PHP_FLOAT_EPSILON);
        $this->assertSame([['metric' => METRIC_MAU, 'type' => Usage::TYPE_GAUGE, 'queries' => []]], $usage->reads);
    }

    public function testGrowthIsTheRiseSinceTheWindowOpened(): void
    {
        $usage = new RecordingTenant([
            METRIC_USERS => static fn (array $queries): int => $queries === [] ? 30 : 18,
        ]);

        $value = $this->meter($usage)->measure($this->rule(METRIC_USERS, 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(12.0, $value, PHP_FLOAT_EPSILON);
        $this->assertStringStartsWith('lessThan:time=', $usage->reads[1]['queries'][0]);
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
                $usage = new RecordingTenant();
                $value = $this->meter($usage)->measure($this->rule($metric, $resourceType), new \DateTimeImmutable());
                $this->assertEqualsWithDelta(0.0, $value, PHP_FLOAT_EPSILON, "{$metric} on {$resourceType}");
                $this->assertNotEmpty($usage->reads, "{$metric} on {$resourceType}");
            }
        }
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
