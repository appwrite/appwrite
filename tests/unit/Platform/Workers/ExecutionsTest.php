<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\ExecutionCancelled;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Appwrite\Execution\Store;
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
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('upsert')->with('project', $this->isInstanceOf(Document::class));

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $dbForProject,
            $store,
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
        $dbForProject->expects($this->once())
            ->method('getDocument')
            ->with('executions', 'execution')
            ->willReturn(new Document(['$id' => 'execution', 'status' => 'completed']));
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsert')
            ->willReturnCallback(function (string $projectId, Document $execution): void {
                $this->assertSame('project', $projectId);
                $this->assertSame('completed', $execution->getAttribute('status'));
            });

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $dbForProject,
            $store,
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
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('upsert')->with('project', $this->isInstanceOf(Document::class));

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
            $store,
        );

        $this->assertInstanceOf(Document::class, $upserted);
        $this->assertSame('completed', $upserted->getAttribute('status'));
        $this->assertSame('output', $upserted->getAttribute('logs'));
    }

    public function testBatchMirrorsDatabaseAssignedSequences(): void
    {
        $stored = new Document([
            '$id' => 'execution',
            '$sequence' => 42,
            'status' => 'completed',
        ]);
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('upsertDocuments')
            ->willReturnCallback(function (string $collection, array $executions, int $batchSize, callable $onNext) use ($stored): int {
                $onNext($stored);
                return 1;
            });
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsertMany')
            ->willReturnCallback(function (string $projectId, array $executions): void {
                $this->assertSame('project', $projectId);
                $this->assertSame('42', $executions[0]->getSequence());
            });

        (new Executions())->action(
            $this->message((new ExecutionsMessage(
                project: new Document(['$id' => 'project']),
                executions: [new Document(['$id' => 'execution', 'status' => 'completed'])],
            ))->toArray()),
            $dbForProject,
            $store,
        );
    }

    public function testBatchRedeliveryHealsClickHouseAfterDatabaseNoOp(): void
    {
        $stored = new Document([
            '$id' => 'execution',
            '$sequence' => 42,
            'status' => 'completed',
        ]);
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('upsertDocuments')
            ->willReturn(0);
        $dbForProject->expects($this->once())
            ->method('getDocument')
            ->with('executions', 'execution')
            ->willReturn($stored);
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsertMany')
            ->with('project', [$stored]);

        (new Executions())->action(
            $this->message((new ExecutionsMessage(
                project: new Document(['$id' => 'project']),
                executions: [new Document(['$id' => 'execution', 'status' => 'completed'])],
            ))->toArray()),
            $dbForProject,
            $store,
        );
    }

    public function testDeletesCancelledExecution(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('executions', 'execution')
            ->willReturn(true);
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('delete')->with('project', $this->isInstanceOf(Document::class));

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $dbForProject,
            $store,
        );
    }

    public function testRetriesCancelledExecutionWhenDeletionReturnsFalse(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('executions', 'execution')
            ->willReturn(false);
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('delete')->with('project', $this->isInstanceOf(Document::class));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to remove cancelled execution');

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $dbForProject,
            $store,
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
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('delete')->with('project', $this->isInstanceOf(Document::class));

        $this->expectExceptionObject($error);

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $dbForProject,
            $store,
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
