<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Distribution\Build\Status as BuildStatus;
use Appwrite\Distribution\Submission\Status as SubmissionStatus;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Usage\Context;
use Appwrite\Usage\Distribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class DistributionTest extends TestCase
{
    private CapturingPublisher $publisher;
    private UsagePublisher $publisherForUsage;
    private Context $usage;
    private Document $app;
    private Document $project;

    protected function setUp(): void
    {
        $this->publisher = new CapturingPublisher();
        $this->publisherForUsage = new UsagePublisher($this->publisher, new Queue('v1-usage'));
        $this->usage = new Context();
        $this->app = new Document(['$id' => 'app', '$sequence' => 42]);
        $this->project = new Document(['$id' => 'project', '$sequence' => 1, 'database' => 'db']);
    }

    public function testSigningPublishesOneSignOnTheApp(): void
    {
        Distribution::publishSigning($this->usage, $this->app, $this->project, $this->publisherForUsage);

        $metrics = $this->published();
        $this->assertSame([METRIC_DISTRIBUTION_SIGNING => 1], $this->values($metrics));
        $this->assertScopedToApp($metrics);
        $this->assertTrue($this->usage->isEmpty(), 'the context is reset after publishing');
    }

    public function testReadyBuildCountsSuccessAndCompute(): void
    {
        Distribution::publishBuild($this->usage, $this->app, $this->build(BuildStatus::Ready), $this->project, $this->publisherForUsage);

        $metrics = $this->published();
        $this->assertSame([
            METRIC_DISTRIBUTION_BUILDS => 1,
            METRIC_DISTRIBUTION_BUILDS_SUCCESS => 1,
            'distribution.builds.android.compute' => 120_000,
            'distribution.builds.android.mbSeconds' => 8192 * 120 * 4,
        ], $this->values($metrics));
        $this->assertScopedToApp($metrics);
        $this->assertTrue($this->usage->isEmpty(), 'the context is reset after publishing');
    }

    public function testFailedBuildCountsFailure(): void
    {
        Distribution::publishBuild($this->usage, $this->app, $this->build(BuildStatus::Failed), $this->project, $this->publisherForUsage);

        $values = $this->values($this->published());
        $this->assertSame(1, $values[METRIC_DISTRIBUTION_BUILDS_FAILED] ?? null);
        $this->assertArrayNotHasKey(METRIC_DISTRIBUTION_BUILDS_SUCCESS, $values);
        $this->assertSame(1, $values[METRIC_DISTRIBUTION_BUILDS]);
    }

    public function testBuildThatNeverStartedMetersNoCompute(): void
    {
        $build = $this->build(BuildStatus::Failed)->setAttribute('startedAt', null)->setAttribute('duration', 0);

        Distribution::publishBuild($this->usage, $this->app, $build, $this->project, $this->publisherForUsage);

        $this->assertSame([
            METRIC_DISTRIBUTION_BUILDS => 1,
            METRIC_DISTRIBUTION_BUILDS_FAILED => 1,
        ], $this->values($this->published()));
    }

    public function testCacheHitIsCounted(): void
    {
        $build = $this->build(BuildStatus::Ready)->setAttribute('toolchain', ['image' => 'android:1', 'cache' => true]);

        Distribution::publishBuild($this->usage, $this->app, $build, $this->project, $this->publisherForUsage);

        $this->assertSame(1, $this->values($this->published())[METRIC_DISTRIBUTION_BUILDS_CACHE_HITS] ?? null);
    }

    public function testAttemptMetersComputeOnly(): void
    {
        Distribution::publishAttempt($this->usage, $this->app, $this->build(BuildStatus::Building), $this->project, $this->publisherForUsage);

        $metrics = $this->published();
        $this->assertSame([
            'distribution.builds.android.compute' => 120_000,
            'distribution.builds.android.mbSeconds' => 8192 * 120 * 4,
        ], $this->values($metrics));
        $this->assertScopedToApp($metrics);
    }

    public function testAttemptThatNeverStartedPublishesNothing(): void
    {
        $build = $this->build(BuildStatus::Waiting)->setAttribute('startedAt', null);

        Distribution::publishAttempt($this->usage, $this->app, $build, $this->project, $this->publisherForUsage);

        $this->assertSame([], $this->publisher->published);
    }

    public function testListingSyncPublishesOneSyncOnTheApp(): void
    {
        Distribution::publishListingSync($this->usage, $this->app, $this->project, $this->publisherForUsage);

        $metrics = $this->published();
        $this->assertSame([METRIC_DISTRIBUTION_LISTINGS_SYNCS => 1], $this->values($metrics));
        $this->assertSame('distribution.listings.syncs', $metrics[0]['key']);
        $this->assertScopedToApp($metrics);
        $this->assertTrue($this->usage->isEmpty(), 'the context is reset after publishing');
    }

    public function testPublishedSubmissionCountsSuccess(): void
    {
        $this->publishSubmission(SubmissionStatus::Published);

        $metrics = $this->published();
        $this->assertSame([
            METRIC_DISTRIBUTION_SUBMISSIONS => 1,
            METRIC_DISTRIBUTION_SUBMISSIONS_SUCCESS => 1,
        ], $this->values($metrics));
        $this->assertScopedToApp($metrics);
    }

    /**
     * Every other terminal status counts as failed, so success + failed always
     * equals the submissions total.
     */
    #[DataProvider('unpublishedStatuses')]
    public function testUnpublishedSubmissionCountsFailed(SubmissionStatus $status): void
    {
        $this->publishSubmission($status);

        $this->assertSame([
            METRIC_DISTRIBUTION_SUBMISSIONS => 1,
            METRIC_DISTRIBUTION_SUBMISSIONS_FAILED => 1,
        ], $this->values($this->published()));
    }

    /**
     * @return \Iterator<string, array{SubmissionStatus}>
     */
    public static function unpublishedStatuses(): \Iterator
    {
        yield 'failed' => [SubmissionStatus::Failed];
        yield 'rejected' => [SubmissionStatus::Rejected];
        yield 'canceled' => [SubmissionStatus::Canceled];
    }

    #[DataProvider('transferMetrics')]
    public function testTransferStaysOnTheRequestContext(string $metric): void
    {
        $this->usage->setPath('/v1/distribution/apps/app/artifacts')->setService('distribution');

        Distribution::addTransfer($this->usage, $this->app, $metric, 4096);

        $this->assertSame([], $this->publisher->published, 'the api shutdown hook publishes request metrics');
        $metrics = $this->usage->getMetrics();
        $this->assertSame([$metric => 4096], $this->values($metrics));
        $this->assertScopedToApp($metrics);
        $this->assertSame('/v1/distribution/apps/app/artifacts', $metrics[0]['path']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function transferMetrics(): \Iterator
    {
        yield 'inbound' => [METRIC_DISTRIBUTION_ARTIFACTS_INBOUND];
        yield 'outbound' => [METRIC_DISTRIBUTION_ARTIFACTS_OUTBOUND];
    }

    #[DataProvider('emptyTransfers')]
    public function testEmptyTransferAddsNothing(int $bytes): void
    {
        Distribution::addTransfer($this->usage, $this->app, METRIC_DISTRIBUTION_ARTIFACTS_OUTBOUND, $bytes);

        $this->assertTrue($this->usage->isEmpty());
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function emptyTransfers(): \Iterator
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    private function build(BuildStatus $status): Document
    {
        return new Document([
            '$id' => 'build',
            'status' => $status->value,
            'platform' => 'android',
            'startedAt' => '2026-11-28T10:00:00.000+00:00',
            'duration' => 120,
            'cpus' => 4,
            'memory' => 8192,
            'toolchain' => ['image' => 'android:1', 'cache' => false],
        ]);
    }

    private function publishSubmission(SubmissionStatus $status): void
    {
        Distribution::publishSubmission(
            $this->usage,
            $this->app,
            new Document(['$id' => 'submission', 'status' => $status->value]),
            $this->project,
            $this->publisherForUsage,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function published(): array
    {
        $this->assertCount(1, $this->publisher->published);
        $this->assertSame('project', $this->publisher->published[0]['project']['$id'] ?? null);

        return $this->publisher->published[0]['metrics'];
    }

    /**
     * @param list<array<string, mixed>> $metrics
     * @return array<string, int>
     */
    private function values(array $metrics): array
    {
        $values = [];
        foreach ($metrics as $metric) {
            $values[$metric['key']] = ($values[$metric['key']] ?? 0) + $metric['value'];
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $metrics
     */
    private function assertScopedToApp(array $metrics): void
    {
        foreach ($metrics as $metric) {
            $this->assertSame('distribution', $metric['service'], $metric['key']);
            $this->assertSame('distributionApp', $metric['resourceType'], $metric['key']);
            $this->assertSame('app', $metric['resourceId'], $metric['key']);
            $this->assertSame('42', $metric['resourceInternalId'], $metric['key']);
        }
    }
}
