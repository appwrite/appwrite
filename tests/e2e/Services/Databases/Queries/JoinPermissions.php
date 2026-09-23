<?php

namespace Tests\E2E\Services\Databases\Queries;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

/**
 * A joined collection is read exactly as listing it directly reads it (contract C6): with its collection-level
 * read every row, otherwise with document security the rows the caller holds document-level read on, otherwise
 * not at all (401). Adding a join never hides a main row the caller can read.
 */
trait JoinPermissions
{
    private static array $joinPermissionsCache = [];
    private static array $joinCursorCache = [];

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

        $userOrders = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpUserOrders' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $userOrders['headers']['status-code']);
        $userOrdersId = $userOrders['body']['$id'];

        $profiles = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpProfiles' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $profiles['headers']['status-code']);
        $profilesId = $profiles['body']['$id'];

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
        foreach ([$ordersId, $userOrdersId] as $orderContainerId) {
            $this->createAttribute($databaseId, $orderContainerId, 'string', [
                'key' => 'customerId',
                'size' => 36,
                'required' => false,
            ]);
            $this->createAttribute($databaseId, $orderContainerId, 'integer', [
                'key' => 'amount',
                'required' => false,
            ]);
        }
        $this->createAttribute($databaseId, $profilesId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $profilesId, 'string', [
            'key' => 'tier',
            'size' => 32,
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
        foreach ([$ordersId, $userOrdersId] as $orderContainerId) {
            $this->waitForAttribute($databaseId, $orderContainerId, 'customerId');
            $this->waitForAttribute($databaseId, $orderContainerId, 'amount');
        }
        $this->waitForAttribute($databaseId, $profilesId, 'customerId');
        $this->waitForAttribute($databaseId, $profilesId, 'tier');
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

        $dora = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $customersId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
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
                $created = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $orderContainerId), $serverHeaders, [
                    $this->getRecordIdParam() => ID::unique(),
                    'data' => $order,
                    'permissions' => [$permission],
                ]);
                $this->assertSame(201, $created['headers']['status-code']);
            }
        }

        $profile = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $profilesId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'tier' => 'gold',
            ],
            'permissions' => [],
        ]);
        $this->assertSame(201, $profile['headers']['status-code']);

        $privateRow = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $privateId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'secret' => 'classified-join-data',
            ],
        ]);
        $this->assertSame(201, $privateRow['headers']['status-code']);

        $ownedRow = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $privateId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'secret' => 'owned-join-data',
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $ownedRow['headers']['status-code']);

        $selfJoin = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpSelfJoin' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
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
            'userOrdersId' => $userOrdersId,
            'profilesId' => $profilesId,
            'privateId' => $privateId,
            'aliceId' => $aliceId,
            'carolId' => $carolId,
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
     * Customers that each have one order the client user can read and one it cannot. The unreadable order is
     * created first, so a lookup that ignored permissions would find it first, and its amount would move the
     * customer's place in a list ordered by amount.
     */
    protected function setupJoinCursorFixture(): array
    {
        $cacheKey = $this->getCacheKey();
        if (!empty(self::$joinCursorCache[$cacheKey])) {
            return self::$joinCursorCache[$cacheKey];
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $userId = $this->getUser()['$id'];
        $suffix = ID::unique();
        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $customers = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpCursorCustomers' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertSame(201, $customers['headers']['status-code']);
        $customersId = $customers['body']['$id'];

        $orders = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jpCursorOrders' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [Permission::create(Role::any())],
        ]);
        $this->assertSame(201, $orders['headers']['status-code']);
        $ordersId = $orders['body']['$id'];

        $this->createAttribute($databaseId, $customersId, 'string', ['key' => 'name', 'size' => 64, 'required' => true]);
        $this->createAttribute($databaseId, $ordersId, 'string', ['key' => 'customerId', 'size' => 36, 'required' => true]);
        $this->createAttribute($databaseId, $ordersId, 'integer', ['key' => 'amount', 'required' => true]);
        $this->waitForAttribute($databaseId, $customersId, 'name');
        $this->waitForAttribute($databaseId, $ordersId, 'customerId');
        $this->waitForAttribute($databaseId, $ordersId, 'amount');

        $customerIds = [];
        foreach (['First' => [10, 35], 'Second' => [20, 5], 'Third' => [30, 1], 'Fourth' => [40, 99]] as $name => [$readable, $unreadable]) {
            $customer = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $customersId), $serverHeaders, [
                $this->getRecordIdParam() => ID::unique(),
                'data' => ['name' => $name],
                'permissions' => [Permission::read(Role::any())],
            ]);
            $this->assertSame(201, $customer['headers']['status-code']);
            $customerIds[] = $customer['body']['$id'];

            foreach ([[$unreadable, 'other-join-cursor-user'], [$readable, $userId]] as [$amount, $reader]) {
                $order = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $ordersId), $serverHeaders, [
                    $this->getRecordIdParam() => ID::unique(),
                    'data' => ['customerId' => $customer['body']['$id'], 'amount' => $amount],
                    'permissions' => [Permission::read(Role::user($reader))],
                ]);
                $this->assertSame(201, $order['headers']['status-code']);
            }
        }

        self::$joinCursorCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'customersId' => $customersId,
            'ordersId' => $ordersId,
            'customerIds' => $customerIds,
        ];

        return self::$joinCursorCache[$cacheKey];
    }

    /**
     * @param list<string> $queries
     * @param array<string, string>|null $headers
     * @return array<string, mixed>
     */
    protected function joinPermissionList(string $databaseId, string $containerId, array $queries, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId), $headers ?? array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<mixed> Each row's joined value, without the rows the join left without one.
     */
    protected function joinPermissionValues(array $rows, string $alias, string $attribute): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $row[$alias . '.' . $attribute] ?? $row[$attribute] ?? null;
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

    public function testJoinKeepsMainRowsReadableThroughTheCollection(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $plain = $this->joinPermissionList($data['databaseId'], $data['customersId'], [
            Query::select(['name'])->toString(),
        ]);
        $joined = $this->joinPermissionList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['profilesId'], '$id', 'customerId', '=', 'prof')->toString(),
            Query::select(['name', 'prof.tier'])->toString(),
        ]);

        $this->assertSame(200, $plain['headers']['status-code']);
        $this->assertSame(200, $joined['headers']['status-code']);
        $plainRows = $plain['body'][$this->getRecordResource()];
        $joinedRows = $joined['body'][$this->getRecordResource()];
        $plainIds = \array_column($plainRows, '$id');

        $this->assertContains($data['doraId'], $plainIds, 'a row without document permissions is readable through the collection grant');
        $this->assertSame($this->joinPermissionSorted($plainIds), $this->joinPermissionSorted(\array_column($joinedRows, '$id')), 'a left join adds columns, it must not change which customers are listed');
        $this->assertSame($plain['body']['total'], $joined['body']['total']);
        $this->assertSame(\count($plainRows), $joined['body']['total']);
        $this->assertSame(['gold'], $this->joinPermissionValues($joinedRows, 'prof', 'tier'), 'the joined profile has no document permissions either and is readable through its collection grant');
    }

    public function testListJoinPerUserTableReturnsOnlyRowsTheCallerCanRead(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $result = $this->joinPermissionList($data['databaseId'], $data['customersId'], [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ]);
        $direct = $this->joinPermissionList($data['databaseId'], $data['privateId'], [
            Query::equal('customerId', [$data['aliceId']])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code'], 'a document-security collection without collection-level read is listable, so it is joinable');
        $this->assertSame(200, $direct['headers']['status-code']);
        $secrets = $this->joinPermissionValues($result['body'][$this->getRecordResource()], 'rev', 'secret');
        $directSecrets = \array_column($direct['body'][$this->getRecordResource()], 'secret');

        $this->assertSame($this->joinPermissionSorted($directSecrets), $this->joinPermissionSorted($secrets), 'the join returns exactly the rows listing the collection returns');

        if ($this->getSide() === 'client') {
            $this->assertSame(['owned-join-data'], $secrets);
            $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body']));
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

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $secret = $result['body']['rev.secret'] ?? $result['body']['secret'] ?? null;

        if ($this->getSide() === 'client') {
            $this->assertSame('owned-join-data', $secret);
            $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body']));
        } else {
            $this->assertContains($secret, ['classified-join-data', 'owned-join-data']);
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
                Query::fullOuterJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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
                Query::leftJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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
                Query::leftJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $amount = $result['body']['rev.amount'] ?? $result['body']['amount'] ?? null;
        $encoded = (string) json_encode($result['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(100, (int) $amount);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
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
                Query::rightJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $amount = $result['body']['rev.amount'] ?? $result['body']['amount'] ?? null;
        $encoded = (string) json_encode($result['body'] ?? []);

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

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::crossJoin($data['userOrdersId'], 'rev')->toString(),
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
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $queries = fn (string $joinedId): array => [
            'queries' => [
                Query::join($joinedId, '$id', 'customerId', '=', 'rev')->toString(),
                Query::select(['name', 'rev.secret'])->toString(),
            ],
        ];

        $readable = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['dsOffSourceId']), $headers, $queries($data['dsOffJoinedId']));
        $this->assertSame(200, $readable['headers']['status-code'], 'the same join to a collection the caller can list must succeed');
        $this->assertContains('classified-join-data', $this->joinPermissionValues($readable['body'][$this->getRecordResource()], 'rev', 'secret'));

        $listed = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['dsOffSourceId']), $headers, $queries($data['dsOffDeniedId']));
        $got = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['dsOffSourceId'], $data['dsOffRowId']), $headers, $queries($data['dsOffDeniedId']));

        if ($this->getSide() === 'client') {
            foreach ([$listed, $got] as $result) {
                $this->assertSame(401, $result['headers']['status-code']);
                $this->assertSame(Exception::USER_UNAUTHORIZED, $result['body']['type'], 'a collection without collection-level read and without document security cannot be joined');
                $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body']));
            }
        } else {
            $this->assertSame(200, $listed['headers']['status-code']);
            $this->assertContains('classified-join-data', $this->joinPermissionValues($listed['body'][$this->getRecordResource()], 'rev', 'secret'));
            $this->assertSame(200, $got['headers']['status-code']);
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
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertNotEmpty($rows);
        $pairs = \array_map(static fn (array $row): array => [$row['payload'] ?? null, $row['peer.payload'] ?? null], $rows);

        if ($this->getSide() === 'client') {
            $this->assertSame([['open-payload', 'open-payload']], $pairs, 'the only pair the caller can read is the open row with itself');
            $this->assertStringNotContainsString('classified-join-data', (string) json_encode($result['body'] ?? []));
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

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::fullOuterJoin($data['userOrdersId'], '$id', 'customerId', '=', 'rev')->toString(),
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
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 9999));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8888));
            $this->assertStringNotContainsString('classified-join-data', $encoded);
        } else {
            $this->assertTrue($amount === null || $amount === '' || in_array((int) $amount, [100, 9999], true));
        }
    }

    public function testListInnerJoinShowsEveryOrderListingOrdersShows(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();

        $joined = $this->joinPermissionList($data['databaseId'], $data['customersId'], [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.amount'])->toString(),
        ]);
        $direct = $this->joinPermissionList($data['databaseId'], $data['ordersId'], [
            Query::equal('customerId', [$data['aliceId']])->toString(),
        ]);

        $this->assertSame(200, $joined['headers']['status-code']);
        $this->assertSame(200, $direct['headers']['status-code']);
        $rows = $joined['body'][$this->getRecordResource()];
        $amounts = \array_map('intval', $this->joinPermissionValues($rows, 'rev', 'amount'));
        $directAmounts = \array_map('intval', \array_column($direct['body'][$this->getRecordResource()], 'amount'));

        $this->assertSame([100, 9999], $this->joinPermissionSorted($directAmounts), 'the collection grants read, so listing it shows every order whatever its document permissions');
        $this->assertSame($this->joinPermissionSorted($directAmounts), $this->joinPermissionSorted($amounts), 'the join shows exactly the orders listing the collection shows');
        $this->assertSame(\count($rows), $joined['body']['total']);
    }

    public function testApiKeyWithDocumentScopesJoinsAnyTableOfTheProject(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinPermissionsFixture();
        $queries = [
            Query::join($data['privateId'], '$id', 'customerId', '=', 'rev')->toString(),
            Query::select(['name', 'rev.secret'])->toString(),
        ];
        $headers = fn (string $key): array => [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $key,
        ];

        $reader = $this->getNewKey(['databases.read', 'tables.read', 'collections.read', 'documents.read', 'rows.read']);
        $joined = $this->joinPermissionList($data['databaseId'], $data['customersId'], $queries, $headers($reader));

        $this->assertSame(200, $joined['headers']['status-code'], 'an API key is privileged across its project, so it joins a table no user may read');
        $this->assertSame(
            ['classified-join-data', 'owned-join-data'],
            $this->joinPermissionSorted($this->joinPermissionValues($joined['body'][$this->getRecordResource()], 'rev', 'secret')),
        );

        $unscoped = $this->getNewKey(['databases.read', 'tables.read', 'collections.read']);
        $refused = $this->joinPermissionList($data['databaseId'], $data['customersId'], $queries, $headers($unscoped));

        $this->assertSame(401, $refused['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_UNAUTHORIZED_SCOPE, $refused['body']['type'], 'without the read scope the endpoint refuses the key before it reads the query');
        $this->assertStringNotContainsString('classified-join-data', (string) json_encode($refused['body']));
    }

    public function testJoinedListPagesPastJoinedRowsTheCallerCannotRead(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinCursorFixture();
        $base = [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::orderAsc('ord.amount')->toString(),
            Query::select(['name', 'ord.amount'])->toString(),
        ];
        $row = fn (array $document): array => [$document['$id'], (int) $document['ord.amount']];

        $unpaged = $this->joinPermissionList($data['databaseId'], $data['customersId'], [...$base, Query::limit(100)->toString()]);
        $this->assertSame(200, $unpaged['headers']['status-code']);
        $expected = \array_map($row, $unpaged['body'][$this->getRecordResource()]);

        if ($this->getSide() !== 'client') {
            $this->assertSame([1, 5, 10, 20, 30, 35, 40, 99], \array_column($expected, 1), 'a privileged caller reads every order');

            return;
        }

        $this->assertSame([10, 20, 30, 40], \array_column($expected, 1), 'the caller reads one order per customer');
        $this->assertSame($data['customerIds'], \array_column($expected, 0));

        $forward = [];
        $cursor = null;
        for ($page = 0; $page <= \count($expected); $page++) {
            $queries = [...$base, Query::limit(1)->toString()];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter(new Document(['$id' => $cursor]))->toString();
            }
            $result = $this->joinPermissionList($data['databaseId'], $data['customersId'], $queries);
            $this->assertSame(200, $result['headers']['status-code']);
            $rows = $result['body'][$this->getRecordResource()];
            if ($rows === []) {
                break;
            }
            $forward[] = $row($rows[0]);
            $cursor = $rows[0]['$id'];
        }
        $this->assertSame($expected, $forward, 'paging forward returns every readable row once, in order');

        $backward = [];
        $cursor = $expected[\count($expected) - 1][0];
        for ($page = 0; $page < \count($expected); $page++) {
            $result = $this->joinPermissionList($data['databaseId'], $data['customersId'], [
                ...$base,
                Query::limit(1)->toString(),
                Query::cursorBefore(new Document(['$id' => $cursor]))->toString(),
            ]);
            $this->assertSame(200, $result['headers']['status-code']);
            $rows = $result['body'][$this->getRecordResource()];
            if ($rows === []) {
                break;
            }
            \array_unshift($backward, $row($rows[0]));
            $cursor = $rows[0]['$id'];
        }
        $this->assertSame(\array_slice($expected, 0, -1), $backward, 'paging back from the last row returns every earlier row once, in order');
    }
}
