<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Publisher;

use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Message\Database as DatabaseMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Audit;
use Appwrite\Event\Publisher\Database;
use Appwrite\Event\Publisher\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Queue\Broker\Background;
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

    public function testPublishUsesBrokerEvenWhenBackgroundDeliveryIsAvailable(): void
    {
        $publisher = new RecordingPublisher();
        $usage = new Usage($publisher, new Queue('usage'));
        $message = new UsageMessage(
            new Document(['$id' => 'project', '$sequence' => 42]),
            [['key' => 'requests', 'value' => 1]],
        );

        $this->assertTrue($usage->publish($message));
        $this->assertSame(['usage'], $publisher->published);
        $this->assertSame([], $publisher->enqueued);
    }

    public static function dispatchMethods(): array
    {
        return [['publish'], ['enqueue']];
    }

    #[DataProvider('dispatchMethods')]
    public function testDisabledUsageDoesNotReachEitherDeliveryPath(string $method): void
    {
        putenv('_APP_USAGE_STATS=disabled');
        $publisher = new RecordingPublisher();
        $usage = new Usage($publisher, new Queue('usage'));

        $this->assertFalse($usage->$method(new UsageMessage(new Document(['$id' => 'project']), [])));
        $this->assertSame([], $publisher->published);
        $this->assertSame([], $publisher->enqueued);
    }

    #[DataProvider('dispatchMethods')]
    public function testSelfHostedAuditsRemainDisabled(string $method): void
    {
        putenv('_APP_EDITION=self-hosted');
        $publisher = new RecordingPublisher();
        $audit = new Audit($publisher, new Queue('audits'));

        $this->assertFalse($audit->$method(new AuditMessage('document.create', ['id' => 'document'])));
        $this->assertSame([], $publisher->published);
        $this->assertSame([], $publisher->enqueued);
    }

    #[DataProvider('dispatchMethods')]
    public function testDatabaseKeepsProjectRoutingAndExplicitOverrides(string $method): void
    {
        $publisher = new MockPublisher();
        $database = new Database($publisher, new Queue('default'));
        $message = new DatabaseMessage(project: new Document(['database' => 'mysql://shard-a:3306']));

        $database->$method($message);
        $database->$method($message, new Queue('override'));
        $database->$method(new DatabaseMessage());

        $this->assertSame([$message->toArray()], $publisher->getEvents('shard-a'));
        $this->assertSame([$message->toArray()], $publisher->getEvents('override'));
        $this->assertCount(1, $publisher->getEvents('default'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBoundPublisherDrainsBufferedMessagesAndPublishesSynchronously(): void
    {
        Coroutine\run(function (): void {
            $broker = new MockPublisher();
            $usage = new Usage(new Background($broker, maxBatchInterval: 60.0, maxBatchSize: 100), new Queue('usage'));
            $buffered = new UsageMessage(new Document(['$id' => 'buffered']), [['key' => 'requests', 'value' => 1]]);
            $synchronous = new UsageMessage(new Document(['$id' => 'synchronous']), [['key' => 'requests', 'value' => 2]]);

            $usage->start();
            $usage->enqueue($buffered);
            $usage->publish($synchronous);
            $this->assertSame([$synchronous->toArray()], $broker->getEvents('usage'));

            $usage->shutdown();
            $this->assertSame([$synchronous->toArray(), $buffered->toArray()], $broker->getEvents('usage'));
            $this->assertSame(2, $usage->getSize());
        });
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
