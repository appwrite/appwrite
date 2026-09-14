<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Appwrite\Event\Message\ExecutionCancelled;
use Appwrite\Event\Message\Executions as ExecutionsMessage;
use Appwrite\Execution\Store;
use Appwrite\Platform\Workers\Executions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Message;

require_once __DIR__ . '/../../../../app/init.php';

final class ExecutionsTest extends TestCase
{
    public function testUpsertsPendingExecution(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsert')
            ->willReturnCallback(function (string $projectId, Document $execution): void {
                $this->assertSame('project', $projectId);
                $this->assertSame('waiting', $execution->getAttribute('status'));
            });

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $store,
        );
    }

    public function testUpsertsCompletedExecutionWithLogs(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsert')
            ->willReturnCallback(function (string $projectId, Document $execution): void {
                $this->assertSame('project', $projectId);
                $this->assertSame('completed', $execution->getAttribute('status'));
                $this->assertSame('output', $execution->getAttribute('logs'));
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
            $store,
        );
    }

    public function testBatchUpsertsExecutions(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsertMany')
            ->willReturnCallback(function (string $projectId, array $executions): void {
                $this->assertSame('project', $projectId);
                $this->assertCount(1, $executions);
                $this->assertSame('completed', $executions[0]->getAttribute('status'));
            });

        (new Executions())->action(
            $this->message((new ExecutionsMessage(
                project: new Document(['$id' => 'project']),
                executions: [new Document(['$id' => 'execution', 'status' => 'completed'])],
            ))->toArray()),
            $store,
        );
    }

    public function testDeletesCancelledExecution(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects($this->once())->method('delete')->with('project', $this->isInstanceOf(Document::class));

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $store,
        );
    }

    public function testPropagatesCancelledExecutionDeletionFailure(): void
    {
        $error = new \RuntimeException('ClickHouse unavailable');
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('delete')
            ->willThrowException($error);

        $this->expectExceptionObject($error);

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $store,
        );
    }

    public function testPropagatesUpsertFailure(): void
    {
        $error = new \RuntimeException('ClickHouse unavailable');
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('upsert')
            ->willThrowException($error);

        $this->expectExceptionObject($error);

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'completed']),
            ))->toArray()),
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
