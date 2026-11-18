<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;

trait JoinAliasDefault
{
    private const string UNIQUE_CUSTOMERS = '6ad1e9ed691819ab52ee';

    private const string HYPHENATED_CUSTOMERS = 'vip-customers';

    private const string LONG_CUSTOMERS = 'customers_with_the_longest_id_allowd';

    private const string LONG_KEY = 'name_with_the_longest_key_allowed_xx';

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

    public function testJoinWithoutAliasToAUniqueTableIdIsNamedAfterTheTable(): void
    {
        $this->assertJoinWithoutAliasReadsNames(self::UNIQUE_CUSTOMERS, 'name');
    }

    public function testJoinWithoutAliasToAHyphenatedTableIdIsNamedAfterTheTable(): void
    {
        $this->assertJoinWithoutAliasReadsNames(self::HYPHENATED_CUSTOMERS, 'name');
    }

    public function testJoinWithoutAliasReadsAColumnWhoseLabelExceedsTheIdentifierLimit(): void
    {
        $this->assertJoinWithoutAliasReadsNames(self::LONG_CUSTOMERS, self::LONG_KEY);
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

    private function assertJoinWithoutAliasReadsNames(string $tableId, string $key): void
    {
        $databaseId = $this->setupJoinAliasFixture();

        $result = $this->listOrders($databaseId, [
            \json_encode([
                'method' => 'join',
                'attribute' => $tableId,
                'values' => [['method' => 'on', 'values' => ['customerId', '=', $tableId . '.$id']]],
            ]),
            Query::select(['$id', $tableId . '.' . $key])->toString(),
            Query::orderAsc('$id')->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code'], $result['body']['message'] ?? '');
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertSame(['o1', 'o2', 'o3'], \array_column($rows, '$id'));
        $this->assertSame(['Alice', 'Alice', 'Bob'], \array_column($rows, $tableId . '.' . $key));
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

        $customers = [
            'customers' => 'name',
            self::UNIQUE_CUSTOMERS => 'name',
            self::HYPHENATED_CUSTOMERS => 'name',
            self::LONG_CUSTOMERS => self::LONG_KEY,
        ];

        foreach ([...\array_keys($customers), 'orders', 'my.customers'] as $containerId) {
            $container = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
                $this->getContainerIdParam() => $containerId,
                'name' => $containerId,
                'permissions' => [Permission::read(Role::any())],
            ]);
            $this->assertSame(201, $container['headers']['status-code'], $container['body']['message'] ?? '');
        }

        $attributes = [...$customers, 'orders' => 'customerId', 'my.customers' => 'name'];
        foreach ($attributes as $containerId => $key) {
            $attribute = $this->createAttribute($databaseId, $containerId, 'string', [
                'key' => $key,
                'size' => 36,
                'required' => false,
            ]);
            $this->assertSame(202, $attribute['headers']['status-code']);
            $this->waitForAttribute($databaseId, $containerId, $key);
        }

        $records = [];
        foreach ($customers as $containerId => $key) {
            $records[] = [$containerId, 'alice', [$key => 'Alice']];
            $records[] = [$containerId, 'bob', [$key => 'Bob']];
        }
        \array_push(
            $records,
            ['orders', 'o1', ['customerId' => 'alice']],
            ['orders', 'o2', ['customerId' => 'alice']],
            ['orders', 'o3', ['customerId' => 'bob']],
            ['orders', 'o4', ['customerId' => 'zed']],
        );
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
