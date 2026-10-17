<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;

trait JoinAliasDefault
{
    /**
     * @var array<string, array{databaseId: string}>
     */
    private static array $joinAliasFixtures = [];

    public function testJoinWithoutAliasIsNamedAfterTheTable(): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $result = $this->listOrders($databaseId, [
            '{"method":"join","attribute":"customers","values":[{"method":"on","values":["customerId","=","customers.$id"]}]}',
            Query::select(['$id', 'customers.name'])->toString(),
            Query::orderAsc('$id')->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code'], $result['body']['message'] ?? '');
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertSame(['o1', 'o2', 'o3'], \array_column($rows, '$id'));
        $this->assertSame(['Alice', 'Alice', 'Bob'], \array_column($rows, 'customers.name'));
        $this->assertSame(3, $result['body']['total']);
    }

    public function testColumnFormLeftJoinWithoutAliasIsNamedAfterTheTable(): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $result = $this->listOrders($databaseId, [
            '{"method":"leftJoin","attribute":"customers","values":["customerId","=","$id"]}',
            Query::select(['$id', 'customers.name'])->toString(),
            Query::orderAsc('$id')->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code'], $result['body']['message'] ?? '');
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertSame(['o1', 'o2', 'o3', 'o4'], \array_column($rows, '$id'));
        $this->assertSame(['Alice', 'Alice', 'Bob', null], \array_column($rows, 'customers.name'));
    }

    public function testCrossJoinWithoutAliasIsNamedAfterTheTable(): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $result = $this->listOrders($databaseId, [
            '{"method":"crossJoin","attribute":"customers"}',
            Query::select(['$id', 'customers.name'])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code'], $result['body']['message'] ?? '');
        $this->assertCount(8, $result['body'][$this->getRecordResource()]);
        $this->assertSame(8, $result['body']['total']);
    }

    public function testSelfJoinWithoutAliasIsNamedAfterTheTable(): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $result = $this->listOrders($databaseId, [
            '{"method":"join","attribute":"orders","values":[{"method":"on","values":["customerId","=","orders.customerId"]}]}',
            Query::select(['$id', 'orders.$id'])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code'], $result['body']['message'] ?? '');
        $this->assertSame(6, $result['body']['total']);
    }

    public function testJoinWithoutAliasToATableIdThatCannotBeAnAliasIsRefused(): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $result = $this->listOrders($databaseId, [
            '{"method":"join","attribute":"my.customers","values":[{"method":"on","values":["customerId","=","$id"]}]}',
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertStringContainsString('Table ID "my.customers" cannot be used as the join alias', $result['body']['message']);
        $this->assertStringContainsString('Pass an alias for the join', $result['body']['message']);
    }

    public function testJoiningTheSameTableTwiceWithoutAliasIsRefused(): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $join = '{"method":"join","attribute":"customers","values":[{"method":"on","values":["customerId","=","customers.$id"]}]}';
        $result = $this->listOrders($databaseId, [$join, $join]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertStringContainsString('customers', $result['body']['message']);
    }

    /**
     * @param  array<string>  $queries
     * @return array<string, mixed>
     */
    private function listOrders(string $databaseId, array $queries): array
    {
        return $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, 'orders'), \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $queries,
        ]);
    }

    private function setupJoinAliasFixture(): string
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $cacheKey = $this->getCacheKey();
        if (isset(self::$joinAliasFixtures[$cacheKey])) {
            return self::$joinAliasFixtures[$cacheKey]['databaseId'];
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, $this->getApiBasePath(), $headers, [
            'databaseId' => Id::unique(),
            'name' => 'Join Alias Database',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        foreach (['customers', 'orders', 'my.customers'] as $containerId) {
            $container = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
                $this->getContainerIdParam() => $containerId,
                'name' => $containerId,
                'permissions' => [Permission::read(Role::any())],
            ]);
            $this->assertSame(201, $container['headers']['status-code'], $container['body']['message'] ?? '');
        }

        foreach ([['customers', 'name'], ['orders', 'customerId'], ['my.customers', 'name']] as [$containerId, $key]) {
            $attribute = $this->createAttribute($databaseId, $containerId, 'string', [
                'key' => $key,
                'size' => 36,
                'required' => false,
            ]);
            $this->assertSame(202, $attribute['headers']['status-code']);
            $this->waitForAttribute($databaseId, $containerId, $key);
        }

        $records = [
            ['customers', 'alice', ['name' => 'Alice']],
            ['customers', 'bob', ['name' => 'Bob']],
            ['orders', 'o1', ['customerId' => 'alice']],
            ['orders', 'o2', ['customerId' => 'alice']],
            ['orders', 'o3', ['customerId' => 'bob']],
            ['orders', 'o4', ['customerId' => 'zed']],
        ];
        foreach ($records as [$containerId, $recordId, $data]) {
            $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $headers, [
                $this->getRecordIdParam() => $recordId,
                'data' => $data,
            ]);
            $this->assertSame(201, $record['headers']['status-code'], $record['body']['message'] ?? '');
        }

        self::$joinAliasFixtures[$cacheKey] = ['databaseId' => $databaseId];

        return $databaseId;
    }
}
