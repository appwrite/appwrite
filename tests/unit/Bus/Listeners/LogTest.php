<?php

declare(strict_types=1);

namespace Tests\Unit\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCancelled;
use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Bus\Events\ExecutionScheduled;
use Appwrite\Bus\Listeners\ExecutionCancelledCleanup;
use Appwrite\Bus\Listeners\Log;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use Appwrite\Execution\Store;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Execution\CapturingClient;
use Tests\Unit\Execution\FailingClient;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../app/init.php';

final class LogTest extends TestCase
{
    public function testListensForScheduledAndCompletedExecutions(): void
    {
        $this->assertSame([
            ExecutionCompleted::class,
            ExecutionScheduled::class,
        ], Log::getEvents());
    }

    public function testScheduledExecutionIsStoredBeforeTheResponse(): void
    {
        $publisher = new MockPublisher();
        $publisherForExecutions = new ExecutionPublisher($publisher, new Queue('executions'));
        $client = new CapturingClient();

        (new Log())->handle(new ExecutionScheduled(
            execution: [
                '$id' => 'execution',
                'resourceType' => 'functions',
                'status' => 'waiting',
            ],
            project: ['$id' => 'project'],
        ), $publisherForExecutions, $this->store($client));

        $this->assertCount(1, $client->requests);
        $row = \json_decode((string) $client->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('execution', $row['id']);
        $this->assertSame('waiting', $row['status']);
        $this->assertNull($publisher->getEvents('executions'));
    }

    public function testScheduledExecutionIsQueuedWhenTheStoreFails(): void
    {
        $publisher = new MockPublisher();
        $publisherForExecutions = new ExecutionPublisher($publisher, new Queue('executions'));

        (new Log())->handle(new ExecutionScheduled(
            execution: [
                '$id' => 'execution',
                'status' => 'waiting',
            ],
            project: ['$id' => 'project'],
        ), $publisherForExecutions, $this->store(new FailingClient()));

        $events = $publisher->getEvents('executions');
        $this->assertCount(1, $events);
        $this->assertSame('execution', $events[0]['execution']['$id']);
    }

    public function testCompletedExecutionIsPublishedWithLogs(): void
    {
        $publisher = new MockPublisher();
        $publisherForExecutions = new ExecutionPublisher($publisher, new Queue('executions'));
        $client = new CapturingClient();

        (new Log())->handle(new ExecutionCompleted(
            execution: [
                '$id' => 'execution',
                'status' => 'completed',
                'logs' => 'output',
            ],
            project: ['$id' => 'project'],
        ), $publisherForExecutions, $this->store($client));

        $events = $publisher->getEvents('executions');
        $this->assertCount(1, $events);
        $this->assertSame('output', $events[0]['execution']['logs']);
        $this->assertSame([], $client->requests);
    }

    public function testCancellationIsPublishedAsDeleteOperation(): void
    {
        $publisher = new MockPublisher();
        $publisherForExecutions = new ExecutionPublisher($publisher, new Queue('executions'));

        (new ExecutionCancelledCleanup())->handle(new ExecutionCancelled(
            execution: ['$id' => 'execution'],
            project: ['$id' => 'project'],
        ), $publisherForExecutions);

        $events = $publisher->getEvents('executions');
        $this->assertCount(1, $events);
        $this->assertSame('delete', $events[0]['operation']);
        $this->assertSame('execution', $events[0]['execution']['$id']);
    }

    private function store(ClientInterface $client): Store
    {
        return new Store(
            dsn: 'http://appwrite:secret@clickhouse:8123/appwrite',
            client: $client,
            insertBuffer: 500,
        );
    }
}
