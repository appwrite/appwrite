<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

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
