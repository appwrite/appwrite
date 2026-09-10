<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait QueryJoinPermissions
{
    private static array $joinPermissionsCache = [];

    protected function isTablesDB(): bool
    {
        return \str_contains(static::class, '\\TablesDB\\');
    }

    protected function joinApiBase(): string
    {
        return $this->isTablesDB() ? '/tablesdb' : '/databases';
    }

    protected function joinContainerUrl(string $databaseId, string $containerId = ''): string
    {
        $resource = $this->isTablesDB() ? 'tables' : 'collections';
        $base = $this->joinApiBase() . '/' . $databaseId . '/' . $resource;

        return $containerId !== '' ? $base . '/' . $containerId : $base;
    }

    protected function joinSchemaUrl(string $databaseId, string $containerId, string $type = ''): string
    {
        $resource = $this->isTablesDB() ? 'columns' : 'attributes';
        $base = $this->joinContainerUrl($databaseId, $containerId) . '/' . $resource;

        return $type !== '' ? $base . '/' . $type : $base;
    }

    protected function joinRecordUrl(string $databaseId, string $containerId, string $recordId = ''): string
    {
        $resource = $this->isTablesDB() ? 'rows' : 'documents';
        $base = $this->joinContainerUrl($databaseId, $containerId) . '/' . $resource;

        return $recordId !== '' ? $base . '/' . $recordId : $base;
    }

    protected function joinSecurityParam(): string
    {
        return $this->isTablesDB() ? 'rowSecurity' : 'documentSecurity';
    }

    protected function joinContainerIdParam(): string
    {
        return $this->isTablesDB() ? 'tableId' : 'collectionId';
    }

    protected function joinRecordIdParam(): string
    {
        return $this->isTablesDB() ? 'rowId' : 'documentId';
    }

    protected function joinServerHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    protected function createJoinAttribute(string $databaseId, string $containerId, string $type, array $payload): array
    {
        return $this->client->call(
            Client::METHOD_POST,
            $this->joinSchemaUrl($databaseId, $containerId, $type),
            $this->joinServerHeaders(),
            $payload
        );
    }

    protected function waitForJoinAttribute(string $databaseId, string $containerId, string $key): void
    {
        $this->assertEventually(function () use ($databaseId, $containerId, $key) {
            $attribute = $this->client->call(
                Client::METHOD_GET,
                $this->joinSchemaUrl($databaseId, $containerId) . '/' . $key,
                $this->joinServerHeaders()
            );

            $this->assertSame(200, $attribute['headers']['status-code']);
            $this->assertSame('available', $attribute['body']['status'] ?? '');
        }, 360000, 500);
    }

    protected function graphqlJoin(string $query, array $variables): array
    {
        return $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'query' => $query,
            'variables' => $variables,
        ]);
    }

    protected function graphqlJoinWithKey(string $query, array $variables, string $key): array
    {
        return $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $key,
        ], [
            'query' => $query,
            'variables' => $variables,
        ]);
    }

    protected function joinListQuery(): string
    {
        return $this->getQuery($this->isTablesDB() ? self::GET_ROWS : self::GET_DOCUMENTS);
    }

    protected function joinGetQuery(): string
    {
        return $this->getQuery($this->isTablesDB() ? self::GET_ROW : self::GET_DOCUMENT);
    }

    protected function joinListField(): string
    {
        return $this->isTablesDB() ? 'tablesDBListRows' : 'databasesListDocuments';
    }

    protected function joinGetField(): string
    {
        return $this->isTablesDB() ? 'tablesDBGetRow' : 'databasesGetDocument';
    }

    protected function joinItemsKey(): string
    {
        return $this->isTablesDB() ? 'rows' : 'documents';
    }

    protected function joinListVariables(string $databaseId, string $containerId, array $queries): array
    {
        return [
            'databaseId' => $databaseId,
            $this->joinContainerIdParam() => $containerId,
            'queries' => $queries,
        ];
    }

    protected function joinGetVariables(string $databaseId, string $containerId, string $recordId, array $queries): array
    {
        return [
            'databaseId' => $databaseId,
            $this->joinContainerIdParam() => $containerId,
            $this->joinRecordIdParam() => $recordId,
            'queries' => $queries,
        ];
    }

    protected function joinListRecords(array $result): array
    {
        return $result['body']['data'][$this->joinListField()][$this->joinItemsKey()] ?? [];
    }

    protected function joinGetRecord(array $result): array
    {
        $record = $result['body']['data'][$this->joinGetField()] ?? [];

        return \is_array($record) ? $record : [];
    }

    protected function decodeJoinData(array $record): array
    {
        if (!isset($record['data']) || !\is_string($record['data'])) {
            return [];
        }

        $decoded = \json_decode($record['data'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    protected function joinEncodedBody(array $result): string
    {
        return (string) \json_encode($result['body']);
    }

    protected function setupJoinPermissionsFixture(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$joinPermissionsCache[$cacheKey])) {
            return self::$joinPermissionsCache[$cacheKey];
        }

        $userId = $this->getUser()['$id'];
        $suffix = ID::unique();
        $serverHeaders = $this->joinServerHeaders();

        $database = $this->client->call(Client::METHOD_POST, $this->joinApiBase(), $serverHeaders, [
            'databaseId' => ID::unique(),
            'name' => 'jpGraphQL' . $suffix,
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $customers = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpCustomers' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $customers['headers']['status-code']);
        $customersId = $customers['body']['$id'];

        $orders = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpOrders' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $orders['headers']['status-code']);
        $ordersId = $orders['body']['$id'];

        $private = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpPrivate' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [],
        ]);
        $this->assertSame(201, $private['headers']['status-code']);
        $privateId = $private['body']['$id'];

        $this->createJoinAttribute($databaseId, $customersId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $ordersId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $ordersId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $privateId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $privateId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);

        $this->waitForJoinAttribute($databaseId, $customersId, 'name');
        $this->waitForJoinAttribute($databaseId, $ordersId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $ordersId, 'amount');
        $this->waitForJoinAttribute($databaseId, $privateId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $privateId, 'secret');

        $alice = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $customersId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Alice'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $alice['headers']['status-code']);
        $aliceId = $alice['body']['$id'];

        $carol = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $customersId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Carol'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $carol['headers']['status-code']);

        $publicOrder = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $ordersId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 100,
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $publicOrder['headers']['status-code']);

        $secretOrder = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $ordersId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 9999,
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $secretOrder['headers']['status-code']);

        $orphanOrder = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $ordersId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'amount' => 8888,
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $orphanOrder['headers']['status-code']);

        $privateRow = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $privateId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'secret' => 'classified-join-data',
            ],
        ]);
        $this->assertSame(201, $privateRow['headers']['status-code']);

        $selfJoin = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpSelfJoin' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $selfJoin['headers']['status-code']);
        $selfJoinId = $selfJoin['body']['$id'];

        $dsOffSource = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpDsOffSource' . $suffix,
            $this->joinSecurityParam() => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $dsOffSource['headers']['status-code']);
        $dsOffSourceId = $dsOffSource['body']['$id'];

        $dsOffJoined = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpDsOffJoined' . $suffix,
            $this->joinSecurityParam() => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $dsOffJoined['headers']['status-code']);
        $dsOffJoinedId = $dsOffJoined['body']['$id'];

        $dsOffDenied = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpDsOffDenied' . $suffix,
            $this->joinSecurityParam() => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $dsOffDenied['headers']['status-code']);
        $dsOffDeniedId = $dsOffDenied['body']['$id'];

        $this->createJoinAttribute($databaseId, $selfJoinId, 'string', [
            'key' => 'payload',
            'size' => 128,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $selfJoinId, 'string', [
            'key' => 'code',
            'size' => 128,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $selfJoinId, 'string', [
            'key' => 'tag',
            'size' => 32,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $dsOffSourceId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $dsOffJoinedId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $dsOffJoinedId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $dsOffDeniedId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $dsOffDeniedId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);

        $this->waitForJoinAttribute($databaseId, $selfJoinId, 'payload');
        $this->waitForJoinAttribute($databaseId, $selfJoinId, 'code');
        $this->waitForJoinAttribute($databaseId, $selfJoinId, 'tag');
        $this->waitForJoinAttribute($databaseId, $dsOffSourceId, 'name');
        $this->waitForJoinAttribute($databaseId, $dsOffJoinedId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $dsOffJoinedId, 'secret');
        $this->waitForJoinAttribute($databaseId, $dsOffDeniedId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $dsOffDeniedId, 'secret');

        $openSelf = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $selfJoinId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
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

        $secretSelf = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $selfJoinId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
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

        $dsOffRow = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $dsOffSourceId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Alice'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertSame(201, $dsOffRow['headers']['status-code']);
        $dsOffRowId = $dsOffRow['body']['$id'];

        $dsOffVisible = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $dsOffJoinedId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $dsOffRowId,
                'secret' => 'classified-join-data',
            ],
            'permissions' => [
                Permission::read(Role::user('other-join-perm-user')),
            ],
        ]);
        $this->assertSame(201, $dsOffVisible['headers']['status-code']);

        $dsOffHidden = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $dsOffDeniedId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
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

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]));

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertArrayHasKey('errors', $result['body']);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertArrayNotHasKey('errors', $result['body']);
        }
    }

    public function testGetJoinWithoutTableReadDenied(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]));

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertArrayHasKey('errors', $result['body']);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertArrayNotHasKey('errors', $result['body']);
        }
    }

    public function testListFullOuterJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertContains(100, $amounts);
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
            $this->assertStringNotContainsString('classified-join-data', $encoded);
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

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertContains(100, $amounts);
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
            $this->assertStringNotContainsString('classified-join-data', $encoded);
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

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $record = $this->joinGetRecord($result);
        $this->assertSame($data['aliceId'], $record['_id']);
        $decoded = $this->decodeJoinData($record);
        $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
        $encoded = $this->joinEncodedBody($result);

        if ($this->getSide() === 'client') {
            $this->assertSame(100, (int) $amount);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
            $this->assertStringNotContainsString('classified-join-data', $encoded);
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

        $result = $this->graphqlJoinWithKey($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]), $this->getProject()['apiKey']);

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $this->assertNotEmpty($rows);
        $secrets = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $secret = $decoded['rev.secret'] ?? $decoded['secret'] ?? null;
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

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::rightJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertContains(100, $amounts);
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::rightJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $record = $this->joinGetRecord($result);
        $this->assertSame($data['aliceId'], $record['_id']);
        $decoded = $this->decodeJoinData($record);
        $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
        $encoded = $this->joinEncodedBody($result);

        if ($this->getSide() === 'client') {
            $this->assertSame(100, (int) $amount);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::crossJoin($data['ordersId'], 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertNotEmpty($amounts);
            foreach ($amounts as $amount) {
                $this->assertSame(100, $amount);
            }
            $this->assertNotContains(9999, $amounts);
            $this->assertNotContains(8888, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['dsOffSourceId'], [
            Query::leftJoin($data['dsOffJoinedId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $this->assertNotEmpty($rows);
        $secrets = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $secret = $decoded['rev.secret'] ?? $decoded['secret'] ?? null;
            if ($secret !== null && $secret !== '') {
                $secrets[] = $secret;
            }
        }
        $encoded = $this->joinEncodedBody($result);
        $this->assertContains('classified-join-data', $secrets);
        $this->assertStringContainsString('classified-join-data', $encoded);
    }

    public function testDocumentSecurityOffCollectionDeny(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['dsOffSourceId'], [
            Query::join($data['dsOffDeniedId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]));

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertArrayHasKey('errors', $result['body']);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertArrayNotHasKey('errors', $result['body']);
        }
    }

    public function testSelfJoinDoesNotLeakOtherRow(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['selfJoinId'], [
            Query::join($data['selfJoinId'], 'tag', 'tag', '=', 'peer')->toString(),
            Query::select(['payload', 'code', 'peer.payload', 'peer.code'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $rows = $this->joinListRecords($result);
            $this->assertNotEmpty($rows);
            $this->assertStringNotContainsString('classified-join-data', $encoded);
            foreach ($rows as $row) {
                $decoded = $this->decodeJoinData($row);
                $payload = $decoded['peer.payload'] ?? $decoded['payload'] ?? null;
                $code = $decoded['peer.code'] ?? $decoded['code'] ?? null;
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

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $record = $this->joinGetRecord($result);
        $this->assertSame($data['aliceId'], $record['_id']);
        $decoded = $this->decodeJoinData($record);
        $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
        $encoded = $this->joinEncodedBody($result);

        if ($this->getSide() === 'client') {
            $this->assertTrue(
                $amount === null || $amount === '' || (int) $amount === 100,
                'unmatched or unauthorized FOJ amount must be nullish or the authorized 100'
            );
            $this->assertNotSame(9999, \is_numeric($amount) ? (int) $amount : $amount);
            $this->assertNotSame(8888, \is_numeric($amount) ? (int) $amount : $amount);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertTrue($amount === null || $amount === '' || \in_array((int) $amount, [100, 9999], true));
        }
    }

    public function testListInnerJoinDoesNotIncludeSecretOrder(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $amount = $decoded['rev.amount'] ?? $decoded['amount'] ?? null;
            if ($amount !== null && $amount !== '') {
                $amounts[] = (int) $amount;
            }
        }

        $encoded = $this->joinEncodedBody($result);
        if ($this->getSide() === 'client') {
            $this->assertSame([100], \array_values(\array_unique($amounts)));
            $this->assertNotContains(9999, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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

        $result = $this->graphqlJoinWithKey($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]), $secret);

        $this->assertStringNotContainsString('classified-join-data', $this->joinEncodedBody($result));
    }
}
