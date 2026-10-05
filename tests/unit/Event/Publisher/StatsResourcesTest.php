<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Publisher;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Event\Publisher\StatsResources;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\CoalescingPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class StatsResourcesTest extends TestCase
{
    private string|false $interval;

    protected function setUp(): void
    {
        $this->interval = getenv('_APP_STATS_RESOURCES_INTERVAL');
        putenv('_APP_STATS_RESOURCES_INTERVAL=60');
    }

    protected function tearDown(): void
    {
        putenv($this->interval === false ? '_APP_STATS_RESOURCES_INTERVAL' : '_APP_STATS_RESOURCES_INTERVAL=' . $this->interval);
    }

    public function testCoalesceHoldsTheKeyForTenIntervals(): void
    {
        $publisher = new CoalescingPublisher();
        $stats = new StatsResources($publisher, new Queue('v1-stats-resources', 'appwrite', 30));

        $stats->coalesce(new StatsResourcesMessage(new Document(['$id' => 'project'])));

        $this->assertCount(1, $publisher->queues);
        $queue = $publisher->queues[0];
        $this->assertSame('v1-stats-resources', $queue->name);
        $this->assertSame('appwrite', $queue->namespace);
        $this->assertSame(30, $queue->jobTtl);
        $this->assertSame(600, $queue->keyTtl);
    }
}
