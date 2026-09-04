<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Publisher;

use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Audit;
use Appwrite\Event\Publisher\Usage;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Publisher\Asynchronous;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

final class RecordingPublisher implements Synchronous, Asynchronous
{
    /** @var list<string> */
    public array $enqueued = [];

    /** @var list<string> */
    public array $published = [];

    public function enqueue(Queue $queue, array $payload, bool $priority = false): void
    {
        $this->enqueued[] = $queue->name;
    }

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        $this->published[] = $queue->name;

        return true;
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return 0;
    }
}

final class BackgroundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('_APP_EDITION=cloud');
        putenv('_APP_USAGE_STATS=enabled');
    }

    protected function tearDown(): void
    {
        putenv('_APP_EDITION');
        putenv('_APP_USAGE_STATS');
        parent::tearDown();
    }

    public function testAuditUsesAsynchronousPublisher(): void
    {
        $publisher = new RecordingPublisher();
        $audit = new Audit($publisher, new Queue('audits'));

        $this->assertTrue($audit->enqueue(new AuditMessage('document.create', ['id' => 'document'])));
        $this->assertSame(['audits'], $publisher->enqueued);
        $this->assertSame([], $publisher->published);
    }

    public function testUsageUsesAsynchronousPublisher(): void
    {
        $publisher = new RecordingPublisher();
        $usage = new Usage($publisher, new Queue('usage'));

        $this->assertTrue($usage->enqueue(new UsageMessage(
            new Document(['$id' => 'project', '$sequence' => 42]),
            [['key' => 'requests', 'value' => 1]],
        )));
        $this->assertSame(['usage'], $publisher->enqueued);
        $this->assertSame([], $publisher->published);
    }

    public function testUsageRetainsSynchronousFallback(): void
    {
        $publisher = new MockPublisher();
        $usage = new Usage($publisher, new Queue('usage'));

        $this->assertTrue($usage->enqueue(new UsageMessage(
            new Document(['$id' => 'project', '$sequence' => 42]),
            [['key' => 'requests', 'value' => 1]],
        )));
        $this->assertCount(1, $publisher->getEvents('usage'));
    }
}
