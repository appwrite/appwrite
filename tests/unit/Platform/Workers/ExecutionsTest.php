<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\ExecutionCancelled;
use Appwrite\Platform\Workers\Executions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Queue\Message;

require_once __DIR__ . '/../../../../app/init.php';

final class ExecutionsTest extends TestCase
{
    public function testCreatesPendingExecutionWithoutUpsert(): void
    {
        $created = null;
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('createDocument')
            ->with('executions', $this->isInstanceOf(Document::class))
            ->willReturnCallback(function (string $collection, Document $execution) use (&$created): Document {
                $created = $execution;
                return $execution;
            });
        $dbForProject->expects($this->never())->method('upsertDocument');

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $dbForProject,
        );

        $this->assertInstanceOf(Document::class, $created);
        $this->assertSame('waiting', $created->getAttribute('status'));
    }

    public function testPendingExecutionDoesNotReplaceExistingTerminalExecution(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('createDocument')
            ->willThrowException(new Duplicate('Execution already exists'));
        $dbForProject->expects($this->never())->method('upsertDocument');

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $dbForProject,
        );
    }

    public function testUpsertsCompletedExecutionWithLogs(): void
    {
        $upserted = null;
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->never())->method('createDocument');
        $dbForProject->expects($this->once())
            ->method('upsertDocument')
            ->with('executions', $this->isInstanceOf(Document::class))
            ->willReturnCallback(function (string $collection, Document $execution) use (&$upserted): Document {
                $upserted = $execution;
                return $execution;
            });

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document([
                    '$id' => 'execution',
                    'status' => 'completed',
                    'logs' => 'output',
                ]),
            ))->toArray()),
            $dbForProject,
        );

        $this->assertInstanceOf(Document::class, $upserted);
        $this->assertSame('completed', $upserted->getAttribute('status'));
        $this->assertSame('output', $upserted->getAttribute('logs'));
    }

    public function testDeletesCancelledExecution(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('executions', 'execution')
            ->willReturn(true);

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $dbForProject,
        );
    }

    public function testRetriesCancelledExecutionWhenDeletionReturnsFalse(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('executions', 'execution')
            ->willReturn(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to remove cancelled execution');

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $dbForProject,
        );
    }

    public function testPropagatesCancelledExecutionDeletionFailure(): void
    {
        $error = new \RuntimeException('Database unavailable');
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('executions', 'execution')
            ->willThrowException($error);

        $this->expectExceptionObject($error);

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $dbForProject,
        );
    }

    private function message(array $payload): Message
    {
        return new Message([
            'pid' => 'pid',
            'queue' => 'v1-executions',
            'timestamp' => \time(),
            'payload' => $payload,
        ]);
    }
}
