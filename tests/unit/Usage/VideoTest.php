<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Usage\Context;
use Appwrite\Usage\Video;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class VideoTest extends TestCase
{
    /**
     * Publishes one settled rendition and returns the metric key => value map
     * from the single message that reached the queue, plus the raw metrics so
     * callers can assert tags such as service.
     *
     * @param array{memory?: int, cpus?: int|float} $specification
     * @return array{values: array<string, int>, metrics: list<array<string, mixed>>}
     */
    private function publish(string $status, int $storageBytes = 0, int $computeMs = 0, array $specification = []): array
    {
        $publisher = new CapturingPublisher();

        Video::publish(
            new Context(),
            new Document(['$id' => 'video', '$sequence' => 7]),
            new Document(['$id' => 'rendition', 'status' => $status]),
            new Document(['$id' => 'project', '$sequence' => 1, 'database' => 'db']),
            new UsagePublisher($publisher, new Queue('v1-usage')),
            $storageBytes,
            $computeMs,
            $specification
        );

        $this->assertCount(1, $publisher->published);

        $values = [];
        foreach ($publisher->published[0]['metrics'] as $metric) {
            $values[$metric['key']] = ($values[$metric['key']] ?? 0) + $metric['value'];
        }

        return [
            'values' => $values,
            'metrics' => $publisher->published[0]['metrics'],
        ];
    }

    public function testReadyCountsSuccess(): void
    {
        $metrics = $this->publish('ready', 2048, 1500)['values'];

        $this->assertSame(1, $metrics[METRIC_RENDITIONS_SUCCESS] ?? null);
        $this->assertArrayNotHasKey(METRIC_RENDITIONS_FAILED, $metrics);
        $this->assertSame(1, $metrics[METRIC_RENDITIONS] ?? null);
        $this->assertSame(2048, $metrics[METRIC_VIDEOS_STORAGE] ?? null);
        $this->assertSame(1500, $metrics[METRIC_RENDITIONS_COMPUTE] ?? null);
    }

    /**
     * Every non-ready settlement counts as failed, so success + failed always
     * equals the renditions total.
     */
    #[DataProvider('nonReadyStatuses')]
    public function testNonReadyCountsFailed(string $status): void
    {
        $metrics = $this->publish($status)['values'];

        $this->assertSame(1, $metrics[METRIC_RENDITIONS_FAILED] ?? null);
        $this->assertArrayNotHasKey(METRIC_RENDITIONS_SUCCESS, $metrics);
        $this->assertSame(1, $metrics[METRIC_RENDITIONS] ?? null);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function nonReadyStatuses(): \Iterator
    {
        yield 'error' => ['error'];
        yield 'sweeper abort' => ['aborted'];
        yield 'park observed mid-run' => ['started'];
    }

    public function testVideosGaugeNotPublishedPerEncode(): void
    {
        $metrics = $this->publish('ready')['values'];

        $this->assertArrayNotHasKey(
            METRIC_VIDEOS,
            $metrics,
            'videos is a resource gauge owned by StatsResources; per-encode publishing counts rendition attempts as videos'
        );
    }

    public function testNegativeSizesClampToZero(): void
    {
        $metrics = $this->publish('ready', -5, -9)['values'];

        $this->assertSame(0, $metrics[METRIC_VIDEOS_STORAGE] ?? null);
        $this->assertSame(0, $metrics[METRIC_RENDITIONS_COMPUTE] ?? null);
        $this->assertSame(0, $metrics[METRIC_EXECUTIONS_MB_SECONDS] ?? null);
    }

    public function testMbSecondsMultipliesMemoryDurationAndCpus(): void
    {
        $published = $this->publish('ready', 0, 1500, ['memory' => 4096, 'cpus' => 4]);

        $this->assertSame(24576, $published['values'][METRIC_EXECUTIONS_MB_SECONDS] ?? null);
        $this->assertSame(1500, $published['values'][METRIC_RENDITIONS_COMPUTE] ?? null);
        $this->assertEncodeServiceIsVideos($published['metrics']);
    }

    public function testMbSecondsKeepsFractionalCpus(): void
    {
        $published = $this->publish('ready', 0, 2000, ['memory' => 512, 'cpus' => 0.5]);

        $this->assertSame(512, $published['values'][METRIC_EXECUTIONS_MB_SECONDS] ?? null);
        $this->assertEncodeServiceIsVideos($published['metrics']);
    }

    public function testFailedEncodeStillBillsMbSeconds(): void
    {
        $published = $this->publish('error', 0, 1000, ['memory' => 4096, 'cpus' => 4]);

        $this->assertSame(16384, $published['values'][METRIC_EXECUTIONS_MB_SECONDS] ?? null);
        $this->assertEncodeServiceIsVideos($published['metrics']);
    }

    public function testMbSecondsDefaultsWithoutSpecification(): void
    {
        $published = $this->publish('ready', 0, 1000);

        $this->assertSame(
            (int) (APP_COMPUTE_MEMORY_DEFAULT * APP_COMPUTE_CPUS_DEFAULT),
            $published['values'][METRIC_EXECUTIONS_MB_SECONDS] ?? null
        );
        $this->assertEncodeServiceIsVideos($published['metrics']);
    }

    /**
     * Encode compute shares executions.mbSeconds with functions and sites;
     * service=videos is what keeps the three products apart.
     *
     * @param list<array<string, mixed>> $metrics
     */
    private function assertEncodeServiceIsVideos(array $metrics): void
    {
        foreach ($metrics as $metric) {
            if (($metric['key'] ?? '') !== METRIC_EXECUTIONS_MB_SECONDS) {
                continue;
            }

            $this->assertSame('videos', $metric['service'] ?? null);

            return;
        }

        $this->fail('executions.mbSeconds was not published');
    }
}
