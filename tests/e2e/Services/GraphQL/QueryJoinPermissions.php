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

        $userOrders = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpUserOrders' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $userOrders['headers']['status-code']);
        $userOrdersId = $userOrders['body']['$id'];

        $profiles = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpProfiles' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $profiles['headers']['status-code']);
        $profilesId = $profiles['body']['$id'];

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
        foreach ([$ordersId, $userOrdersId] as $orderContainerId) {
            $this->createJoinAttribute($databaseId, $orderContainerId, 'string', [
                'key' => 'customerId',
                'size' => 36,
                'required' => false,
            ]);
            $this->createJoinAttribute($databaseId, $orderContainerId, 'integer', [
                'key' => 'amount',
                'required' => false,
            ]);
        }
        $this->createJoinAttribute($databaseId, $profilesId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $profilesId, 'string', [
            'key' => 'tier',
            'size' => 32,
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
        foreach ([$ordersId, $userOrdersId] as $orderContainerId) {
            $this->waitForJoinAttribute($databaseId, $orderContainerId, 'customerId');
            $this->waitForJoinAttribute($databaseId, $orderContainerId, 'amount');
        }
        $this->waitForJoinAttribute($databaseId, $profilesId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $profilesId, 'tier');
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

        $dora = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $customersId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => ['name' => 'Dora'],
            'permissions' => [],
        ]);
        $this->assertSame(201, $dora['headers']['status-code']);
        $doraId = $dora['body']['$id'];

        foreach ([$ordersId, $userOrdersId] as $orderContainerId) {
            foreach ([
                [['customerId' => $aliceId, 'amount' => 100], Permission::read(Role::user($userId))],
                [['customerId' => $aliceId, 'amount' => 9999], Permission::read(Role::user('other-join-perm-user'))],
                [['amount' => 8888], Permission::read(Role::user('other-join-perm-user'))],
            ] as [$order, $permission]) {
                $created = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $orderContainerId), $serverHeaders, [
                    $this->joinRecordIdParam() => ID::unique(),
                    'data' => $order,
                    'permissions' => [$permission],
                ]);
                $this->assertSame(201, $created['headers']['status-code']);
            }
        }

        $profile = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $profilesId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'tier' => 'gold',
            ],
            'permissions' => [],
        ]);
        $this->assertSame(201, $profile['headers']['status-code']);

        $privateRow = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $privateId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'secret' => 'classified-join-data',
            ],
        ]);
        $this->assertSame(201, $privateRow['headers']['status-code']);

        $ownedRow = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $privateId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'secret' => 'owned-join-data',
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $ownedRow['headers']['status-code']);

        $selfJoin = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jpSelfJoin' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
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
            'userOrdersId' => $userOrdersId,
            'profilesId' => $profilesId,
            'privateId' => $privateId,
            'aliceId' => $aliceId,
            'doraId' => $doraId,
            'selfJoinId' => $selfJoinId,
            'dsOffSourceId' => $dsOffSourceId,
            'dsOffJoinedId' => $dsOffJoinedId,
            'dsOffDeniedId' => $dsOffDeniedId,
            'dsOffRowId' => $dsOffRowId,
        ];

        return self::$joinPermissionsCache[$cacheKey];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<mixed> Each row's joined value, without the rows the join left without one.
     */
    protected function joinPermissionValues(array $rows, string $alias, string $attribute): array
    {
        $values = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $value = $decoded[$alias . '.' . $attribute] ?? $decoded[$attribute] ?? null;
            if ($value !== null && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    protected function joinPermissionSorted(array $values): array
    {
        \sort($values);

        return \array_values($values);
    }

    protected function joinPermissionTotal(array $result): int
    {
        return (int) ($result['body']['data'][$this->joinListField()]['total'] ?? -1);
    }

    public function testJoinKeepsMainRowsReadableThroughTheCollection(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $plain = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::select(['name'])->toString(),
        ]));
        $joined = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['profilesId'], '$id', 'customerId', '=', 'prof')->toString(),
            Query::select(['name', 'prof.tier'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $plain['body']);
        $this->assertArrayNotHasKey('errors', $joined['body']);
        $plainRows = $this->joinListRecords($plain);
        $joinedRows = $this->joinListRecords($joined);
        $plainIds = \array_column($plainRows, '_id');

        $this->assertContains($data['doraId'], $plainIds, 'a row without document permissions is readable through the collection grant');
        $this->assertSame($this->joinPermissionSorted($plainIds), $this->joinPermissionSorted(\array_column($joinedRows, '_id')), 'a left join adds columns, it must not change which customers are listed');
        $this->assertSame($this->joinPermissionTotal($plain), $this->joinPermissionTotal($joined));
        $this->assertSame(\count($plainRows), $this->joinPermissionTotal($joined));
        $this->assertSame(['gold'], $this->joinPermissionValues($joinedRows, 'prof', 'tier'), 'the joined profile has no document permissions either and is readable through its collection grant');
    }

    public function testListJoinPerUserTableReturnsOnlyRowsTheCallerCanRead(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]));
        $direct = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['privateId'], [
            Query::equal('customerId', [$data['aliceId']])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body'], 'a document-security collection without collection-level read is listable, so it is joinable');
        $this->assertArrayNotHasKey('errors', $direct['body']);
        $secrets = $this->joinPermissionValues($this->joinListRecords($result), 'rev', 'secret');
        $directSecrets = \array_column(\array_map($this->decodeJoinData(...), $this->joinListRecords($direct)), 'secret');

        $this->assertSame($this->joinPermissionSorted($directSecrets), $this->joinPermissionSorted($secrets), 'the join returns exactly the rows listing the collection returns');

        if ($this->getSide() === 'client') {
            $this->assertSame(['owned-join-data'], $secrets);
            $this->assertStringNotContainsString('classified-join-data', $this->joinEncodedBody($result));
        } else {
            $this->assertSame(['classified-join-data', 'owned-join-data'], $this->joinPermissionSorted($secrets));
        }
    }

    public function testGetJoinPerUserTableReturnsOnlyTheRowTheCallerCanRead(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $record = $this->joinGetRecord($result);
        $this->assertSame($data['aliceId'], $record['_id']);
        $decoded = $this->decodeJoinData($record);
        $secret = $decoded['rev.secret'] ?? $decoded['secret'] ?? null;

        if ($this->getSide() === 'client') {
            $this->assertSame('owned-join-data', $secret);
            $this->assertStringNotContainsString('classified-join-data', $this->joinEncodedBody($result));
        } else {
            $this->assertContains($secret, ['classified-join-data', 'owned-join-data']);
        }
    }

    public function testListFullOuterJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            Query::leftJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            Query::leftJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            Query::rightJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            Query::rightJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            Query::crossJoin($data['userOrdersId'], 'rev')->toString(),
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
        $queries = fn (string $joinedId): array => [
            Query::join($joinedId, '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ];

        $readable = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['dsOffSourceId'], $queries($data['dsOffJoinedId'])));
        $this->assertArrayNotHasKey('errors', $readable['body'], 'the same join to a collection the caller can list must succeed');
        $this->assertContains('classified-join-data', $this->joinPermissionValues($this->joinListRecords($readable), 'rev', 'secret'));

        $listed = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['dsOffSourceId'], $queries($data['dsOffDeniedId'])));
        $got = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['dsOffSourceId'], $data['dsOffRowId'], $queries($data['dsOffDeniedId'])));

        if ($this->getSide() === 'client') {
            foreach ([$listed, $got] as $result) {
                $this->assertArrayHasKey('errors', $result['body']);
                $this->assertSame('The current user is not authorized to perform the requested action.', $result['body']['errors'][0]['message'] ?? null, 'a collection without collection-level read and without document security cannot be joined');
                $this->assertStringNotContainsString('classified-join-data', $this->joinEncodedBody($result));
            }
        } else {
            $this->assertArrayNotHasKey('errors', $listed['body']);
            $this->assertContains('classified-join-data', $this->joinPermissionValues($this->joinListRecords($listed), 'rev', 'secret'));
            $this->assertArrayNotHasKey('errors', $got['body']);
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
        $rows = $this->joinListRecords($result);
        $this->assertNotEmpty($rows);
        $pairs = \array_map(function (array $row): array {
            $decoded = $this->decodeJoinData($row);

            return [$decoded['payload'] ?? null, $decoded['peer.payload'] ?? null];
        }, $rows);

        if ($this->getSide() === 'client') {
            $this->assertSame([['open-payload', 'open-payload']], $pairs, 'the only pair the caller can read is the open row with itself');
            $this->assertStringNotContainsString('classified-join-data', $this->joinEncodedBody($result));
        } else {
            $this->assertContains(['open-payload', 'classified-join-data'], $pairs, 'the self join pairs different rows');
            $this->assertContains(['classified-join-data', 'open-payload'], $pairs);
            $this->assertCount(4, $pairs, 'two rows sharing a tag pair with each other and with themselves');
        }
    }

    public function testGetFullOuterJoinOmitsUnauthorizedAmounts(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::fullOuterJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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

    public function testListInnerJoinShowsEveryOrderListingOrdersShows(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $joined = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]));
        $direct = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['ordersId'], [
            Query::equal('customerId', [$data['aliceId']])->toString(),
        ]));

        $this->assertArrayNotHasKey('errors', $joined['body']);
        $this->assertArrayNotHasKey('errors', $direct['body']);
        $rows = $this->joinListRecords($joined);
        $amounts = \array_map('intval', $this->joinPermissionValues($rows, 'rev', 'amount'));
        $directAmounts = \array_map('intval', \array_column(\array_map($this->decodeJoinData(...), $this->joinListRecords($direct)), 'amount'));

        $this->assertSame([100, 9999], $this->joinPermissionSorted($directAmounts), 'the collection grants read, so listing it shows every order whatever its document permissions');
        $this->assertSame($this->joinPermissionSorted($directAmounts), $this->joinPermissionSorted($amounts), 'the join shows exactly the orders listing the collection shows');
        $this->assertSame(\count($rows), $this->joinPermissionTotal($joined));
    }

    public function testApiKeyWithDocumentScopesJoinsAnyTableOfTheProject(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();
        $variables = $this->joinListVariables($data['databaseId'], $data['customersId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]);

        $reader = $this->getNewKey(['databases.read', 'tables.read', 'collections.read', 'documents.read', 'rows.read']);
        $joined = $this->graphqlJoinWithKey($this->joinListQuery(), $variables, $reader);

        $this->assertArrayNotHasKey('errors', $joined['body'], 'an API key is privileged across its project, so it joins a table no user may read');
        $this->assertSame(
            ['classified-join-data', 'owned-join-data'],
            $this->joinPermissionSorted($this->joinPermissionValues($this->joinListRecords($joined), 'rev', 'secret')),
        );

        $unscoped = $this->getNewKey(['databases.read', 'tables.read', 'collections.read']);
        $refused = $this->graphqlJoinWithKey($this->joinListQuery(), $variables, $unscoped);

        $this->assertArrayHasKey('errors', $refused['body']);
        $this->assertStringContainsString('missing scopes', (string) ($refused['body']['errors'][0]['message'] ?? ''), 'without the read scope the endpoint refuses the key before it reads the query');
        $this->assertStringNotContainsString('classified-join-data', $this->joinEncodedBody($refused));
    }

}
