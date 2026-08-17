<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait QueryJoinPermissions
{
    private static array $joinPermissionsCache = [];

    protected function setupJoinPermissionsFixture(): array
    {
        $cacheKey = $this->getCacheKey();
        if (!empty(self::$joinPermissionsCache[$cacheKey])) {
            return self::$joinPermissionsCache[$cacheKey];
        }

        $data = $this->setupDatabase();
        $databaseId = $data['databaseId'];
        $userId = $this->getUser()['$id'];
        $suffix = ID::unique();

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $customers = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpCustomers' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $customers['headers']['status-code']);
        $customersId = $customers['body']['$id'];

        $orders = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpOrders' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $orders['headers']['status-code']);
        $ordersId = $orders['body']['$id'];

        $private = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpPrivate' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [],
        ]);
        $this->assertSame(201, $private['headers']['status-code']);
        $privateId = $private['body']['$id'];

        $this->createAttribute($databaseId, $customersId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $ordersId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $ordersId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $privateId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $privateId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);

        $this->waitForAttribute($databaseId, $customersId, 'name');
        $this->waitForAttribute($databaseId, $ordersId, 'customerId');
        $this->waitForAttribute($databaseId, $ordersId, 'amount');
        $this->waitForAttribute($databaseId, $privateId, 'customerId');
        $this->waitForAttribute($databaseId, $privateId, 'secret');

        $alice = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $customersId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Alice'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $alice['headers']['status-code']);
        $aliceId = $alice['body']['$id'];

        $carol = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $customersId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Carol'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $carol['headers']['status-code']);
        $carolId = $carol['body']['$id'];

        $publicOrder = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $ordersId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 100,
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $publicOrder['headers']['status-code']);

        $secretOrder = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $ordersId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 9999,
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $secretOrder['headers']['status-code']);

        $orphanOrder = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $ordersId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'amount' => 8888,
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $orphanOrder['headers']['status-code']);

        $privateRow = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $privateId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'secret' => 'classified-join-data',
            ],
        ]);
        $this->assertSame(201, $privateRow['headers']['status-code']);

        $selfJoin = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpSelfJoin' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $selfJoin['headers']['status-code']);
        $selfJoinId = $selfJoin['body']['$id'];

        $dsOffSource = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpDsOffSource' . $suffix,
            $this->getSecurityParam() => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $dsOffSource['headers']['status-code']);
        $dsOffSourceId = $dsOffSource['body']['$id'];

        $dsOffJoined = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpDsOffJoined' . $suffix,
            $this->getSecurityParam() => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $dsOffJoined['headers']['status-code']);
        $dsOffJoinedId = $dsOffJoined['body']['$id'];

        $dsOffDenied = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpDsOffDenied' . $suffix,
            $this->getSecurityParam() => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $dsOffDenied['headers']['status-code']);
        $dsOffDeniedId = $dsOffDenied['body']['$id'];

        $this->createAttribute($databaseId, $selfJoinId, 'string', [
            'key' => 'payload',
            'size' => 128,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $selfJoinId, 'string', [
            'key' => 'code',
            'size' => 128,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $selfJoinId, 'string', [
            'key' => 'tag',
            'size' => 32,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $dsOffSourceId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $dsOffJoinedId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $dsOffJoinedId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $dsOffDeniedId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $dsOffDeniedId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);

        $this->waitForAttribute($databaseId, $selfJoinId, 'payload');
        $this->waitForAttribute($databaseId, $selfJoinId, 'code');
        $this->waitForAttribute($databaseId, $selfJoinId, 'tag');
        $this->waitForAttribute($databaseId, $dsOffSourceId, 'name');
        $this->waitForAttribute($databaseId, $dsOffJoinedId, 'customerId');
        $this->waitForAttribute($databaseId, $dsOffJoinedId, 'secret');
        $this->waitForAttribute($databaseId, $dsOffDeniedId, 'customerId');
        $this->waitForAttribute($databaseId, $dsOffDeniedId, 'secret');

        $openSelf = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $selfJoinId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'payload' => 'open-payload',
                'code' => 'open-code',
                'tag' => 'shared',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $openSelf['headers']['status-code']);

        $secretSelf = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $selfJoinId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'payload' => 'classified-join-data',
                'code' => 'classified-join-data',
                'tag' => 'shared',
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $secretSelf['headers']['status-code']);

        $dsOffRow = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $dsOffSourceId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Alice'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $dsOffRow['headers']['status-code']);
        $dsOffRowId = $dsOffRow['body']['$id'];

        $dsOffVisible = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $dsOffJoinedId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $dsOffRowId,
                'secret' => 'classified-join-data',
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $dsOffVisible['headers']['status-code']);

        $dsOffHidden = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $dsOffDeniedId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $dsOffRowId,
                'secret' => 'classified-join-data',
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $dsOffHidden['headers']['status-code']);

        self::$joinPermissionsCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'customersId' => $customersId,
            'ordersId' => $ordersId,
            'privateId' => $privateId,
            'aliceId' => $aliceId,
            'carolId' => $carolId,
            'selfJoinId' => $selfJoinId,
            'dsOffSourceId' => $dsOffSourceId,
            'dsOffJoinedId' => $dsOffJoinedId,
            'dsOffDeniedId' => $dsOffDeniedId,
            'dsOffRowId' => $dsOffRowId,
        ];

        return self::$joinPermissionsCache[$cacheKey];
    }

    public function testListJoinWithoutTableReadDenied(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        if ($this->getSide() === 'client') {
            $this->assertContains($result['headers']['status-code'], [400, 401]);
            $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body']));
        } else {
            $this->assertSame(200, $result['headers']['status-code']);
        }
    }

    public function testGetJoinWithoutTableReadDenied(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        if ($this->getSide() === 'client') {
            $this->assertContains($result['headers']['status-code'], [400, 401]);
            $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body']));
        } else {
            $this->assertSame(200, $result['headers']['status-code']);
        }
    }

    public function testGetLeftJoinUnmatchedOk(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('carol', $result['body']['$id']);
        $this->assertSame('Carol', $result['body']['name']);
        $amount = $result['body']['ord.amount'] ?? $result['body']['amount'] ?? null;
        $this->assertTrue($amount === null || $amount === '', 'unmatched order amount must be nullish, not 0');
    }

    public function testGetInnerJoinUnmatchedNotFound(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['ordersId'], '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(404, $result['headers']['status-code']);
    }

    public function testListFullOuterJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = [];
        foreach ($rows as $row) {
            $amount = $row['rev.amount'] ?? $row['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = (string) json_encode($result['body']);
        if ($this->getSide() === 'client') {
            $this->assertContains(100, $amounts);
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
        } else {
            $this->assertContains(100, $amounts);
            $this->assertContains(9999, $amounts);
        }
    }

    public function testSelectJoinColumnOmitsSecretValues(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = [];
        foreach ($rows as $row) {
            $amount = $row['rev.amount'] ?? $row['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = (string) json_encode($result['body']);
        if ($this->getSide() === 'client') {
            $this->assertContains(100, $amounts);
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
        } else {
            $this->assertContains(100, $amounts);
            $this->assertContains(9999, $amounts);
        }
    }

    public function testGetSelectJoinColumnOmitsSecretValues(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $amount = $result['body']['rev.amount'] ?? $result['body']['amount'] ?? null;
        $encoded = (string) json_encode($result['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(100, (int) $amount);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
        } else {
            $this->assertContains((int) $amount, [100, 9999]);
        }
    }

    public function testListJoinApiKeyBypassesTablePermissions(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'queries' => [
                Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertNotEmpty($rows);
        $secrets = [];
        foreach ($rows as $row) {
            $secret = $row['rev.secret'] ?? $row['secret'] ?? null;
            if ($secret !== null && $secret !== '') {
                $secrets[] = $secret;
            }
        }
        $this->assertContains('classified-join-data', $secrets);
    }

    public function testListRightJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = [];
        foreach ($rows as $row) {
            $amount = $row['rev.amount'] ?? $row['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = (string) json_encode($result['body'] ?? []);
        if ($this->getSide() === 'client') {
            $this->assertContains(100, $amounts);
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertContains(100, $amounts);
            $this->assertContains(9999, $amounts);
        }
    }

    public function testGetRightJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $amount = $result['body']['rev.amount'] ?? $result['body']['amount'] ?? null;
        $encoded = (string) json_encode($result['body'] ?? []);

        if ($this->getSide() === 'client') {
            $this->assertSame(100, (int) $amount);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertContains((int) $amount, [100, 9999]);
        }
    }

    public function testListCrossJoinDoesNotLeakSecretRows(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::crossJoin($data['ordersId'], 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = [];
        foreach ($rows as $row) {
            $amount = $row['rev.amount'] ?? $row['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = (string) json_encode($result['body'] ?? []);
        if ($this->getSide() === 'client') {
            $this->assertNotEmpty($amounts);
            foreach ($amounts as $amount) {
                $this->assertSame(100, $amount);
            }
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertContains(100, $amounts);
            $this->assertContains(9999, $amounts);
        }
    }

    public function testDocumentSecurityOffHonorsCollectionRead(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['dsOffSourceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($data['dsOffJoinedId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertNotEmpty($rows);
        $secrets = [];
        foreach ($rows as $row) {
            $secret = $row['rev.secret'] ?? $row['secret'] ?? null;
            if ($secret !== null && $secret !== '') {
                $secrets[] = $secret;
            }
        }
        $encoded = (string) json_encode($result['body'] ?? []);
        $this->assertContains('classified-join-data', $secrets);
        $this->assertStringContainsString('classified-join-data', $encoded);
    }

    public function testDocumentSecurityOffCollectionDeny(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['dsOffSourceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['dsOffDeniedId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        $encoded = (string) json_encode($result['body'] ?? []);
        if ($this->getSide() === 'client') {
            $this->assertContains($result['headers']['status-code'], [400, 401]);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertSame(200, $result['headers']['status-code']);
        }
    }

    public function testSelfJoinDoesNotLeakOtherRow(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['selfJoinId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['selfJoinId'], 'tag', 'tag', '=', 'peer')->toString(),
                Query::select(['payload', 'code', 'peer.payload', 'peer.code'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $encoded = (string) json_encode($result['body'] ?? []);
        if ($this->getSide() === 'client') {
            $rows = $result['body'][$this->getRecordResource()];
            $this->assertNotEmpty($rows);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
            foreach ($rows as $row) {
                $payload = $row['peer.payload'] ?? $row['payload'] ?? null;
                $code = $row['peer.code'] ?? $row['code'] ?? null;
                $this->assertNotSame('classified-join-data', $payload);
                $this->assertNotSame('classified-join-data', $code);
            }
        }
    }

    public function testGetFullOuterJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $amount = $result['body']['rev.amount'] ?? $result['body']['amount'] ?? null;
        $encoded = (string) json_encode($result['body'] ?? []);

        if ($this->getSide() === 'client') {
            $this->assertTrue(
                $amount === null || $amount === '' || (int) $amount === 100,
                'unmatched or unauthorized FOJ amount must be nullish or the authorized 100'
            );
            $this->assertNotSame(9999, is_numeric($amount) ? (int) $amount : $amount);
            $this->assertNotSame(8888, is_numeric($amount) ? (int) $amount : $amount);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertTrue($amount === null || $amount === '' || in_array((int) $amount, [100, 9999], true));
        }
    }

    public function testListInnerJoinDoesNotIncludeSecretOrder(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = [];
        foreach ($rows as $row) {
            $amount = $row['rev.amount'] ?? $row['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = (string) json_encode($result['body'] ?? []);
        if ($this->getSide() === 'client') {
            $this->assertSame([100], array_values(array_unique($amounts)));
            $this->assertNotContains(9999, $amounts);
            $this->assertStringNotContainsString('9999', $encoded);
            $this->assertStringNotContainsString('8888', $encoded);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertContains(100, $amounts);
            $this->assertContains(9999, $amounts);
        }
    }

    public function testLimitedApiKeyCannotJoinOutOfScopeTable(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();
        $secret = $this->getNewKey([
            'databases.read',
            'tables.read',
            'collections.read',
        ]);

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $secret,
        ], [
            'queries' => [
                Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        $this->assertContains($result['headers']['status-code'], [400, 401, 403]);
        $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body'] ?? []));
    }
}
