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
        $usage = $this->usage();

        $value = (new Meter($usage))->measure($this->rule('databases.reads', 'database'), new \DateTimeImmutable(), $type);

        $this->assertSame($expected, $value);
        $this->assertSame($metrics, \array_column($usage->reads, 'metric'));
    }

    public function testProjectRuleAddsEveryEngine(): void
    {
        $usage = $this->usage();

        $value = (new Meter($usage))->measure($this->rule('databases.reads', 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(7.0, $value, PHP_FLOAT_EPSILON);
        $this->assertSame([Usage::TYPE_EVENT, Usage::TYPE_EVENT, Usage::TYPE_EVENT], \array_column($usage->reads, 'type'));
    }

    public function testStorageIsTheLatestGauge(): void
    {
        $usage = new RecordingTenant([METRIC_FILES_STORAGE => 512, METRIC_STORAGE => 64]);
        $meter = new Meter($usage);

        $this->assertEqualsWithDelta(512.0, $meter->measure($this->rule('files.storage', 'project'), new \DateTimeImmutable()), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(64.0, $meter->measure($this->rule('files.storage', 'bucket'), new \DateTimeImmutable()), PHP_FLOAT_EPSILON);
        $this->assertSame([Usage::TYPE_GAUGE, Usage::TYPE_GAUGE], \array_column($usage->reads, 'type'));
    }

    public function testNegativeTotalsReadAsZero(): void
    {
        $value = (new Meter(new RecordingTenant([METRIC_NETWORK_OUTBOUND => -5])))->measure($this->rule('bandwidth.outbound', 'project'), new \DateTimeImmutable());

        $this->assertEqualsWithDelta(0.0, $value, PHP_FLOAT_EPSILON);
    }

    public function testEveryRegisteredMetricIsMeasured(): void
    {
        $alerts = include __DIR__ . '/../../../app/config/alerts.php';

        foreach ($alerts['metrics'] as $metric => $definition) {
            foreach ($definition['resources'] as $resourceType) {
                $value = (new Meter(new RecordingTenant()))->measure($this->rule($metric, $resourceType), new \DateTimeImmutable());
                $this->assertEqualsWithDelta(0.0, $value, PHP_FLOAT_EPSILON, "{$metric} on {$resourceType}");
            }
        }
    }

    public function testUnknownMetricIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Meter(new RecordingTenant()))->measure($this->rule('functions.executions', 'project'), new \DateTimeImmutable());
    }

    private function usage(): RecordingTenant
    {
        return new RecordingTenant([
            METRIC_DATABASES_OPERATIONS_READS => 1,
            METRIC_DATABASES_OPERATIONS_READS_DOCUMENTSDB => 2,
            METRIC_DATABASES_OPERATIONS_READS_VECTORSDB => 4,
        ]);
    }

    private function rule(string $metric, string $resourceType): Document
    {
        return new Document([
            'metric' => $metric,
            'resourceType' => $resourceType,
            'resourceId' => 'main',
            'window' => 3600,
        ]);
    }
}
