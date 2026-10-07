<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Publisher;

use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    #[DataProvider('limits')]
    public function testEnqueueRespectsPendingLimit(?string $max, int $pending, bool $accepted): void
    {
        if ($max !== null) {
            putenv('_APP_STATS_USAGE_QUEUE_MAX=' . $max);
        }

        $publisher = new UsageQueuePublisher();
        $publisher->pending = $pending;
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertSame($accepted, $usage->enqueue($this->message()) !== false);
        $this->assertCount($accepted ? 1 : 0, $publisher->getEvents('v1-stats-usage') ?? []);
        $this->assertSame($pending + ($accepted ? 1 : 0), $publisher->pending);
    }

    /**
     * @return \Iterator<string, array{0: ?string, 1: int, 2: bool}>
     */
    public static function limits(): \Iterator
    {
        $cap = Usage::DEFAULT_MAX_PENDING;

        yield 'under an explicit cap' => ['2', 1, true];
        yield 'at an explicit cap' => ['2', 2, false];
        yield 'under the default cap' => [null, $cap - 1, true];
        yield 'at the default cap' => [null, $cap, false];
        yield 'negative one removes the cap' => ['-1', $cap, true];
        yield 'other negatives keep the default' => ['-2', $cap, false];
        yield 'zero keeps the default' => ['0', 0, true];
        yield 'zero does not uncap a full queue' => ['0', $cap, false];
        yield 'non numeric keeps the default' => ['nope', $cap, false];
        yield 'trailing junk keeps the default' => ['12abc', $cap, false];
    }

    public function testEnqueueResumesAfterTheQueueDrains(): void
    {
        putenv('_APP_STATS_USAGE_QUEUE_MAX=2');
        $publisher = new UsageQueuePublisher();
        $publisher->pending = 2;
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));

        $publisher->pending = 1;

        $this->assertNotFalse($usage->enqueue($this->message()));
        $this->assertSame(2, $publisher->pending);
    }

    public function testDepthReadFailureDoesNotEscape(): void
    {
        $publisher = new UsageQueuePublisher();
        $publisher->failSize = new RuntimeException('redis down');
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertSame([], $publisher->getEvents('v1-stats-usage') ?? []);
    }

    public function testPublishFailureDoesNotEscape(): void
    {
        $publisher = new UsageQueuePublisher();
        $publisher->failPublish = new RuntimeException('redis down');
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertSame(0, $publisher->pending);
    }

    public function testDisabledStatsSkipsTheQueue(): void
    {
        putenv('_APP_USAGE_STATS=disabled');
        putenv('_APP_STATS_USAGE_QUEUE_MAX=1');
        $publisher = new UsageQueuePublisher();
        $publisher->failSize = new RuntimeException('should not read depth');
        $usage = new Usage($publisher, new Queue('v1-stats-usage'));

        $this->assertFalse($usage->enqueue($this->message()));
        $this->assertSame(0, $publisher->sizeReads);
        $this->assertSame(0, $publisher->pending);
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
 * Pending depth is independent of recorded events so a full queue can be
 * simulated, then drained, without publishing thousands of jobs.
 */
final class UsageQueuePublisher extends MockPublisher
{
    public int $pending = 0;

    public int $sizeReads = 0;

    public ?\Throwable $failSize = null;

    public ?\Throwable $failPublish = null;

    public function publish(Queue $queue, array $payload): bool
    {
        if ($this->failPublish !== null) {
            throw $this->failPublish;
        }

        $published = parent::publish($queue, $payload);
        if ($published) {
            $this->pending++;
        }

        return $published;
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $this->sizeReads++;
        if ($this->failSize !== null) {
            throw $this->failSize;
        }

        return $this->pending;
    }
}
