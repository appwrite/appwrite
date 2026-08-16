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
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('createDocument')
            ->with('executions', $this->callback(fn (Document $execution) => $execution->getAttribute('status') === 'waiting'));
        $dbForProject->expects($this->never())->method('upsertDocument');

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $dbForProject,
        );
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
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->never())->method('createDocument');
        $dbForProject->expects($this->once())
            ->method('upsertDocument')
            ->with('executions', $this->callback(function (Document $execution): bool {
                return $execution->getAttribute('status') === 'completed'
                    && $execution->getAttribute('logs') === 'output';
            }));

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
    }

    public function testDeletesCancelledExecution(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('executions', 'execution');

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
