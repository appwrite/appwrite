<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Workers\Functions;
use Executor\Executor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound;
use Utopia\Queue\Message;

require_once __DIR__ . '/../../../../app/init.php';

final class FunctionsTest extends TestCase
{
    #[DataProvider('functionIdProvider')]
    public function testScheduledExecutionIsHydratedBeforeItIsEnqueued(array $scheduleData, string $fallbackFunctionId, string $expectedFunctionId): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $schedule = new Document([
            '$id' => 'schedule-id',
            'active' => true,
            'data' => array_merge([
                'userId' => 'user-id',
                'body' => 'body',
                'path' => '/path',
                'headers' => ['x-test' => 'value'],
                'method' => 'PATCH',
            ], $scheduleData),
        ]);
        $claimed = false;

        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturn($schedule);
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('updateDocument')
            ->with('schedules', 'schedule-id', $this->callback(function (Document $schedule) use (&$claimed): bool {
                $claimed = $schedule->getAttribute('active') === false;
                return $claimed;
            }))
            ->willReturn(new Document(['$id' => 'schedule-id', 'active' => false]));
        $dbForPlatform
            ->expects($this->once())
            ->method('deleteDocument')
            ->with('schedules', 'schedule-id')
            ->willReturn(true);

        $message = null;
        $project = new Document([
            '$id' => 'project-id',
            'accessedAt' => DateTime::now(),
        ]);
        $execution = new Document([
            '$id' => 'execution-id',
            'scheduleId' => 'schedule-id',
        ]);

        $this->assertTrue($worker->schedule(
            $dbForPlatform,
            $project,
            $execution,
            $fallbackFunctionId,
            function (FunctionMessage $candidate) use (&$claimed, &$message): void {
                $this->assertTrue($claimed, 'Schedule must be claimed before it is published');
                $message = $candidate;
            },
        ));

        $this->assertInstanceOf(FunctionMessage::class, $message);
        $this->assertSame('schedule', $message->type);
        $this->assertSame($expectedFunctionId, $message->functionId);
        $this->assertSame('user-id', $message->userId);
        $this->assertSame('execution-id', $message->execution->getId());
        $this->assertSame('', $message->execution->getAttribute('scheduleId', ''));
        $this->assertSame('body', $message->body);
        $this->assertSame('/path', $message->path);
        $this->assertSame(['x-test' => 'value'], $message->headers);
        $this->assertSame('PATCH', $message->method);
        $this->assertSame(
            0,
            $worker->projectAccessUpdates,
            'Access is recorded once per message in action(), so the republish path must not write it again'
        );
    }

    #[DataProvider('inactiveScheduleProvider')]
    public function testCancelledOrMissingScheduleIsNotEnqueued(Document $schedule): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->with('schedules', 'schedule-id', [], true)
            ->willReturn($schedule);
        $dbForPlatform->expects($this->never())->method('updateDocument');
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->assertFalse($worker->schedule(
            $dbForPlatform,
            new Document(['$id' => 'project-id']),
            new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
            'function-id',
            fn () => $this->fail('Cancelled schedule was enqueued'),
        ));
    }

    public function testFailedPublishReleasesScheduleClaim(): void
    {
        $worker = $this->worker();
        $dbForPlatform = $this->createMock(Database::class);
        $updates = [];
        $dbForPlatform
            ->expects($this->once())
            ->method('withTransaction')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());
        $dbForPlatform
            ->expects($this->once())
            ->method('getDocument')
            ->willReturn(new Document([
                '$id' => 'schedule-id',
                'active' => true,
                'data' => ['functionId' => 'function-id'],
            ]));
        $dbForPlatform
            ->expects($this->exactly(2))
            ->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $schedule) use (&$updates): Document {
                $updates[] = $schedule->getAttribute('active');
                return new Document(['$id' => $id, 'active' => $schedule->getAttribute('active')]);
            });
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue unavailable');

        try {
            $worker->schedule(
                $dbForPlatform,
                new Document(['$id' => 'project-id', 'accessedAt' => DateTime::now()]),
                new Document(['$id' => 'execution-id', 'scheduleId' => 'schedule-id']),
                '',
                fn () => throw new \RuntimeException('Queue unavailable'),
            );
        } finally {
            $this->assertSame([false, true], $updates);
        }
    }

    /**
     * A schedule that fires between a project's deletion and the scheduler
     * dropping it reaches the worker with a project that resolves to nothing.
     * dbForProject then falls back to the platform database, which has no
     * functions or deployments collection: in production every such run threw
     * "Collection not found" and was dead-lettered.
     */
    #[DataProvider('deletedProjectRunProvider')]
    public function testRunOfDeletedProjectIsAcknowledgedWithoutExecuting(string $type): void
    {
        $worker = $this->worker();

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willThrowException(new NotFound('Collection not found'));
        $dbForProject->method('find')->willThrowException(new NotFound('Collection not found'));

        $executor = $this->createMock(Executor::class);
        $executor->expects($this->never())->method('createExecution');

        $bus = $this->createMock(Bus::class);
        $bus->expects($this->never())->method('dispatch');

        $publisher = $this->createMock(FunctionPublisher::class);
        $publisher->expects($this->never())->method('enqueue');

        $payload = new FunctionMessage(
            project: new Document(['$id' => 'deleted-project']),
            function: new Document(['$id' => 'function-id', 'deploymentId' => 'deployment-id']),
            execution: new Document(['$id' => 'execution-id']),
            type: $type,
            method: 'POST',
            path: '/',
        );

        // Returning is what acknowledges the message; a throw would dead-letter it.
        $worker->action(
            project: new Document(),
            message: new Message([
                'pid' => 'pid',
                'queue' => 'v1-functions',
                'timestamp' => \time(),
                'payload' => $payload->toArray(),
            ]),
            dbForProject: $dbForProject,
            dbForPlatform: $this->createStub(Database::class),
            queueForWebhooks: $this->createStub(Webhook::class),
            publisherForFunctions: $publisher,
            queueForRealtime: $this->createStub(Realtime::class),
            queueForEvents: $this->createStub(Event::class),
            bus: $bus,
            executor: $executor,
            getIsResourceBlocked: fn (): bool => false,
            locks: fn (string $key, int $ttl, callable $callback): mixed => $callback(),
            cache: $this->createStub(Cache::class),
        );
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function deletedProjectRunProvider(): \Iterator
    {
        yield 'schedule' => ['schedule'];
        yield 'http' => ['http'];
    }

    /**
     * @return \Iterator<string, array{array<string, string>, string, string}>
     */
    public static function functionIdProvider(): \Iterator
    {
        yield 'current schedule data' => [['functionId' => 'function-id'], 'legacy-function-id', 'function-id'];
        yield 'legacy execution resource' => [[], 'legacy-function-id', 'legacy-function-id'];
    }

    /**
     * @return \Iterator<string, array{\Utopia\Database\Document}>
     */
    public static function inactiveScheduleProvider(): \Iterator
    {
        yield 'inactive' => [new Document(['$id' => 'schedule-id', 'active' => false])];
        yield 'missing' => [new Document()];
    }

    private function worker(): TestFunctions
    {
        return new TestFunctions();
    }
}

final class TestFunctions extends Functions
{
    public int $projectAccessUpdates = 0;

    public function schedule(Database $dbForPlatform, Document $project, Document $execution, string $functionId, callable $enqueue): bool
    {
        return $this->enqueueScheduledExecution($dbForPlatform, $project, $execution, $functionId, $enqueue);
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        $this->projectAccessUpdates++;
    }
}
