<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Publisher;

use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

final class UsageTest extends TestCase
{
    private ?string $previousMax = null;

    private ?string $previousStats = null;

    protected function setUp(): void
    {
        $max = getenv('_APP_STATS_USAGE_QUEUE_MAX');
        $stats = getenv('_APP_USAGE_STATS');
        $this->previousMax = $max === false ? null : $max;
        $this->previousStats = $stats === false ? null : $stats;
        putenv('_APP_STATS_USAGE_QUEUE_MAX');
        putenv('_APP_USAGE_STATS');
    }

    protected function tearDown(): void
    {
        $this->restore('_APP_STATS_USAGE_QUEUE_MAX', $this->previousMax);
        $this->restore('_APP_USAGE_STATS', $this->previousStats);
    }

    public function testEnqueueStopsAtPendingCap(): void
    {
        putenv('_APP_STATS_USAGE_QUEUE_MAX=2');
        $publisher = new MockPublisher();
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertNotFalse($usage->enqueue($this->message()));
        $this->assertNotFalse($usage->enqueue($this->message()));
        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertCount(2, $publisher->getEvents('v1-stats-usage'));
    }

    public function testDefaultCapDropsWhenTheQueueIsAlreadyFull(): void
    {
        $publisher = new SizedPublisher();
        $publisher->pending = Usage::DEFAULT_MAX_PENDING;
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertNull($publisher->getEvents('v1-stats-usage'));
    }

    public function testDefaultCapAllowsOneBelowTheLimit(): void
    {
        $publisher = new SizedPublisher();
        $publisher->pending = Usage::DEFAULT_MAX_PENDING - 1;
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertNotFalse($usage->enqueue($this->message()));
        $this->assertCount(1, $publisher->getEvents('v1-stats-usage'));
    }

    public function testNegativeCapDisablesTheLimit(): void
    {
        putenv('_APP_STATS_USAGE_QUEUE_MAX=-1');
        $publisher = new SizedPublisher();
        $publisher->pending = Usage::DEFAULT_MAX_PENDING;
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertNotFalse($usage->enqueue($this->message()));
        $this->assertCount(1, $publisher->getEvents('v1-stats-usage'));
    }

    public function testNonNumericCapKeepsTheDefault(): void
    {
        putenv('_APP_STATS_USAGE_QUEUE_MAX=nope');
        $publisher = new SizedPublisher();
        $publisher->pending = Usage::DEFAULT_MAX_PENDING;
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertNull($publisher->getEvents('v1-stats-usage'));
    }

    public function testDisabledStatsSkipsTheQueue(): void
    {
        putenv('_APP_USAGE_STATS=disabled');
        putenv('_APP_STATS_USAGE_QUEUE_MAX=1');
        $publisher = new MockPublisher();
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertNull($publisher->getEvents('v1-stats-usage'));
    }

    private function message(): UsageMessage
    {
        return new UsageMessage(
            project: new Document([
                '$id' => 'project',
                '$sequence' => '1',
            ]),
            metrics: [[
                'key' => 'network.requests',
                'value' => 1,
            ]],
        );
    }

    private function restore(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }
}

/**
 * Reports a fixed pending depth so the cap can be tested without thousands of jobs.
 */
final class SizedPublisher extends MockPublisher
{
    public int $pending = 0;

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->pending;
    }
}
