<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Platform\Workers\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

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
