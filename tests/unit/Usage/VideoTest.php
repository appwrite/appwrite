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
     * from the single message that reached the queue.
     *
     * @return array<string, int>
     */
    private function publish(string $status, int $storageBytes = 0, int $computeMs = 0): array
    {
        $publisher = new CapturingPublisher();

        Video::publish(
            new Context(),
            new Document(['$id' => 'video', '$sequence' => 7]),
            new Document(['$id' => 'rendition', 'status' => $status]),
            new Document(['$id' => 'project', '$sequence' => 1, 'database' => 'db']),
            new UsagePublisher($publisher, new Queue('v1-usage')),
            $storageBytes,
            $computeMs
        );

        $this->assertCount(1, $publisher->published);

        $metrics = [];
        foreach ($publisher->published[0]['metrics'] as $metric) {
            $metrics[$metric['key']] = ($metrics[$metric['key']] ?? 0) + $metric['value'];
        }

        return $metrics;
    }

    public function testReadyCountsSuccess(): void
    {
        $metrics = $this->publish('ready', 2048, 1500);

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
        $metrics = $this->publish($status);

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
        $metrics = $this->publish('ready');

        $this->assertArrayNotHasKey(
            METRIC_VIDEOS,
            $metrics,
            'videos is a resource gauge owned by StatsResources; per-encode publishing counts rendition attempts as videos'
        );
    }

    public function testNegativeSizesClampToZero(): void
    {
        $metrics = $this->publish('ready', -5, -9);

        $this->assertSame(0, $metrics[METRIC_VIDEOS_STORAGE] ?? null);
        $this->assertSame(0, $metrics[METRIC_RENDITIONS_COMPUTE] ?? null);
    }
}
