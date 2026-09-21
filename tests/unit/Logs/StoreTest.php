<?php

declare(strict_types=1);

namespace Tests\Unit\Logs;

use Appwrite\Logs\Store;
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
    public function testCreatesRuntimeLogSnapshot(): void
    {
        $client = new CapturingClient();
        $store = $this->store($client);

        $store->create('project', new Document([
            '$id' => 'log1',
            'resourceType' => 'functions',
            'resourceId' => 'fn',
            'resourceInternalId' => '10',
            'deploymentId' => 'dep',
            'timestamp' => '2026-08-25T10:00:00.000+00:00',
            'stream' => 'stdout',
            'message' => 'hello',
        ]));

        $request = $client->requests[0];
        $query = \urldecode((string) $request->getUri());
        $this->assertStringContainsString('INSERT INTO', $query);
        $this->assertStringContainsString('`appwrite`.`runtime_logs`', $query);
        $row = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('project', $row['projectId']);
        $this->assertSame('log1', $row['id']);
        $this->assertSame('functions', $row['resourceType']);
        $this->assertSame('dep', $row['deploymentId']);
        $this->assertSame('stdout', $row['stream']);
        $this->assertSame('hello', $row['message']);
        $this->assertSame('2026-09-08 10:00:00.000', $row['expiresAt']);
    }

    public function testSetupCreatesMergeTreeTable(): void
    {
        $client = new CapturingClient([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
        ]);

        $this->store($client)->setup();

        $bodies = \array_map(
            static fn (RequestInterface $request): string => (string) $request->getBody(),
            $client->requests
        );
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS', $bodies[0]);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $bodies[1]);
        $this->assertStringContainsString('ENGINE = MergeTree', $bodies[1]);
        $this->assertStringContainsString('ORDER BY (projectId, deploymentId, timestamp, id)', $bodies[1]);
        $this->assertStringContainsString('MODIFY TTL expiresAt DELETE', $bodies[3]);
    }

    public function testFindsAndCountsWithDeploymentFilter(): void
    {
        $client = new CapturingClient([
            $this->jsonResponse([[
                'id' => 'log1',
                'resourceType' => 'functions',
                'resourceId' => 'fn',
                'resourceInternalId' => '10',
                'deploymentId' => 'dep',
                'timestamp' => '2026-08-25 10:00:00.000',
                'stream' => 'stderr',
                'message' => 'boom',
            ]]),
            $this->jsonResponse([['total' => 1]]),
        ]);

        $store = $this->store($client);
        $queries = [
            Query::equal('deploymentId', ['dep']),
            Query::equal('stream', ['stderr']),
            Query::limit(10),
        ];

        $logs = $store->find('project', $queries);
        $total = $store->count('project', $queries, 100);

        $this->assertCount(1, $logs);
        $this->assertSame('log1', $logs[0]->getId());
        $this->assertSame('stderr', $logs[0]->getAttribute('stream'));
        $this->assertSame(1, $total);

        $findBody = (string) $client->requests[0]->getBody();
        $this->assertStringContainsString('deploymentId', $findBody);
        $this->assertStringContainsString('ORDER BY timestamp DESC', $findBody);
    }

    public function testGetById(): void
    {
        $client = new CapturingClient([
            $this->jsonResponse([[
                'id' => 'log1',
                'resourceType' => 'sites',
                'resourceId' => 'site',
                'resourceInternalId' => '2',
                'deploymentId' => 'dep',
                'timestamp' => '2026-08-25 10:00:00.000',
                'stream' => 'stdout',
                'message' => 'ok',
            ]]),
        ]);

        $log = $this->store($client)->getById('project', 'log1');

        $this->assertSame('log1', $log->getId());
        $this->assertSame('sites', $log->getAttribute('resourceType'));
        $this->assertStringContainsString('id = {logId:String}', (string) $client->requests[0]->getBody());
    }

    public function testRejectsInvalidStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Runtime log stream must be stdout or stderr');

        $this->store(new CapturingClient())->create('project', new Document([
            '$id' => 'log1',
            'deploymentId' => 'dep',
            'stream' => 'other',
            'message' => 'x',
        ]));
    }

    public function testInsertFailuresPropagate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ClickHouse runtime log insert failed');

        $this->store(new FailingClient())->create('project', new Document([
            '$id' => 'log1',
            'deploymentId' => 'dep',
            'stream' => 'stdout',
            'message' => 'x',
        ]));
    }

    private function store(ClientInterface $client): Store
    {
        return new Store(
            dsn: 'http://appwrite:secret@clickhouse:8123/appwrite',
            client: $client,
        );
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
