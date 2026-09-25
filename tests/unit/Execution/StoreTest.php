<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Appwrite\Execution\Store;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class StoreTest extends TestCase
{
    public function testCreatesExecutionSnapshot(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);

        $store->create('project', new Document([
            '$id' => 'execution',
            '$sequence' => 42,
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            '$updatedAt' => '2026-08-25T10:00:00.000+00:00',
            '$permissions' => ['read("user:abc")'],
            'resourceInternalId' => '10',
            'resourceId' => 'function',
            'resourceType' => 'functions',
            'status' => 'waiting',
        ]));

        $request = $client->requests[0];
        $this->assertStringContainsString('INSERT+INTO+%60appwrite%60.%60executions%60', (string) $request->getUri());
        $row = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('project', $row['projectId']);
        $this->assertSame('execution', $row['id']);
        $this->assertSame(['user:abc'], $row['readRoles']);
        $this->assertSame('waiting', $row['status']);
        $this->assertSame(0, $row['deleted']);
        $this->assertSame(42, $row['sequence']);
        $this->assertGreaterThan(0, $row['version']);
        $this->assertSame('2026-09-08 10:00:00.000', $row['expiresAt']);
    }

    public function testUpdatesAndDeletesWithNewSnapshots(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);
        $execution = new Document([
            '$id' => 'execution',
            '$createdAt' => '2020-08-25T10:00:00.000+00:00',
            '$updatedAt' => '2020-08-25T10:00:01.000+00:00',
            'resourceInternalId' => '10',
            'resourceType' => 'sites',
            'status' => 'completed',
            'logs' => 'done',
        ]);

        $store->update('project', $execution);
        $store->delete('project', $execution);

        $updated = \json_decode((string) $client->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $deleted = \json_decode((string) $client->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $updated['deleted']);
        $this->assertSame(1, $deleted['deleted']);
        $this->assertGreaterThan($updated['version'], $deleted['version']);
        $this->assertGreaterThan($updated['expiresAt'], $deleted['expiresAt']);
        $this->assertStringContainsString('"logs":"done"', (string) $updated['document']);
    }

    public function testGetsLatestAuthorizedExecution(): void
    {
        $document = [
            '$id' => 'execution',
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            'resourceType' => 'functions',
            'status' => 'completed',
        ];
        $client = new CapturingClient([
            $this->jsonResponse([['document' => \json_encode($document, JSON_THROW_ON_ERROR)]]),
        ]);

        $execution = $this->store($client)->get('project', 'execution', ['user:abc']);

        $this->assertSame('execution', $execution->getId());
        $this->assertSame('completed', $execution->getAttribute('status'));
        $body = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString('argMax(source.document, source.version)', $body);
        $this->assertStringContainsString('AS source', $body);
        $this->assertStringContainsString('hasAny(readRoles', $body);
        $this->assertStringContainsString('user:abc', $body);
    }

    public function testFindsAndCountsWithExecutionQueries(): void
    {
        $document = [
            '$id' => 'execution',
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            'resourceType' => 'functions',
            'status' => 'failed',
        ];
        $client = new CapturingClient([
            $this->jsonResponse([['document' => \json_encode($document, JSON_THROW_ON_ERROR)]]),
            $this->jsonResponse([['total' => 1]]),
        ]);
        $store = $this->store($client);
        $queries = [
            Query::equal('resourceType', ['functions']),
            Query::equal('status', ['failed']),
            Query::orderDesc('$createdAt'),
            Query::limit(10),
        ];

        $executions = $store->find('project', $queries);
        $total = $store->count('project', $queries, 5);

        $this->assertCount(1, $executions);
        $this->assertSame(1, $total);
        $find = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString('resourceType IN', $find);
        $this->assertStringContainsString('status IN', $find);
        $this->assertStringContainsString('ORDER BY createdAt DESC, sequence DESC, id DESC', $find);
        $this->assertStringContainsString('LIMIT {param0:Int64}', $find);
        $this->assertStringContainsString('name="param_param0"', $find);
        $this->assertStringContainsString('least(count()', (string) $client->requests[1]->getBody());
    }

    public function testBulkDeleteUsesLatestSnapshots(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);

        $store->deleteByResource('project', '10', 'functions', '2026-08-01T00:00:00.000+00:00');

        $body = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString('INSERT INTO `appwrite`.`executions`', $body);
        $this->assertStringContainsString('argMax(source.deleted, source.version)', $body);
        $this->assertStringContainsString('source.resourceInternalId = {resourceInternalId:String}', $body);
        $this->assertStringContainsString('source.createdAt < {createdBefore:String}', $body);
        $this->assertStringContainsString('now64(6) + INTERVAL 1209600 SECOND', $body);
        $this->assertStringContainsString('expiresAt > now64(6)', $body);
    }

    public function testReadsHideExecutionsPastRetention(): void
    {
        $client = new CapturingClient([
            $this->jsonResponse([]),
            $this->jsonResponse([]),
            $this->jsonResponse([['total' => 0]]),
        ]);
        $store = $this->store($client);

        $store->get('project', 'execution');
        $store->find('project', [Query::equal('status', ['completed'])]);
        $store->count('project', [], 25);

        foreach ($client->requests as $request) {
            $this->assertStringContainsString('expiresAt > now64(6)', (string) $request->getBody());
        }
    }

    public function testTerminalAndDeleteVersionsWinOverLatePendingWrites(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);
        $execution = new Document([
            '$id' => 'execution',
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            'status' => 'waiting',
        ]);

        $store->upsert('project', $execution);
        $execution->setAttribute('status', 'completed');
        $store->upsert('project', $execution);
        $execution->setAttribute('status', 'waiting');
        $store->upsert('project', $execution);
        $store->delete('project', $execution);

        $waiting = \json_decode((string) $client->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $completed = \json_decode((string) $client->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $lateWaiting = \json_decode((string) $client->requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $deleted = \json_decode((string) $client->requests[3]->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThan($waiting['version'], $completed['version']);
        $this->assertGreaterThan($lateWaiting['version'], $completed['version']);
        $this->assertGreaterThan($completed['version'], $deleted['version']);
    }

    public function testCursorUsesEveryOrderAndReversesCursorBeforeOrdering(): void
    {
        $client = new CapturingClient([$this->jsonResponse([])]);
        $cursor = new Document([
            '$id' => 'execution',
            '$sequence' => 42,
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            'status' => 'failed',
        ]);

        $this->store($client)->find('project', [
            Query::orderAsc('status'),
            Query::orderDesc('$createdAt'),
            Query::cursorBefore($cursor),
        ]);

        $body = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString('ORDER BY status DESC, createdAt ASC, sequence DESC, id DESC', $body);
        $this->assertStringContainsString('status <', $body);
        $this->assertStringContainsString('status =', $body);
        $this->assertStringContainsString('createdAt >', $body);
        $this->assertStringContainsString('sequence <', $body);
        $this->assertStringContainsString('id <', $body);
    }

    public function testOrdersByIdWhenSequenceIsAlreadyUnique(): void
    {
        $client = new CapturingClient([$this->jsonResponse([])]);

        $this->store($client)->find('project', [Query::orderDesc('$sequence')]);

        $this->assertStringContainsString(
            'ORDER BY sequence DESC, id DESC',
            (string) $client->requests[0]->getBody()
        );
    }

    public function testDoesNotDuplicateExistingIdOrder(): void
    {
        $client = new CapturingClient([$this->jsonResponse([])]);

        $this->store($client)->find('project', [Query::orderAsc('$id')]);

        $body = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString('ORDER BY id ASC', $body);
        $this->assertStringNotContainsString('id ASC, id ASC', $body);
    }

    public function testCompilesStringQueryOperators(): void
    {
        $client = new CapturingClient([$this->jsonResponse([])]);

        $this->store($client)->find('project', [
            Query::containsAny('requestPath', ['/users', '/teams']),
            Query::containsAll('requestPath', ['/v1', '/users']),
            Query::regex('requestPath', '^/v1/'),
        ]);

        $body = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString(' OR ', $body);
        $this->assertStringContainsString(' AND ', $body);
        $this->assertStringContainsString('match(requestPath', $body);
    }

    public function testSetsUpExecutionTable(): void
    {
        $client = new CapturingClient();

        $this->store($client)->setup();

        $this->assertCount(4, $client->requests);
        $requests = \implode("\n", \array_map(
            fn (RequestInterface $request) => (string) $request->getBody(),
            $client->requests
        ));
        $this->assertStringContainsString('ReplacingMergeTree(version)', $requests);
        $this->assertStringContainsString('ORDER BY (projectId, resourceType, resourceInternalId, id)', $requests);
        $this->assertStringContainsString('MODIFY TTL expiresAt DELETE', $requests);
    }

    public function testHealthChecksExecutionSchema(): void
    {
        $client = new CapturingClient([
            $this->jsonResponse([['tables' => 1]]),
            $this->jsonResponse([]),
        ]);

        $health = $this->store($client)->healthCheck();

        $this->assertTrue($health['healthy']);
        $this->assertTrue($health['schemaReady']);
        $this->assertCount(2, $client->requests);
        $this->assertStringContainsString('resourceInternalId', (string) $client->requests[1]->getBody());
    }

    public function testAssignsSequenceFromCreatedAtWhenMissing(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);
        $createdAt = '2026-08-25T10:00:00.123456+00:00';

        $store->create('project', new Document([
            '$id' => 'execution-a',
            '$createdAt' => $createdAt,
            '$updatedAt' => $createdAt,
            'status' => 'waiting',
        ]));
        $store->create('project', new Document([
            '$id' => 'execution-b',
            '$createdAt' => $createdAt,
            '$updatedAt' => $createdAt,
            'status' => 'waiting',
        ]));
        $store->create('project', new Document([
            '$id' => 'execution-c',
            '$createdAt' => '2026-08-25T10:00:01.000000+00:00',
            '$updatedAt' => '2026-08-25T10:00:01.000000+00:00',
            'status' => 'waiting',
        ]));
        $store->create('project', new Document([
            '$id' => 'execution-d',
            '$sequence' => 42,
            '$createdAt' => $createdAt,
            '$updatedAt' => $createdAt,
            'status' => 'waiting',
        ]));

        $first = \json_decode((string) $client->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $sameCreatedAt = \json_decode((string) $client->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $later = \json_decode((string) $client->requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $preserved = \json_decode((string) $client->requests[3]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $document = \json_decode((string) $first['document'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(0, $first['sequence']);
        $this->assertSame($first['sequence'], $document['$sequence']);
        $this->assertSame($first['sequence'], $sameCreatedAt['sequence']);
        $this->assertNotSame($first['sequence'], $later['sequence']);
        $this->assertSame(42, $preserved['sequence']);
    }

    public function testPersistsPublicIso8601Timestamps(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);
        $stored = '2026-09-14 21:27:33.884';

        $store->create('project', new Document([
            '$id' => 'execution',
            '$createdAt' => $stored,
            '$updatedAt' => $stored,
            'scheduledAt' => $stored,
            'resourceType' => 'functions',
            'status' => 'completed',
        ]));

        $row = \json_decode((string) $client->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $document = \json_decode((string) $row['document'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('2026-09-14T21:27:33.884+00:00', $document['$createdAt']);
        $this->assertSame('2026-09-14T21:27:33.884+00:00', $document['$updatedAt']);
        $this->assertSame('2026-09-14T21:27:33.884+00:00', $document['scheduledAt']);
        $this->assertSame('2026-09-14 21:27:33.884', $row['createdAt']);
        $this->assertSame('2026-09-14 21:27:33.884', $row['updatedAt']);
    }

    public function testFillsMissingTimestampsAsIso8601(): void
    {
        $client = new CapturingClient();
        $this->store($client)->create('project', new Document([
            '$id' => 'execution',
            'resourceType' => 'functions',
            'status' => 'completed',
        ]));

        $row = \json_decode((string) $client->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $document = \json_decode((string) $row['document'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertRfc3339($document['$createdAt']);
        $this->assertRfc3339($document['$updatedAt']);
    }

    public function testReadsStoredDbTimestampsAsIso8601(): void
    {
        $stored = '2026-09-14 21:27:33.884';
        $client = new CapturingClient([
            $this->jsonResponse([['document' => \json_encode([
                '$id' => 'execution',
                '$createdAt' => $stored,
                '$updatedAt' => $stored,
                'scheduledAt' => $stored,
                'resourceType' => 'functions',
                'status' => 'completed',
            ], JSON_THROW_ON_ERROR)]]),
        ]);

        $execution = $this->store($client)->get('project', 'execution');

        $this->assertSame('2026-09-14T21:27:33.884+00:00', $execution->getCreatedAt());
        $this->assertSame('2026-09-14T21:27:33.884+00:00', $execution->getUpdatedAt());
        $this->assertSame('2026-09-14T21:27:33.884+00:00', $execution->getAttribute('scheduledAt'));
    }

    public function testWriteFailuresPropagate(): void
    {
        $store = $this->store(new FailingClient());
        $execution = new Document([
            '$id' => 'execution',
            '$createdAt' => '2026-08-25T10:00:00.000+00:00',
            'resourceType' => 'functions',
            'status' => 'completed',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse execution insert failed');

        $store->create('project', $execution);
    }

    public function testDeleteFailuresPropagate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse execution query failed');

        $this->store(new FailingClient())->deleteProject('project');
    }

    public function testSetupFailuresRemainVisible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse execution query failed');

        $this->store(new FailingClient())->setup();
    }

    private function store(ClientInterface $client): Store
    {
        return new Store(
            dsn: 'http://appwrite:secret@clickhouse:8123/appwrite',
            client: $client,
        );
    }

    private function assertRfc3339(string $value): void
    {
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $value);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed, $value);
        $this->assertSame($parsed->format(\DateTimeInterface::RFC3339_EXTENDED), $value);
    }

    /** @param list<array<string, mixed>> $rows */
    private function jsonResponse(array $rows): ResponseInterface
    {
        return new Response(200, body: new Stream(\json_encode(['data' => $rows], JSON_THROW_ON_ERROR)));
    }
}

final class CapturingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return \array_shift($this->responses) ?? new Response(200);
    }
}

final class FailingClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('ClickHouse unavailable');
    }
}
