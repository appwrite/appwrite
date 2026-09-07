<?php

declare(strict_types=1);

namespace Tests\Unit\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCancelled;
use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Bus\Events\ExecutionScheduled;
use Appwrite\Bus\Listeners\ExecutionCancelledCleanup;
use Appwrite\Bus\Listeners\Log;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
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

    public function testCompletedExecutionIsPublishedWithLogs(): void
    {
        $publisher = new MockPublisher();
        $publisherForExecutions = new ExecutionPublisher($publisher, new Queue('executions'));

        (new Log())->handle(new ExecutionCompleted(
            execution: [
                '$id' => 'execution',
                'status' => 'completed',
                'logs' => 'output',
            ],
            project: ['$id' => 'project'],
        ), $publisherForExecutions);

        $events = $publisher->getEvents('executions');
        $this->assertCount(1, $events);
        $this->assertSame('output', $events[0]['execution']['logs']);
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
}
