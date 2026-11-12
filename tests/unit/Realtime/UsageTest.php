<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime;

use Appwrite\Realtime\Usage;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase
{
    /** @var array<int, array{string, array<string, int>}> */
    private array $published = [];

    private function usage(): Usage
    {
        return new Usage(function (array $metrics, string $projectId): void {
            $this->published[] = [$projectId, $metrics];
        });
    }

    public function testClosesArePublishedOncePerProjectOnFlush(): void
    {
        $usage = $this->usage();

        for ($i = 0; $i < 128; $i++) {
            $usage->add([METRIC_REALTIME_CONNECTIONS => -1], 'project1');
        }
        $usage->add([METRIC_REALTIME_CONNECTIONS => -1], 'project2');
        $usage->add([METRIC_REALTIME_OUTBOUND => 10, METRIC_REALTIME_CONNECTIONS_MESSAGES_SENT => 1], 'project2');
        $usage->add([METRIC_REALTIME_OUTBOUND => 5, METRIC_REALTIME_CONNECTIONS_MESSAGES_SENT => 1], 'project2');

        $this->assertSame([], $this->published, 'Usage was published before the flush.');

        $usage->flush();

        $this->assertSame([
            ['project1', [METRIC_REALTIME_CONNECTIONS => -128]],
            ['project2', [METRIC_REALTIME_CONNECTIONS => -1, METRIC_REALTIME_OUTBOUND => 15, METRIC_REALTIME_CONNECTIONS_MESSAGES_SENT => 2]],
        ], $this->published);
    }

    public function testFlushPublishesOnlyWhatWasAddedSinceTheLastFlush(): void
    {
        $usage = $this->usage();
        $usage->add([METRIC_REALTIME_INBOUND => 3], 'project1');
        $usage->flush();
        $usage->flush();

        $usage->add([METRIC_REALTIME_INBOUND => 4], 'project1');
        $usage->flush();

        $this->assertSame([
            ['project1', [METRIC_REALTIME_INBOUND => 3]],
            ['project1', [METRIC_REALTIME_INBOUND => 4]],
        ], $this->published);
    }

    public function testMetricsThatCancelOutAreNotPublished(): void
    {
        $usage = $this->usage();
        $usage->add([METRIC_REALTIME_CONNECTIONS => 1], 'project1');
        $usage->add([METRIC_REALTIME_CONNECTIONS => -1, METRIC_REALTIME_OUTBOUND => 7], 'project1');
        $usage->add([METRIC_REALTIME_CONNECTIONS => 1], 'project2');
        $usage->add([METRIC_REALTIME_CONNECTIONS => -1], 'project2');

        $usage->flush();

        $this->assertSame([
            ['project1', [METRIC_REALTIME_OUTBOUND => 7]],
        ], $this->published);
    }

    public function testStopPublishesPendingUsageAndThenEachAdditionDirectly(): void
    {
        $usage = $this->usage();
        $usage->add([METRIC_REALTIME_CONNECTIONS => -1], 'project1');
        $usage->add([METRIC_REALTIME_CONNECTIONS => -1], 'project1');

        $usage->stop();
        $usage->add([METRIC_REALTIME_CONNECTIONS => -1], 'project1');

        $this->assertSame([
            ['project1', [METRIC_REALTIME_CONNECTIONS => -2]],
            ['project1', [METRIC_REALTIME_CONNECTIONS => -1]],
        ], $this->published);
    }
}
