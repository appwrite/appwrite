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
    public function testPersistsPendingExecution(): void
    {
        $store = new MemoryStore();

        (new Executions())->action(
            $this->message((new Execution(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution', 'status' => 'waiting']),
            ))->toArray()),
            $store,
        );

        $execution = $store->get('project', 'execution');
        $this->assertFalse($execution->isEmpty());
        $this->assertSame('waiting', $execution->getAttribute('status'));
    }

    public function testPersistsCompletedExecutionWithLogs(): void
    {
        $store = new MemoryStore();

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

        $execution = $store->get('project', 'execution');
        $this->assertSame('completed', $execution->getAttribute('status'));
        $this->assertSame('output', $execution->getAttribute('logs'));
    }

    public function testPersistsBatchExecutions(): void
    {
        $store = new MemoryStore();

        (new Executions())->action(
            $this->message((new ExecutionsMessage(
                project: new Document(['$id' => 'project']),
                executions: [
                    new Document(['$id' => 'execution-a', 'status' => 'completed']),
                    new Document(['$id' => 'execution-b', 'status' => 'failed']),
                ],
            ))->toArray()),
            $store,
        );

        $this->assertSame('completed', $store->get('project', 'execution-a')->getAttribute('status'));
        $this->assertSame('failed', $store->get('project', 'execution-b')->getAttribute('status'));
    }

    public function testRemovesCancelledExecution(): void
    {
        $store = new MemoryStore();
        $store->upsert('project', new Document(['$id' => 'execution', 'status' => 'scheduled']));

        (new Executions())->action(
            $this->message((new ExecutionCancelled(
                project: new Document(['$id' => 'project']),
                execution: new Document(['$id' => 'execution']),
            ))->toArray()),
            $store,
        );

        $this->assertTrue($store->get('project', 'execution')->isEmpty());
    }

    public function testPropagatesCancelledExecutionDeletionFailure(): void
    {
        $error = new \RuntimeException('ClickHouse unavailable');
        $store = new MemoryStore();
        $store->failure = $error;

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
        $store = new MemoryStore();
        $store->failure = $error;

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

final class MemoryStore extends Store
{
    /** @var array<string, array<string, Document>> */
    private array $executions = [];

    public ?\Throwable $failure = null;

    public function __construct()
    {
        parent::__construct(dsn: 'http://unused', client: null);
    }

    public function upsert(string $projectId, Document $execution): void
    {
        $this->upsertMany($projectId, [$execution]);
    }

    /** @param array<Document> $executions */
    public function upsertMany(string $projectId, array $executions): void
    {
        $this->failIfNeeded();
        foreach ($executions as $execution) {
            $this->executions[$projectId][$execution->getId()] = clone $execution;
        }
    }

    public function delete(string $projectId, Document $execution): void
    {
        $this->failIfNeeded();
        unset($this->executions[$projectId][$execution->getId()]);
    }

    public function get(string $projectId, string $executionId, ?array $roles = null): Document
    {
        return $this->executions[$projectId][$executionId] ?? new Document();
    }

    private function failIfNeeded(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
