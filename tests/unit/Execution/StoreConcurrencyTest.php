<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Appwrite\Execution\Store;
use ArrayObject;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Throwable;
use Utopia\Client\Pool as HttpClientPool;
use Utopia\Database\Document;
use Utopia\Pools\Adapter\Swoole as SwoolePoolAdapter;
use Utopia\Pools\Pool as Connections;

use function Swoole\Coroutine\run;

/**
 * run() leaves every runtime hook enabled and a reactor behind, so each test gets a process of its own.
 */
#[RunTestsInSeparateProcesses]
final class StoreConcurrencyTest extends TestCase
{
    private const float LATENCY = 0.2;

    private const int WRITERS = 30;

    private const int POOL_SIZE = 2;

    private const float POOL_TIMEOUT = 0.5;

    public function testConcurrentWritesShareOneConnection(): void
    {
        /** @var ArrayObject<int, array{uri: string, rows: list<array<string, mixed>>}> $requests */
        $requests = new ArrayObject();
        $store = $this->store($requests);
        $failures = [];

        run(function () use ($store, $requests, &$failures): void {
            $store->upsert('project', $this->execution('warmup'));
            $requests->exchangeArray([]);

            $group = new WaitGroup();
            for ($index = 0; $index < self::WRITERS; $index++) {
                $group->add();
                Coroutine::create(function () use ($store, $index, $group, &$failures): void {
                    try {
                        $store->upsert('project', $this->execution("execution-{$index}"));
                    } catch (Throwable $error) {
                        $failures[] = $error->getMessage();
                    } finally {
                        $group->done();
                    }
                });
            }
            $group->wait();
        });

        $this->assertSame([], $failures, 'Concurrent writes failed: ' . \implode('; ', $failures));
        foreach ($requests as $request) {
            $this->assertStringContainsString('INSERT+INTO+%60appwrite%60.%60executions%60', $request['uri']);
        }

        $ids = \array_map(fn (int $index): string => "execution-{$index}", \range(0, self::WRITERS - 1));
        $this->assertSame([['execution-0'], \array_slice($ids, 1)], $this->batches($requests), 'The first write flushes alone while every later write joins the next insert');
    }

    public function testDeletesAndBatchesJoinOneInsert(): void
    {
        /** @var ArrayObject<int, array{uri: string, rows: list<array<string, mixed>>}> $requests */
        $requests = new ArrayObject();
        $store = $this->store($requests);
        $failures = [];

        run(function () use ($store, &$failures): void {
            $operations = [
                fn () => $store->delete('project', $this->execution('deleted')),
                fn () => $store->upsertMany('project', [$this->execution('first'), $this->execution('second')]),
                fn () => $store->upsert('project', $this->execution('single')),
            ];

            $group = new WaitGroup();
            foreach ($operations as $operation) {
                $group->add();
                Coroutine::create(function () use ($operation, $group, &$failures): void {
                    try {
                        $operation();
                    } catch (Throwable $error) {
                        $failures[] = $error->getMessage();
                    } finally {
                        $group->done();
                    }
                });
            }
            $group->wait();
        });

        $this->assertSame([], $failures, 'Concurrent writes failed: ' . \implode('; ', $failures));
        $this->assertSame([['deleted'], ['first', 'second', 'single']], $this->batches($requests));

        $deleted = [];
        foreach ($requests as $request) {
            foreach ($request['rows'] as $row) {
                $deleted[$row['id']] = $row['deleted'];
            }
        }
        \ksort($deleted);
        $this->assertSame(['deleted' => 1, 'first' => 0, 'second' => 0, 'single' => 0], $deleted);
    }

    /** @param ArrayObject<int, array{uri: string, rows: list<array<string, mixed>>}> $requests */
    private function store(ArrayObject $requests): Store
    {
        $connections = new Connections(
            new SwoolePoolAdapter(),
            'executions',
            self::POOL_SIZE,
            fn () => new SlowClient($requests, self::LATENCY),
            timeout: self::POOL_TIMEOUT,
        );

        return new Store(
            dsn: 'http://appwrite:secret@clickhouse:8123/appwrite',
            client: new HttpClientPool($connections),
        );
    }

    private function execution(string $id): Document
    {
        return new Document([
            '$id' => $id,
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            'resourceInternalId' => '10',
            'resourceType' => 'functions',
            'status' => 'completed',
        ]);
    }

    /**
     * @param ArrayObject<int, array{uri: string, rows: list<array<string, mixed>>}> $requests
     * @return list<list<string>>
     */
    private function batches(ArrayObject $requests): array
    {
        $batches = [];
        foreach ($requests as $request) {
            $batches[] = \array_map(fn (array $row): string => $row['id'], $request['rows']);
        }

        return $batches;
    }
}
