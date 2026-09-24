<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Services\Databases\Queries\Oracle\Assertions;
use Tests\E2E\Services\Databases\Queries\Oracle\Customer;
use Tests\E2E\Services\Databases\Queries\Oracle\Fixture;
use Tests\E2E\Services\Databases\Queries\Oracle\Join;
use Tests\E2E\Services\Databases\Queries\Oracle\Order;
use Tests\E2E\Services\Databases\Queries\Oracle\Seed;
use Tests\E2E\Services\Databases\Queries\Oracle\Statistic;
use Tests\E2E\Services\Databases\Queries\Oracle\Summary;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait QueryJoinCombos
{
    use Assertions;

    private static array $joinComboCache = [];

    private static array $joinHardcoreCache = [];

    protected function setupJoinComboFixture(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$joinComboCache[$cacheKey])) {
            return self::$joinComboCache[$cacheKey];
        }

        $userId = $this->getUser()['$id'];
        $suffix = ID::unique();
        $serverHeaders = $this->joinServerHeaders();

        $database = $this->client->call(Client::METHOD_POST, $this->joinApiBase(), $serverHeaders, [
            'databaseId' => ID::unique(),
            'name' => 'jcGraphQL' . $suffix,
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $customers = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jcCustomers' . $suffix,
            $this->joinSecurityParam() => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $customers['headers']['status-code']);
        $customersId = $customers['body']['$id'];

        $public = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jcPublic' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $public['headers']['status-code']);
        $publicId = $public['body']['$id'];

        $secret = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $serverHeaders, [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => 'jcSecret' . $suffix,
            $this->joinSecurityParam() => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $secret['headers']['status-code']);
        $secretId = $secret['body']['$id'];

        $this->createJoinAttribute($databaseId, $customersId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $publicId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $publicId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);

        $this->waitForJoinAttribute($databaseId, $customersId, 'name');
        $this->waitForJoinAttribute($databaseId, $publicId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $publicId, 'amount');
        $this->waitForJoinAttribute($databaseId, $secretId, 'customerId');
        $this->waitForJoinAttribute($databaseId, $secretId, 'amount');
        $this->waitForJoinAttribute($databaseId, $secretId, 'secret');

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
        $carolId = $carol['body']['$id'];

        $publicRow = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $publicId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 313,
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $publicRow['headers']['status-code']);

        $secretRow = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $secretId), $serverHeaders, [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 777,
                'secret' => 'combo-secret-alpha',
            ],
            'permissions' => [
                Permission::read(Role::user('combo-hidden')),
            ],
        ]);
        $this->assertSame(201, $secretRow['headers']['status-code']);

        self::$joinComboCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'customersId' => $customersId,
            'publicId' => $publicId,
            'secretId' => $secretId,
            'aliceId' => $aliceId,
            'carolId' => $carolId,
        ];

        return self::$joinComboCache[$cacheKey];
    }

    /**
     * @return list<string>
     */
    protected function joinComboLeftAndInnerQueries(array $data, array $extra = []): array
    {
        return [
            Query::leftJoin($data['secretId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::join($data['publicId'], '$id', 'customerId', '=', 'pub')->toString(),
            ...$extra,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<int>
     */
    protected function joinComboAmounts(array $rows): array
    {
        $amounts = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            foreach ($decoded as $key => $value) {
                if (($key === 'amount' || \str_ends_with((string) $key, '.amount')) && $value !== null && $value !== '') {
                    $amounts[] = (int) $value;
                }
            }
        }

        return $amounts;
    }

    protected function encodedJsonContainsScalar(string $encoded, int $needle): bool
    {
        $decoded = \json_decode($encoded, true);
        if (!\is_array($decoded)) {
            return false;
        }

        return $this->jsonContainsScalar($decoded, $needle);
    }

    protected function jsonContainsScalar(mixed $value, int $needle, string|int|null $key = null): bool
    {
        if (\is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value))) {
            if ($this->isIgnoredJoinSecretKey($key)) {
                return false;
            }

            return (int) $value === $needle;
        }

        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return $this->jsonContainsScalar($decoded, $needle);
            }

            return false;
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $childKey => $child) {
            if ($this->jsonContainsScalar($child, $needle, $childKey)) {
                return true;
            }
        }

        return false;
    }

    protected function isIgnoredJoinSecretKey(string|int|null $key): bool
    {
        $name = \is_string($key) && \str_contains($key, '.')
            ? \substr($key, (int) \strrpos($key, '.') + 1)
            : $key;

        return \in_array($name, [
            '$id',
            '$sequence',
            '$createdAt',
            '$updatedAt',
            '$tenant',
            '$collection',
            '$distance',
            '$deletedAt',
            '$internalId',
            '$skipPermissionsUpdate',
        ], true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function assertJoinComboClientHidden(array $result, array $rows, array $amounts): void
    {
        $encoded = $this->joinEncodedBody($result);
        $this->assertStringNotContainsString('combo-secret-alpha', $encoded);
        $this->assertStringNotContainsString('user:combo-hidden', $encoded);
        $this->assertSame(false, \in_array(777, $amounts, true));
        $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 777));

        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $decodedEncoded = (string) \json_encode($decoded);
            $this->assertStringNotContainsString('combo-secret-alpha', $decodedEncoded);
            $this->assertStringNotContainsString('user:combo-hidden', $decodedEncoded);
            $this->assertSame(false, \in_array('combo-secret-alpha', $decoded, true));
            $this->assertSame(false, $this->jsonContainsScalar($decoded, 777));

            $permissions = $row['_permissions'] ?? [];
            $permissionsEncoded = (string) \json_encode($permissions);
            $this->assertStringNotContainsString('combo-secret-alpha', $permissionsEncoded);
            $this->assertStringNotContainsString('user:combo-hidden', $permissionsEncoded);

            $decodedPermissions = $decoded['sec.$permissions'] ?? $decoded['$permissions'] ?? [];
            $decodedPermissionsEncoded = (string) \json_encode($decodedPermissions);
            $this->assertStringNotContainsString('user:combo-hidden', $decodedPermissionsEncoded);
        }
    }

    public function testJoinComboListLeftAndInnerOmitsSecret(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinComboFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], $this->joinComboLeftAndInnerQueries($data, [
            Query::select(['name', 'pub.amount', 'sec.amount', 'sec.secret'])->toString(),
        ])));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinComboClientHidden($result, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }
    }

    public function testJoinComboGetLeftAndInnerOmitsSecret(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinComboFixture();

        $result = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($data['databaseId'], $data['customersId'], $data['aliceId'], $this->joinComboLeftAndInnerQueries($data, [
            Query::select(['name', 'pub.amount', 'sec.amount', 'sec.secret'])->toString(),
        ])));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $record = $this->joinGetRecord($result);
        $this->assertSame($data['aliceId'], $record['_id']);
        $amounts = $this->joinComboAmounts([$record]);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinComboClientHidden($result, [$record], $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }
    }

    public function testJoinComboListFilterOracleOmitsSecret(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinComboFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], $this->joinComboLeftAndInnerQueries($data, [
            Query::select(['name', 'pub.amount', 'sec.amount', 'sec.secret'])->toString(),
            Query::equal('sec.secret', ['combo-secret-alpha'])->toString(),
            Query::equal('sec.amount', [777])->toString(),
        ])));

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($rows));
            $this->assertSame(0, (int) ($result['body']['data'][$this->joinListField()]['total'] ?? 0));
            $this->assertJoinComboClientHidden($result, $rows, $amounts);
        }
    }

    public function testJoinComboListSelectPermissionsOmitsRole(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinComboFixture();

        $result = $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($data['databaseId'], $data['customersId'], $this->joinComboLeftAndInnerQueries($data, [
            Query::select(['name', 'pub.amount', 'sec.secret', 'sec.$permissions', 'pub.$permissions'])->toString(),
        ])));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = $this->joinComboAmounts($rows);
        $decodedRows = \array_map($this->decodeJoinData(...), $rows);
        $publicRows = \array_values(\array_filter($decodedRows, static fn (array $row): bool => (int) ($row['pub.amount'] ?? 0) === 313));

        $this->assertNotEmpty($publicRows);
        foreach ($publicRows as $row) {
            $this->assertArrayHasKey('pub.$permissions', $row);
            $this->assertSame([Permission::read(Role::user($this->getUser()['$id']))], $row['pub.$permissions'], 'a joined row exposes exactly the permissions a direct read of it exposes');
        }
        $secretPermissions = \array_merge(...\array_map(static fn (array $row): array => (array) ($row['sec.$permissions'] ?? []), $decodedRows));

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame([], $secretPermissions, 'the unreadable row is left out of the join, its permissions included');
            $this->assertJoinComboClientHidden($result, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertContains(Permission::read(Role::user('combo-hidden')), $secretPermissions);
        }
    }

    protected function setupJoinHardcoreFixture(): array
    {
        $cacheKey = 'gql-hardcore:' . ($this->getProject()['$id'] ?? 'default');
        if (!empty(self::$joinHardcoreCache[$cacheKey])) {
            return self::$joinHardcoreCache[$cacheKey];
        }

        $suffix = ID::unique();
        $serverHeaders = $this->joinServerHeaders();
        $any = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ];
        $createOnly = [Permission::create(Role::any())];
        $readAny = [Permission::read(Role::any())];
        $hidden = [Permission::read(Role::user('combo-hard-hidden'))];
        $midHidden = [Permission::read(Role::user('jh-mid-hidden'))];

        $database = $this->client->call(Client::METHOD_POST, $this->joinApiBase(), $serverHeaders, [
            'databaseId' => ID::unique(),
            'name' => 'jhGraphQL' . $suffix,
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $customersId = $this->createJoinHardcoreContainer($databaseId, 'jhCustomers' . $suffix, true, $any);
        $ordersId = $this->createJoinHardcoreContainer($databaseId, 'jhOrders' . $suffix, true, $createOnly);
        $midId = $this->createJoinHardcoreContainer($databaseId, 'jhMid' . $suffix, false, $any);
        $secretsId = $this->createJoinHardcoreContainer($databaseId, 'jhSecrets' . $suffix, true, $createOnly);
        $rightId = $this->createJoinHardcoreContainer($databaseId, 'jhRight' . $suffix, true, $any);

        $this->createJoinAttribute($databaseId, $customersId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $customersId, 'string', [
            'key' => 'code',
            'size' => 32,
            'required' => true,
        ]);
        $this->createJoinAttribute($databaseId, $ordersId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $ordersId, 'string', [
            'key' => 'partnerCode',
            'size' => 32,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $ordersId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $ordersId, 'string', [
            'key' => 'label',
            'size' => 64,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $midId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $midId, 'string', [
            'key' => 'note',
            'size' => 64,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $midId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretsId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretsId, 'string', [
            'key' => 'midId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretsId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretsId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $secretsId, 'integer', [
            'key' => 'payload',
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $rightId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createJoinAttribute($databaseId, $rightId, 'string', [
            'key' => 'tag',
            'size' => 32,
            'required' => false,
        ]);

        foreach ([
            [$customersId, 'name'],
            [$customersId, 'code'],
            [$ordersId, 'customerId'],
            [$ordersId, 'partnerCode'],
            [$ordersId, 'amount'],
            [$ordersId, 'label'],
            [$midId, 'customerId'],
            [$midId, 'note'],
            [$midId, 'amount'],
            [$secretsId, 'customerId'],
            [$secretsId, 'midId'],
            [$secretsId, 'amount'],
            [$secretsId, 'secret'],
            [$secretsId, 'payload'],
            [$rightId, 'customerId'],
            [$rightId, 'tag'],
        ] as [$containerId, $key]) {
            $this->waitForJoinAttribute($databaseId, $containerId, $key);
        }

        $alice = $this->createJoinHardcoreRecord($databaseId, $customersId, [
            'name' => 'Alice',
            'code' => 'ALICE',
        ], $readAny);
        $bob = $this->createJoinHardcoreRecord($databaseId, $customersId, [
            'name' => 'Bob',
            'code' => 'BOB',
        ], $readAny);
        $carol = $this->createJoinHardcoreRecord($databaseId, $customersId, [
            'name' => 'Carol',
            'code' => 'CAROL',
        ], $readAny);
        $dave = $this->createJoinHardcoreRecord($databaseId, $customersId, [
            'name' => 'Dave',
            'code' => 'DAVE',
        ], $readAny);

        $aliceId = $alice['$id'];
        $bobId = $bob['$id'];
        $carolId = $carol['$id'];
        $daveId = $dave['$id'];

        $order200 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'customerId' => $aliceId,
            'partnerCode' => 'CAROL',
            'amount' => 200,
            'label' => 'visible-gamma',
        ], $readAny);
        $order313 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'customerId' => $aliceId,
            'partnerCode' => 'BOB',
            'amount' => 313,
            'label' => 'visible-alpha',
        ], $readAny);
        $order424 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'customerId' => $bobId,
            'partnerCode' => 'ALICE',
            'amount' => 424,
            'label' => 'visible-beta',
        ], $readAny);
        $order100 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'customerId' => $daveId,
            'partnerCode' => 'DAVE',
            'amount' => 100,
            'label' => 'visible-delta',
        ], $readAny);
        $order700 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'partnerCode' => 'ZZZ',
            'amount' => 700,
            'label' => 'visible-orphan',
        ], $readAny);
        $order8686 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'customerId' => $aliceId,
            'partnerCode' => 'ALICE',
            'amount' => 8686,
            'label' => 'combo-hard-alpha',
        ], $hidden);
        $order5151 = $this->createJoinHardcoreRecord($databaseId, $ordersId, [
            'partnerCode' => 'ZZZ',
            'amount' => 5151,
            'label' => 'combo-hard-alpha',
        ], $hidden);

        $midAlice = $this->createJoinHardcoreRecord($databaseId, $midId, [
            'customerId' => $aliceId,
            'note' => 'mid-visible',
            'amount' => 111,
        ], $midHidden);
        $this->createJoinHardcoreRecord($databaseId, $midId, [
            'customerId' => $bobId,
            'note' => 'mid-bob',
            'amount' => 122,
        ], $midHidden);
        $this->createJoinHardcoreRecord($databaseId, $midId, [
            'customerId' => $daveId,
            'note' => 'mid-dave',
            'amount' => 133,
        ], $midHidden);

        $this->createJoinHardcoreRecord($databaseId, $secretsId, [
            'customerId' => $aliceId,
            'midId' => $midAlice['$id'],
            'amount' => 8686,
            'secret' => 'combo-hard-alpha',
            'payload' => 5151,
        ], $hidden);

        $this->createJoinHardcoreRecord($databaseId, $rightId, [
            'customerId' => $aliceId,
            'tag' => 'right-ok',
        ], $readAny);
        $this->createJoinHardcoreRecord($databaseId, $rightId, [
            'customerId' => $bobId,
            'tag' => 'right-bob',
        ], $readAny);
        $this->createJoinHardcoreRecord($databaseId, $rightId, [
            'customerId' => $daveId,
            'tag' => 'right-dave',
        ], $readAny);

        self::$joinHardcoreCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'customersId' => $customersId,
            'ordersId' => $ordersId,
            'midId' => $midId,
            'secretsId' => $secretsId,
            'rightId' => $rightId,
            'aliceId' => $aliceId,
            'bobId' => $bobId,
            'carolId' => $carolId,
            'daveId' => $daveId,
            'customerIds' => [$aliceId, $bobId, $carolId, $daveId],
            'orderIds' => [
                $order100['$id'],
                $order200['$id'],
                $order313['$id'],
                $order424['$id'],
                $order700['$id'],
                $order8686['$id'],
                $order5151['$id'],
            ],
            'order100Id' => $order100['$id'],
            'order200Id' => $order200['$id'],
            'order313Id' => $order313['$id'],
            'order424Id' => $order424['$id'],
            'order700Id' => $order700['$id'],
            'order8686Id' => $order8686['$id'],
            'order5151Id' => $order5151['$id'],
            'order313CreatedAt' => $order313['$createdAt'] ?? '',
        ];

        return self::$joinHardcoreCache[$cacheKey];
    }

    /**
     * @param list<string> $permissions
     */
    protected function createJoinHardcoreContainer(string $databaseId, string $name, bool $documentSecurity, array $permissions): string
    {
        $result = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $this->joinServerHeaders(), [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => $name,
            $this->joinSecurityParam() => $documentSecurity,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $result['headers']['status-code']);

        return $result['body']['$id'];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    protected function createJoinHardcoreRecord(string $databaseId, string $containerId, array $data, array $permissions): array
    {
        $result = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $containerId), $this->joinServerHeaders(), [
            $this->joinRecordIdParam() => ID::unique(),
            'data' => $data,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $result['headers']['status-code']);

        return $result['body'];
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function joinHardcoreList(string $databaseId, string $containerId, array $queries): array
    {
        return $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($databaseId, $containerId, $queries));
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function joinHardcoreGet(string $databaseId, string $containerId, string $recordId, array $queries): array
    {
        return $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($databaseId, $containerId, $recordId, $queries));
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    protected function joinHardcoreRows(array $result): array
    {
        return $this->joinListRecords($result);
    }

    protected function joinHardcoreTotal(array $result): int
    {
        return (int) ($result['body']['data'][$this->joinListField()]['total'] ?? 0);
    }

    protected function joinHardcoreField(array $row, string $suffix): mixed
    {
        $decoded = $this->decodeJoinData($row);
        if (\array_key_exists($suffix, $decoded)) {
            return $decoded[$suffix];
        }

        foreach ($decoded as $key => $value) {
            if (\is_string($key) && \str_ends_with($key, '.' . $suffix)) {
                return $value;
            }
        }

        if (\array_key_exists($suffix, $row)) {
            return $row[$suffix];
        }

        return null;
    }

    protected function joinHardcoreCursorId(array $row): string
    {
        $id = $row['_id'] ?? '';
        if (\is_string($id) && $id !== '') {
            return $id;
        }

        $decoded = $this->decodeJoinData($row);
        $decodedId = $decoded['$id'] ?? $decoded['_id'] ?? '';

        return \is_string($decodedId) ? $decodedId : '';
    }

    protected function encodedJsonContainsExactString(string $encoded, string $needle): bool
    {
        $decoded = \json_decode($encoded, true);
        if (!\is_array($decoded)) {
            return false;
        }

        return $this->jsonContainsExactString($decoded, $needle);
    }

    protected function jsonContainsExactString(mixed $value, string $needle): bool
    {
        if (\is_string($value)) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return $this->jsonContainsExactString($decoded, $needle);
            }

            return $value === $needle;
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $child) {
            if ($this->jsonContainsExactString($child, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function assertJoinHardcoreClientHidden(array $result, array $rows, array $amounts = []): void
    {
        $encoded = $this->joinEncodedBody($result);
        $this->assertStringNotContainsString('combo-hard-alpha', $encoded);
        $this->assertStringNotContainsString('user:combo-hard-hidden', $encoded);
        $this->assertSame(false, \in_array(8686, $amounts, true));
        $this->assertSame(false, \in_array(5151, $amounts, true));
        $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8686));
        $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 5151));
        $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '8686'));
        $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '5151'));

        foreach ($rows as $row) {
            $dataString = $row['data'] ?? '';
            if (\is_string($dataString) && $dataString !== '') {
                $this->assertStringNotContainsString('combo-hard-alpha', $dataString);
                $this->assertStringNotContainsString('user:combo-hard-hidden', $dataString);
                $this->assertSame(false, $this->encodedJsonContainsScalar($dataString, 8686));
                $this->assertSame(false, $this->encodedJsonContainsScalar($dataString, 5151));
                $this->assertSame(false, $this->encodedJsonContainsExactString($dataString, '8686'));
                $this->assertSame(false, $this->encodedJsonContainsExactString($dataString, '5151'));
            }

            $decoded = $this->decodeJoinData($row);
            $decodedEncoded = (string) \json_encode($decoded);
            $this->assertStringNotContainsString('combo-hard-alpha', $decodedEncoded);
            $this->assertStringNotContainsString('user:combo-hard-hidden', $decodedEncoded);
            $this->assertSame(false, \in_array('combo-hard-alpha', $decoded, true));
            $this->assertSame(false, \in_array(8686, $decoded, true));
            $this->assertSame(false, \in_array(5151, $decoded, true));
            $this->assertSame(false, \in_array('8686', $decoded, true));
            $this->assertSame(false, \in_array('5151', $decoded, true));
            $this->assertSame(false, $this->jsonContainsScalar($decoded, 8686));
            $this->assertSame(false, $this->jsonContainsScalar($decoded, 5151));
            $this->assertSame(false, $this->jsonContainsExactString($decoded, '8686'));
            $this->assertSame(false, $this->jsonContainsExactString($decoded, '5151'));
            $this->assertSame(false, $this->jsonContainsExactString($decoded, 'combo-hard-alpha'));

            $permissions = $row['_permissions'] ?? [];
            $permissionsEncoded = (string) \json_encode($permissions);
            $this->assertStringNotContainsString('combo-hard-alpha', $permissionsEncoded);
            $this->assertStringNotContainsString('user:combo-hard-hidden', $permissionsEncoded);

            $decodedPermissions = $decoded['sec.$permissions'] ?? $decoded['ord.$permissions'] ?? $decoded['$permissions'] ?? [];
            $decodedPermissionsEncoded = (string) \json_encode($decodedPermissions);
            $this->assertStringNotContainsString('user:combo-hard-hidden', $decodedPermissionsEncoded);
        }
    }

    public function testJoinHardcoreSameTableTwoAliasesIndependentPredicates(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'alpha')->toString(),
            Query::join($data['ordersId'], 'code', 'partnerCode', '=', 'beta')->toString(),
            Query::select(['name', 'code', 'alpha.amount', 'beta.amount', 'alpha.label', 'beta.label'])->toString(),
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $listed['body']);
        $rows = $this->joinHardcoreRows($listed);
        $amounts = $this->joinComboAmounts($rows);

        $alicePairs = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $this->assertArrayHasKey('alpha.amount', $decoded);
            $this->assertArrayHasKey('beta.amount', $decoded);
            if (($decoded['name'] ?? null) === 'Alice') {
                $alicePairs[] = [(int) $decoded['alpha.amount'], (int) $decoded['beta.amount']];
            }
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['_id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array([313, 424], $alicePairs, true));
            $this->assertSame(false, \in_array([313, 313], $alicePairs, true));
            $this->assertJoinHardcoreClientHidden($listed, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $independent = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'alpha')->toString(),
            Query::join($data['ordersId'], 'code', 'partnerCode', '=', 'beta')->toString(),
            Query::equal('alpha.amount', [313])->toString(),
            Query::equal('beta.amount', [424])->toString(),
            Query::select(['name', 'alpha.amount', 'beta.amount'])->toString(),
        ]);

        $this->assertSame(200, $independent['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $independent['body']);
        $independentRows = $this->joinHardcoreRows($independent);
        $independentAmounts = $this->joinComboAmounts($independentRows);

        if ($this->getSide() === 'client') {
            $this->assertGreaterThanOrEqual(1, \count($independentRows));
            foreach ($independentRows as $row) {
                $decoded = $this->decodeJoinData($row);
                $this->assertSame('Alice', $decoded['name'] ?? null);
                $this->assertSame($data['aliceId'], $row['_id'] ?? null);
                $this->assertSame(313, (int) $decoded['alpha.amount']);
                $this->assertSame(424, (int) $decoded['beta.amount']);
            }
            $this->assertJoinHardcoreClientHidden($independent, $independentRows, $independentAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $independentAmounts, true));
        }

        $hiddenOnly = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'alpha')->toString(),
            Query::join($data['ordersId'], 'code', 'partnerCode', '=', 'beta')->toString(),
            Query::equal('alpha.amount', [8686])->toString(),
            Query::select(['name', 'alpha.amount', 'beta.amount'])->toString(),
        ]);

        $this->assertSame(200, $hiddenOnly['headers']['status-code']);
        $hiddenRows = $this->joinHardcoreRows($hiddenOnly);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey('errors', $hiddenOnly['body']);
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, $this->joinHardcoreTotal($hiddenOnly));
            $this->assertJoinHardcoreClientHidden($hiddenOnly, $hiddenRows, $this->joinComboAmounts($hiddenRows));
        }
    }

    public function testJoinHardcoreSelfJoinOnIdDoesNotSmashIdentity(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $queries = [
            Query::join($data['customersId'], '$id', '$id', '=', 'peer')->toString(),
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'peer.name', 'peer.$id', 'ord.amount', 'ord.$id'])->toString(),
        ];

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], $queries);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $listed['body']);
        $rows = $this->joinHardcoreRows($listed);
        $this->assertNotEmpty($rows);
        $amounts = $this->joinComboAmounts($rows);

        foreach ($rows as $row) {
            $id = $row['_id'] ?? null;
            $decoded = $this->decodeJoinData($row);
            $this->assertSame(true, \in_array($id, $data['customerIds'], true));
            $this->assertSame(false, \in_array($id, $data['orderIds'], true));
            if (\array_key_exists('$id', $decoded)) {
                $this->assertSame($id, $decoded['$id']);
                $this->assertSame(true, \in_array($decoded['$id'], $data['customerIds'], true));
                $this->assertSame(false, \in_array($decoded['$id'], $data['orderIds'], true));
            }
            $peerId = $decoded['peer.$id'] ?? null;
            if (\is_string($peerId) && $peerId !== '') {
                $this->assertSame(true, \in_array($peerId, $data['customerIds'], true));
            }
        }

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($listed, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $got = $this->joinHardcoreGet($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::join($data['customersId'], '$id', '$id', '=', 'peer')->toString(),
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'peer.name', 'peer.$id', 'ord.amount'])->toString(),
        ]);

        $this->assertSame(200, $got['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $got['body']);
        $record = $this->joinGetRecord($got);
        $decoded = $this->decodeJoinData($record);
        $this->assertSame($data['aliceId'], $record['_id']);
        $this->assertSame('Alice', $decoded['name'] ?? null);
        $this->assertSame(false, \in_array($record['_id'] ?? null, $data['orderIds'], true));
        if (\array_key_exists('$id', $decoded)) {
            $this->assertSame($data['aliceId'], $decoded['$id']);
        }

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($got, [$record], $this->joinComboAmounts([$record]));
        }
    }

    public function testJoinHardcoreLeftInnerRightMixedDocSec(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $result = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::join($data['midId'], '$id', 'customerId', '=', 'mid')->toString(),
            Query::rightJoin($data['rightId'], '$id', 'customerId', '=', 'rt')->toString(),
            Query::select(['name', 'ord.amount', 'mid.note', 'rt.tag'])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinHardcoreRows($result);
        $this->assertNotEmpty($rows);
        $amounts = $this->joinComboAmounts($rows);
        $names = [];
        $notes = [];

        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $name = $decoded['name'] ?? null;
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
            $note = $decoded['mid.note'] ?? $decoded['note'] ?? null;
            if (\is_string($note) && $note !== '') {
                $notes[] = $note;
            }
            $this->assertNotSame('Carol', $name);
            $id = $row['_id'] ?? null;
            if (\is_string($id) && $id !== '') {
                $this->assertSame(true, \in_array($id, $data['customerIds'], true));
                $this->assertSame(false, \in_array($id, $data['orderIds'], true));
            }
        }

        $this->assertSame(false, \in_array('Carol', $names, true));
        $this->assertSame(true, \in_array('mid-visible', $notes, true));

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinHardcoreClientHidden($result, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }
    }

    public function testJoinHardcoreChainAOnBOffCOnHidesC(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $result = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['midId'], '$id', 'customerId', '=', 'mid')->toString(),
            Query::leftJoin($data['secretsId'], 'mid.$id', 'midId', '=', 'sec')->toString(),
            Query::select(['name', 'mid.note', 'mid.amount', 'sec.secret', 'sec.amount', 'sec.payload'])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinHardcoreRows($result);
        $this->assertNotEmpty($rows);
        $amounts = $this->joinComboAmounts($rows);
        $notes = [];

        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $note = $decoded['mid.note'] ?? $decoded['note'] ?? null;
            if (\is_string($note) && $note !== '') {
                $notes[] = $note;
            }
            if (($decoded['name'] ?? null) === 'Alice') {
                $this->assertSame('mid-visible', $note);
                $midAmount = $decoded['mid.amount'] ?? $decoded['amount'] ?? null;
                $this->assertSame(111, (int) $midAmount);
            }

            $dataString = $row['data'] ?? '';
            $this->assertIsString($dataString);
            if ($this->getSide() === 'client') {
                $this->assertStringNotContainsString('combo-hard-alpha', $dataString);
                $this->assertSame(false, $this->encodedJsonContainsScalar($dataString, 8686));
                $this->assertSame(false, $this->encodedJsonContainsExactString($dataString, '8686'));
                $this->assertSame(false, $this->encodedJsonContainsExactString($dataString, 'combo-hard-alpha'));
            }
        }

        $this->assertSame(true, \in_array('mid-visible', $notes, true));

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($result, $rows, $amounts);
            foreach ($rows as $row) {
                $decoded = $this->decodeJoinData($row);
                $this->assertNotSame('combo-hard-alpha', $decoded['sec.secret'] ?? $decoded['secret'] ?? null);
            }
        } else {
            $this->assertSame(true, \in_array(111, $amounts, true));
        }
    }

    public function testJoinHardcoreFullOuterJoinSideCursorPageWalk(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $orderQueries = [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::orderAsc('ord.amount')->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ];

        $ordered = $this->joinHardcoreList($data['databaseId'], $data['customersId'], $orderQueries);
        $this->assertSame(200, $ordered['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $ordered['body']);
        $rows = $this->joinHardcoreRows($ordered);
        $this->assertNotEmpty($rows);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame(true, \in_array(700, $amounts, true));
            $this->assertJoinHardcoreClientHidden($ordered, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $cursorRow = null;
        foreach ($rows as $row) {
            $amount = $this->joinHardcoreField($row, 'amount');
            $id = $this->joinHardcoreCursorId($row);
            if (\is_numeric($amount) && $id !== '') {
                $cursorRow = $row;
                break;
            }
        }

        $this->assertNotNull($cursorRow);
        $cursorId = $this->joinHardcoreCursorId($cursorRow);
        $this->assertNotSame('', $cursorId);
        $this->assertSame(true, \is_numeric($this->joinHardcoreField($cursorRow, 'amount')));
        $this->assertSame(false, \in_array($cursorId, [$data['order700Id'], $data['order5151Id'], $data['order8686Id']], true));

        $firstPage = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $firstPage['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $firstPage['body']);
        $firstRows = $this->joinHardcoreRows($firstPage);
        $this->assertSame(1, \count($firstRows));

        $after = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $after['body']);
        $afterRows = $this->joinHardcoreRows($after);
        $this->assertSame(1, \count($afterRows));
        $afterId = $afterRows[0]['_id'] ?? '';
        $this->assertNotSame('', $afterId);
        $this->assertNotSame($cursorId, $afterId);

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($after, $afterRows, $this->joinComboAmounts($afterRows));
        }

        $afterAmount = $this->joinHardcoreField($afterRows[0], 'amount');
        $cursorAmount = $this->joinHardcoreField($cursorRow, 'amount');
        if (\is_numeric($afterAmount) && \is_numeric($cursorAmount)) {
            $this->assertSame(true, (int) $afterAmount >= (int) $cursorAmount);
        }

        $before = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorBefore(new Document(['$id' => $afterId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $before['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $before['body']);
        $beforeRows = $this->joinHardcoreRows($before);
        $this->assertLessThanOrEqual(1, \count($beforeRows));
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($before, $beforeRows, $this->joinComboAmounts($beforeRows));
        }
    }

    public function testJoinHardcoreAndOrMixMainAndJoinFilters(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $mixed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::leftJoin($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::and([
                Query::equal('name', ['Alice']),
                Query::or([
                    Query::equal('ord.amount', [313]),
                    Query::equal('sec.amount', [8686]),
                ]),
            ])->toString(),
            Query::select(['name', 'ord.amount', 'sec.amount', 'sec.secret'])->toString(),
        ]);

        $this->assertSame(200, $mixed['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $mixed['body']);
        $mixedRows = $this->joinHardcoreRows($mixed);
        $mixedAmounts = $this->joinComboAmounts($mixedRows);

        if ($this->getSide() === 'client') {
            $this->assertNotEmpty($mixedRows);
            foreach ($mixedRows as $row) {
                $decoded = $this->decodeJoinData($row);
                $this->assertSame('Alice', $decoded['name'] ?? null);
                $this->assertSame($data['aliceId'], $row['_id'] ?? null);
            }
            $this->assertSame(true, \in_array(313, $mixedAmounts, true));
            $this->assertJoinHardcoreClientHidden($mixed, $mixedRows, $mixedAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $mixedAmounts, true));
        }

        $hiddenOnly = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::leftJoin($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::and([
                Query::or([
                    Query::equal('name', ['Alice']),
                    Query::equal('ord.amount', [313]),
                ]),
                Query::equal('sec.secret', ['combo-hard-alpha']),
            ])->toString(),
            Query::select(['name', 'ord.amount', 'sec.amount', 'sec.secret'])->toString(),
        ]);

        $this->assertSame(200, $hiddenOnly['headers']['status-code']);
        $hiddenRows = $this->joinHardcoreRows($hiddenOnly);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey('errors', $hiddenOnly['body']);
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, $this->joinHardcoreTotal($hiddenOnly));
            $this->assertJoinHardcoreClientHidden($hiddenOnly, $hiddenRows, $this->joinComboAmounts($hiddenRows));
        }
    }

    public function testJoinHardcoreMixedMainJoinOrderCursor(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $orderQueries = [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::orderAsc('name')->toString(),
            Query::orderDesc('ord.amount')->toString(),
            Query::select(['name', 'ord.amount'])->toString(),
        ];

        $ordered = $this->joinHardcoreList($data['databaseId'], $data['customersId'], $orderQueries);
        $this->assertSame(200, $ordered['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $ordered['body']);
        $rows = $this->joinHardcoreRows($ordered);
        $this->assertNotEmpty($rows);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinHardcoreClientHidden($ordered, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $first = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $first['body']);
        $firstRows = $this->joinHardcoreRows($first);
        $this->assertSame(1, \count($firstRows));
        $this->assertSame('Alice', $this->decodeJoinData($firstRows[0])['name'] ?? null);
        $cursorId = $this->joinHardcoreCursorId($firstRows[0]);
        $this->assertNotSame('', $cursorId);
        $this->assertSame($data['aliceId'], $cursorId);

        $after = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $after['body']);
        $afterRows = $this->joinHardcoreRows($after);
        $this->assertSame(1, \count($afterRows));
        $afterName = (string) ($this->decodeJoinData($afterRows[0])['name'] ?? '');
        $this->assertSame(true, $afterName >= 'Alice');
        $afterId = $afterRows[0]['_id'] ?? '';
        $this->assertNotSame('', $afterId);
        $this->assertNotSame($cursorId, $afterId);
        $this->assertSame(false, \in_array($afterId, $data['orderIds'], true));

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($after, $afterRows, $this->joinComboAmounts($afterRows));
        }

        $before = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorBefore(new Document(['$id' => $afterId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $before['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $before['body']);
        $beforeRows = $this->joinHardcoreRows($before);
        $this->assertLessThanOrEqual(1, \count($beforeRows));
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($before, $beforeRows, $this->joinComboAmounts($beforeRows));
        }
    }

    public function testJoinHardcoreJoinSideOperatorsAndInternalAttrs(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $join = Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString();
        $select = Query::select(['name', 'ord.amount', 'ord.label', 'ord.$id', 'ord.$createdAt'])->toString();

        $contains = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::containsString('ord.label', ['visible'])->toString(),
            $select,
        ]);
        $this->assertSame(200, $contains['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $contains['body']);
        $containsRows = $this->joinHardcoreRows($contains);
        $this->assertNotEmpty($containsRows);
        $containsAmounts = $this->joinComboAmounts($containsRows);
        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $containsAmounts, true));
            $this->assertJoinHardcoreClientHidden($contains, $containsRows, $containsAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $containsAmounts, true));
        }

        $between = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::between('ord.amount', 100, 500)->toString(),
            $select,
        ]);
        $this->assertSame(200, $between['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $between['body']);
        $betweenRows = $this->joinHardcoreRows($between);
        $this->assertNotEmpty($betweenRows);
        $betweenAmounts = $this->joinComboAmounts($betweenRows);
        foreach ($betweenAmounts as $amount) {
            $this->assertSame(true, $amount >= 100 && $amount <= 500);
        }
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($between, $betweenRows, $betweenAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $betweenAmounts, true));
        }

        $starts = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::startsWith('ord.label', 'visible')->toString(),
            $select,
        ]);
        $this->assertSame(200, $starts['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $starts['body']);
        $startsRows = $this->joinHardcoreRows($starts);
        $this->assertNotEmpty($startsRows);
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($starts, $startsRows, $this->joinComboAmounts($startsRows));
        }

        $byId = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::equal('ord.$id', [$data['order313Id']])->toString(),
            $select,
        ]);
        $this->assertSame(200, $byId['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $byId['body']);
        $byIdRows = $this->joinHardcoreRows($byId);
        $this->assertNotEmpty($byIdRows);
        foreach ($byIdRows as $row) {
            $this->assertSame($data['aliceId'], $row['_id'] ?? null);
            $joinId = $this->decodeJoinData($row)['ord.$id'] ?? null;
            if (\is_string($joinId) && $joinId !== '') {
                $this->assertSame($data['order313Id'], $joinId);
            }
        }
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($byId, $byIdRows, $this->joinComboAmounts($byIdRows));
        }

        $byCreated = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::between('ord.$createdAt', '1970-01-01', '2099-12-31')->toString(),
            $select,
        ]);
        $this->assertSame(200, $byCreated['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $byCreated['body']);
        $createdRows = $this->joinHardcoreRows($byCreated);
        $this->assertNotEmpty($createdRows);
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($byCreated, $createdRows, $this->joinComboAmounts($createdRows));
        }

        $search = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::search('ord.label', 'visible')->toString(),
            $select,
        ]);
        if (!isset($search['body']['errors'])) {
            $searchRows = $this->joinHardcoreRows($search);
            if ($this->getSide() === 'client') {
                $this->assertJoinHardcoreClientHidden($search, $searchRows, $this->joinComboAmounts($searchRows));
            }
        }
    }

    public function testJoinHardcoreRightUnmatchedMainIdentityAndSelectSubset(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $result = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::rightJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinHardcoreRows($result);
        $this->assertNotEmpty($rows);
        $encoded = $this->joinEncodedBody($result);
        $amounts = $this->joinComboAmounts($rows);
        $orphanSeen = false;

        foreach ($rows as $row) {
            $id = $row['_id'] ?? null;
            $decoded = $this->decodeJoinData($row);
            $name = $decoded['name'] ?? null;
            $this->assertSame(false, \in_array($id, $data['orderIds'], true));
            $this->assertArrayNotHasKey('ord.$id', $decoded);
            $this->assertArrayNotHasKey('ord.$permissions', $decoded);
            if (\is_string($row['data'] ?? null)) {
                $this->assertStringNotContainsString('ord.$id', $row['data']);
                $this->assertStringNotContainsString('ord.$permissions', $row['data']);
            }

            if ($name === null || $name === '') {
                $orphanSeen = true;
                $this->assertTrue($id === null || $id === '');
                $this->assertNotSame($data['order700Id'], $id);
                $amount = $this->joinHardcoreField($row, 'amount');
                if ($this->getSide() === 'client') {
                    $this->assertSame(700, (int) $amount);
                }
            } else {
                $this->assertSame(true, \in_array($id, $data['customerIds'], true));
            }
        }

        $this->assertSame(true, $orphanSeen);
        $this->assertSame(false, \str_contains($encoded, $data['order700Id']));

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame(true, \in_array(700, $amounts, true));
            $this->assertJoinHardcoreClientHidden($result, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }
    }

    public function testJoinHardcoreCoerceSecret8686AbsentWhenUnauthorized(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $result = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::equal('sec.amount', ['8686'])->toString(),
            Query::select(['name', 'sec.amount', 'sec.secret', 'sec.payload'])->toString(),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $this->joinHardcoreRows($result);
        $amounts = $this->joinComboAmounts($rows);
        $encoded = $this->joinEncodedBody($result);

        if ($this->getSide() === 'client') {
            $this->assertArrayHasKey('errors', $result['body']);
            $this->assertSame(0, \count($rows));
            $this->assertSame(0, $this->joinHardcoreTotal($result));
            $this->assertJoinHardcoreClientHidden($result, $rows, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8686));
            $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '8686'));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 5151));
            $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '5151'));
        }
    }

    public function testJoinHardcoreSkipAuthMixedDocSecStillHidesSecrets(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $joinQueries = [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ];

        $control = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::select(['name'])->toString(),
        ]);
        $this->assertSame(200, $control['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $control['body']);
        $controlRows = $this->joinHardcoreRows($control);
        $this->assertNotEmpty($controlRows);
        $controlNames = [];
        foreach ($controlRows as $row) {
            $name = $this->decodeJoinData($row)['name'] ?? null;
            if (\is_string($name) && $name !== '') {
                $controlNames[] = $name;
            }
        }
        $this->assertSame(true, \in_array('Alice', $controlNames, true));

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], $joinQueries);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $listed['body']);
        $rows = $this->joinHardcoreRows($listed);
        $this->assertNotEmpty($rows);
        $this->assertSame(\count($rows), $this->graphqlTotal($listed));
        $amounts = $this->joinComboAmounts($rows);

        foreach ($rows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['_id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            foreach ([200, 313, 424, 100] as $visible) {
                $this->assertSame(true, \in_array($visible, $amounts, true));
            }
            $this->assertJoinHardcoreClientHidden($listed, $rows, $amounts);
            $this->assertJoinHardcoreClientHidden($control, $controlRows, $this->joinComboAmounts($controlRows));
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $got = $this->joinHardcoreGet($data['databaseId'], $data['customersId'], $data['aliceId'], $joinQueries);
        $this->assertSame(200, $got['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $got['body']);
        $record = $this->joinGetRecord($got);
        $decoded = $this->decodeJoinData($record);
        $this->assertSame($data['aliceId'], $record['_id']);
        $this->assertSame('Alice', $decoded['name'] ?? null);
        $this->assertSame(false, \in_array($record['_id'] ?? null, $data['orderIds'], true));
        $this->assertNotSame($data['order8686Id'], $record['_id']);
        if (\array_key_exists('$id', $decoded)) {
            $this->assertSame($data['aliceId'], $decoded['$id']);
        }

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($got, [$record], $this->joinComboAmounts([$record]));
        }
    }

    public function testJoinHardcoreNestedAndOrTwoAliasesIndependent(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $joins = [
            Query::join($data['ordersId'], '$id', 'customerId', '=', 'alpha')->toString(),
            Query::join($data['ordersId'], 'code', 'partnerCode', '=', 'beta')->toString(),
        ];

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$joins,
            Query::and([
                Query::equal('alpha.amount', [313]),
                Query::or([
                    Query::equal('beta.amount', [424]),
                    Query::equal('alpha.amount', [8686]),
                ]),
            ])->toString(),
            Query::select(['name', 'alpha.amount', 'beta.amount'])->toString(),
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $listed['body']);
        $rows = $this->joinHardcoreRows($listed);
        $amounts = $this->joinComboAmounts($rows);
        $alicePairs = [];

        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['_id'] ?? null);
            if (($decoded['name'] ?? null) === 'Alice') {
                $alicePairs[] = [(int) $decoded['alpha.amount'], (int) $decoded['beta.amount']];
            }
        }

        if ($this->getSide() === 'client') {
            $this->assertNotEmpty($rows);
            $this->assertSame(true, \in_array([313, 424], $alicePairs, true));
            foreach ($rows as $row) {
                $decoded = $this->decodeJoinData($row);
                $this->assertSame('Alice', $decoded['name'] ?? null);
                $this->assertSame($data['aliceId'], $row['_id'] ?? null);
                $this->assertSame(313, (int) $decoded['alpha.amount']);
                $this->assertSame(424, (int) $decoded['beta.amount']);
            }
            $this->assertJoinHardcoreClientHidden($listed, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $hiddenOnly = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$joins,
            Query::or([
                Query::equal('alpha.amount', [8686]),
                Query::equal('beta.label', ['combo-hard-alpha']),
            ])->toString(),
            Query::select(['name', 'alpha.amount', 'beta.amount', 'beta.label'])->toString(),
        ]);

        $this->assertSame(200, $hiddenOnly['headers']['status-code']);
        $hiddenRows = $this->joinHardcoreRows($hiddenOnly);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey('errors', $hiddenOnly['body']);
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, $this->joinHardcoreTotal($hiddenOnly));
            $this->assertJoinHardcoreClientHidden($hiddenOnly, $hiddenRows, $this->joinComboAmounts($hiddenRows));
        }
    }

    public function testJoinHardcoreLeftOnVsInnerWhereVsFojNull(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $left = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::select(['name', 'sec.secret', 'sec.amount'])->toString(),
        ]);
        $this->assertSame(200, $left['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $left['body']);
        $leftRows = $this->joinHardcoreRows($left);
        $this->assertNotEmpty($leftRows);
        $leftAmounts = $this->joinComboAmounts($leftRows);
        $aliceSeen = false;

        foreach ($leftRows as $row) {
            $decoded = $this->decodeJoinData($row);
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            if (($decoded['name'] ?? null) === 'Alice') {
                $aliceSeen = true;
                $this->assertSame($data['aliceId'], $row['_id'] ?? null);
                $secret = $decoded['sec.secret'] ?? $decoded['secret'] ?? null;
                if ($this->getSide() === 'client') {
                    $this->assertSame(true, $secret === null || $secret === '');
                }
            }
        }

        $this->assertSame(true, $aliceSeen);

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($left, $leftRows, $leftAmounts);
        }

        $inner = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::equal('sec.amount', [8686])->toString(),
            Query::select(['name', 'sec.amount', 'sec.secret'])->toString(),
        ]);
        $this->assertSame(200, $inner['headers']['status-code']);
        $innerRows = $this->joinHardcoreRows($inner);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey('errors', $inner['body']);
            $this->assertSame(0, \count($innerRows));
            $this->assertSame(0, $this->joinHardcoreTotal($inner));
            $this->assertJoinHardcoreClientHidden($inner, $innerRows, $this->joinComboAmounts($innerRows));
        }

        $foj = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ]);
        $this->assertSame(200, $foj['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $foj['body']);
        $fojRows = $this->joinHardcoreRows($foj);
        $this->assertNotEmpty($fojRows);
        $fojAmounts = $this->joinComboAmounts($fojRows);

        foreach ($fojRows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['_id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(700, $fojAmounts, true));
            $this->assertJoinHardcoreClientHidden($foj, $fojRows, $fojAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $fojAmounts, true));
        }
    }

    public function testJoinHardcoreFojPlusSecondAliasCursorRemap(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();
        $orderQueries = [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::join($data['rightId'], '$id', 'customerId', '=', 'rt')->toString(),
            Query::orderAsc('ord.amount')->toString(),
            Query::select(['name', 'ord.$id', 'ord.amount', 'rt.$id', 'rt.tag'])->toString(),
        ];

        $ordered = $this->joinHardcoreList($data['databaseId'], $data['customersId'], $orderQueries);
        $this->assertSame(200, $ordered['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $ordered['body']);
        $rows = $this->joinHardcoreRows($ordered);
        $this->assertNotEmpty($rows);
        $amounts = $this->joinComboAmounts($rows);

        $sortedAmounts = [];
        foreach ($rows as $row) {
            $id = $this->joinHardcoreCursorId($row);
            $this->assertNotSame($data['order8686Id'], $id);
            $this->assertNotSame($data['order5151Id'], $id);
            $amount = $this->joinHardcoreField($row, 'amount');
            if (\is_numeric($amount)) {
                if ($sortedAmounts !== []) {
                    $this->assertSame(true, (int) $amount >= $sortedAmounts[\count($sortedAmounts) - 1]);
                }
                $sortedAmounts[] = (int) $amount;
            }
        }

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($ordered, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true) || \in_array(200, $amounts, true));
        }

        $cursorIndex = null;
        $cursorRow = null;
        foreach ($rows as $index => $row) {
            $id = $this->joinHardcoreCursorId($row);
            $amount = $this->joinHardcoreField($row, 'amount');
            if ($id !== '' && \is_numeric($amount)) {
                $cursorIndex = $index;
                $cursorRow = $row;
                break;
            }
        }

        $this->assertNotNull($cursorRow);
        $this->assertSame(true, \is_int($cursorIndex));
        $cursorId = $this->joinHardcoreCursorId($cursorRow);
        $this->assertNotSame('', $cursorId);
        $this->assertSame(true, \is_numeric($this->joinHardcoreField($cursorRow, 'amount')));
        $this->assertNotSame($data['order8686Id'], $cursorId);
        $this->assertNotSame($data['order5151Id'], $cursorId);

        $after = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
            Query::limit(1)->toString(),
        ]);

        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $after['body']);
        $afterRows = $this->joinHardcoreRows($after);
        $this->assertSame(1, \count($afterRows));
        $afterId = $this->joinHardcoreCursorId($afterRows[0]);
        if ($afterId !== '') {
            $this->assertNotSame($cursorId, $afterId);
        }
        $this->assertNotSame($data['order8686Id'], $afterId);
        $this->assertNotSame($data['order5151Id'], $afterId);

        $expectedAmount = null;
        foreach (\array_slice($rows, $cursorIndex + 1) as $row) {
            $amount = $this->joinHardcoreField($row, 'amount');
            if (\is_numeric($amount)) {
                $expectedAmount = (int) $amount;
                break;
            }
        }

        $afterAmount = $this->joinHardcoreField($afterRows[0], 'amount');
        if ($expectedAmount !== null && \is_numeric($afterAmount)) {
            $this->assertSame($expectedAmount, (int) $afterAmount);
        }

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($after, $afterRows, $this->joinComboAmounts($afterRows));
        }
    }

    public function testJoinHardcoreGetDocumentSelectDottedJoinInternals(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $got = $this->joinHardcoreGet($data['databaseId'], $data['customersId'], $data['aliceId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.amount', 'ord.$id', 'ord.$permissions'])->toString(),
        ]);

        $this->assertSame(200, $got['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $got['body']);
        $record = $this->joinGetRecord($got);
        $decoded = $this->decodeJoinData($record);
        $this->assertSame($data['aliceId'], $record['_id']);
        $this->assertSame('Alice', $decoded['name'] ?? null);
        $this->assertArrayHasKey('ord.$id', $decoded);
        $this->assertArrayHasKey('ord.$permissions', $decoded);

        $orderId = $decoded['ord.$id'];
        $this->assertContains($orderId, [$data['order200Id'], $data['order313Id'], $data['order8686Id']], 'the joined order is one of Alice\'s');
        $order = $this->joinHardcoreGet($data['databaseId'], $data['ordersId'], $orderId, []);
        $this->assertArrayNotHasKey('errors', $order['body'], 'the joined order is one the caller can read directly');
        $this->assertSame($this->joinGetRecord($order)['_permissions'] ?? null, $decoded['ord.$permissions'], 'a joined row exposes exactly the permissions a direct read of it exposes');

        if ($this->getSide() === 'client') {
            $this->assertContains($orderId, [$data['order200Id'], $data['order313Id']]);
            $this->assertJoinHardcoreClientHidden($got, [$record], $this->joinComboAmounts([$record]));
        }

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['midId'], '$id', 'customerId', '=', 'mid')->toString(),
            Query::select(['name', 'mid.$id', 'mid.note', 'mid.$permissions'])->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $listed['body']);
        $rows = \array_map($this->decodeJoinData(...), $this->joinHardcoreRows($listed));
        $this->assertCount(3, $rows, 'the collection has no document security, so every customer\'s row is joined whatever its document permissions');
        foreach ($rows as $row) {
            $this->assertSame([Permission::read(Role::user('jh-mid-hidden'))], $row['mid.$permissions'] ?? null);
            $mid = $this->joinHardcoreGet($data['databaseId'], $data['midId'], $row['mid.$id'], []);
            $this->assertArrayNotHasKey('errors', $mid['body']);
            $this->assertSame($this->joinGetRecord($mid)['_permissions'] ?? null, $row['mid.$permissions'], 'the join exposes no more than a direct read of the same row');
        }
    }

    public function testJoinHardcoreSelectedJoinSequencesBelongToReadableRows(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $joined = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.$id', 'ord.$sequence'])->toString(),
        ]);
        $direct = $this->client->call(Client::METHOD_GET, $this->joinRecordUrl($data['databaseId'], $data['ordersId']), \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(100)->toString()],
        ]);

        $this->assertArrayNotHasKey('errors', $joined['body']);
        $this->assertSame(200, $direct['headers']['status-code']);
        $readable = [];
        foreach ($direct['body'][$this->joinItemsKey()] as $order) {
            $readable[$order['$id']] = (string) $order['$sequence'];
        }

        $joinedSequences = [];
        foreach ($this->joinHardcoreRows($joined) as $row) {
            $decoded = $this->decodeJoinData($row);
            $this->assertArrayNotHasKey('ord.$tenant', $decoded);
            $orderId = $decoded['ord.$id'] ?? null;
            if ($orderId === null || $orderId === '') {
                continue;
            }
            $this->assertArrayHasKey($orderId, $readable, 'every joined order is one the caller can list directly');
            $this->assertSame($readable[$orderId], (string) $decoded['ord.$sequence'], 'a joined sequence belongs to the joined row it came with');
            $joinedSequences[] = (string) $decoded['ord.$sequence'];
        }

        $this->assertNotEmpty($joinedSequences);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey($data['order8686Id'], $readable, 'the caller cannot list the hidden order, so no joined sequence can be its');
            $this->assertArrayNotHasKey($data['order5151Id'], $readable);
        } else {
            $this->assertArrayHasKey($data['order8686Id'], $readable);
            $this->assertContains($readable[$data['order8686Id']], $joinedSequences, 'a privileged caller joins the hidden order too');
        }
    }

    public function testJoinHardcoreCountSumFojExcludesSecret(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $listed['body']);
        $rows = $this->joinHardcoreRows($listed);
        $this->assertNotEmpty($rows);
        $this->assertSame(\count($rows), $this->graphqlTotal($listed));
        $amounts = $this->joinComboAmounts($rows);

        foreach ($rows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['_id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame(true, \in_array(700, $amounts, true));
            $this->assertJoinHardcoreClientHidden($listed, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $hidden = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::equal('ord.amount', [8686])->toString(),
            Query::select(['name', 'ord.amount'])->toString(),
        ]);

        $this->assertSame(200, $hidden['headers']['status-code']);
        $hiddenRows = $this->joinHardcoreRows($hidden);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey('errors', $hidden['body']);
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, $this->joinHardcoreTotal($hidden));
            $this->assertJoinHardcoreClientHidden($hidden, $hiddenRows, $this->joinComboAmounts($hiddenRows));
        }

        $customers = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [Query::limit(100)->toString()]);
        $orders = $this->joinHardcoreList($data['databaseId'], $data['ordersId'], [Query::limit(100)->toString()]);
        $this->assertArrayNotHasKey('errors', $customers['body']);
        $this->assertArrayNotHasKey('errors', $orders['body']);
        if ($this->getSide() === 'client') {
            $this->assertNotContains($data['order8686Id'], \array_column($this->joinHardcoreRows($orders), '_id'), 'Order 8686 is readable only by combo-hard-hidden');
            $this->assertNotContains($data['order5151Id'], \array_column($this->joinHardcoreRows($orders), '_id'), 'Order 5151 is readable only by combo-hard-hidden');
        }

        $pairs = Join::FullOuter->pairs(
            \array_map(
                fn (array $record): Customer => new Customer($record['_id'], $this->decodeJoinData($record)['name'], false),
                $this->joinHardcoreRows($customers),
            ),
            \array_map(
                function (array $record): Order {
                    $order = $this->decodeJoinData($record);

                    return new Order($record['_id'], $order['customerId'] ?? null, $order['amount'], $order['label'], 0, false);
                },
                $this->joinHardcoreRows($orders),
            ),
        );
        $expected = Summary::of($pairs);

        $aggregated = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::count('*', 'rowCount')->toString(),
            Query::count('ord.$id', 'orderCount')->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $aggregated['body']);
        $aggregates = $this->graphqlRows($aggregated);
        $this->assertCount(1, $aggregates);
        $this->assertSame($expected->rows, $this->aggregateInteger($aggregates[0], 'rowCount'));
        $this->assertSame($expected->orders, $this->aggregateInteger($aggregates[0], 'orderCount'));
        $this->assertSame($expected->sum, $this->aggregateInteger($aggregates[0], 'amountSum'));
    }

    public function testJoinHardcoreIsNotNullNotEqualSecretDoesNotLeak(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $notNull = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::isNotNull('sec.secret')->toString(),
            Query::select(['name', 'sec.secret', 'sec.amount', 'sec.payload'])->toString(),
        ]);
        $this->assertSame(200, $notNull['headers']['status-code']);
        $notNullRows = $this->joinHardcoreRows($notNull);

        if ($this->getSide() === 'client') {
            $this->assertArrayNotHasKey('errors', $notNull['body']);
            $this->assertSame(0, \count($notNullRows));
            $this->assertSame(0, $this->joinHardcoreTotal($notNull));
            $this->assertJoinHardcoreClientHidden($notNull, $notNullRows, $this->joinComboAmounts($notNullRows));
        }

        $notEqual = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::notEqual('ord.amount', 8686)->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ]);
        $this->assertSame(200, $notEqual['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $notEqual['body']);
        $notEqualRows = $this->joinHardcoreRows($notEqual);
        $this->assertNotEmpty($notEqualRows);
        $notEqualAmounts = $this->joinComboAmounts($notEqualRows);

        foreach ($notEqualRows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['_id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['_id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            foreach ([200, 313, 424, 100] as $visible) {
                $this->assertSame(true, \in_array($visible, $notEqualAmounts, true));
            }
            $this->assertJoinHardcoreClientHidden($notEqual, $notEqualRows, $notEqualAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $notEqualAmounts, true));
        }

        $notContainsQuery = null;
        if (\method_exists(Query::class, 'notContainsString')) {
            $notContainsQuery = Query::notContainsString('ord.label', 'combo-hard-alpha')->toString();
        } elseif (\method_exists(Query::class, 'notContains')) {
            $notContainsQuery = Query::notContains('ord.label', ['combo-hard-alpha'])->toString();
        }

        if ($notContainsQuery !== null) {
            $notContains = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
                Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                $notContainsQuery,
                Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
            ]);
            $this->assertSame(200, $notContains['headers']['status-code']);
            $notContainsRows = $this->joinHardcoreRows($notContains);

            if ($this->getSide() === 'client') {
                $this->assertArrayNotHasKey('errors', $notContains['body']);
                $this->assertJoinHardcoreClientHidden($notContains, $notContainsRows, $this->joinComboAmounts($notContainsRows));
            }
        }
    }

    /**
     * @var array<string, Fixture>
     */
    private static array $restrictedOrdersFixtures = [];

    /**
     * @var array<string, string>
     */
    private static array $aggregateDatabases = [];

    public function testInnerJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertGraphQLJoinMatchesReadableRows(Join::Inner);
    }

    public function testLeftJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertGraphQLJoinMatchesReadableRows(Join::Left);
    }

    public function testRightJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertGraphQLJoinMatchesReadableRows(Join::Right);
    }

    public function testCrossJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertGraphQLJoinMatchesReadableRows(Join::Cross);
    }

    public function testFullOuterJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertGraphQLJoinMatchesReadableRows(Join::FullOuter);
    }

    public function testJoinLimitAcceptsEightJoinsAndRejectsNine(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $readable = \count($this->seedVisibleToCaller()->customers);

        $eight = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
            ...$this->selfJoins($fixture->customersId, 8),
            Query::select(['name'])->toString(),
            Query::limit(100)->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $eight['body'], 'Eight joins are within the limit');
        $this->assertCount($readable, $this->graphqlRows($eight));
        $this->assertSame($readable, $this->graphqlTotal($eight));

        $nine = [
            ...$this->selfJoins($fixture->customersId, 9),
            Query::select(['name'])->toString(),
        ];

        $this->assertRejectedLikeRest(
            $this->graphqlRecords($fixture->databaseId, $fixture->customersId, $nine),
            $this->restRecords($fixture->databaseId, $fixture->customersId, $nine),
            'A ninth join exceeds the limit',
        );
    }

    public function testBareAggregateAttributeDeclaredByTwoJoinsRejected(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $orders = Query::join($fixture->ordersId, '$id', 'customerId', '=', 'ord')->toString();
        $payments = Query::join($fixture->paymentsId, 'ord.$id', 'orderId', '=', 'pay')->toString();

        $qualified = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
            $orders,
            $payments,
            Query::count('*', 'rowCount')->toString(),
            Query::sum('ord.amount', 'orderTotal')->toString(),
            Query::sum('pay.amount', 'paymentTotal')->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $qualified['body']);
        $row = $this->graphqlRows($qualified)[0];
        $this->assertSame(\count($seed->joinedPayments()), $this->aggregateInteger($row, 'rowCount'));
        $this->assertSame($seed->joinedOrderTotal(), $this->aggregateInteger($row, 'orderTotal'));
        $this->assertSame($seed->joinedPaymentTotal(), $this->aggregateInteger($row, 'paymentTotal'));

        $ambiguous = [
            [Query::sum('amount', 'total')->toString()],
            [Query::count('*', 'rowCount')->toString(), Query::groupBy(['amount'])->toString()],
        ];

        foreach ($ambiguous as $queries) {
            $this->assertRejectedLikeRest(
                $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [$orders, $payments, ...$queries]),
                $this->restRecords($fixture->databaseId, $fixture->customersId, [$orders, $payments, ...$queries]),
                'Both joined collections declare amount',
            );
        }
    }

    public function testBareAggregateAttributeResolvesToTheOnlyJoinDeclaringIt(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $expected = Summary::of(Join::Inner->pairs($seed->customers, $seed->orders))->sum;
        $orders = Query::join($fixture->ordersId, '$id', 'customerId', '=', 'ord')->toString();

        foreach (['amount', 'ord.amount'] as $attribute) {
            $summed = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
                $orders,
                Query::sum($attribute, 'total')->toString(),
            ]);

            $this->assertArrayNotHasKey('errors', $summed['body'], "sum('{$attribute}')");
            $this->assertSame($expected, $this->aggregateInteger($this->graphqlRows($summed)[0], 'total'), "sum('{$attribute}')");
        }

        $undeclared = [$orders, Query::sum('undeclared', 'total')->toString()];

        $this->assertRejectedLikeRest(
            $this->graphqlRecords($fixture->databaseId, $fixture->customersId, $undeclared),
            $this->restRecords($fixture->databaseId, $fixture->customersId, $undeclared),
            'No collection in the query declares the attribute',
        );
    }

    public function testJoinWithoutSelectReturnsMainAttributesAndAliasedJoinedAttributes(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $join = Query::join($fixture->ordersId, '$id', 'customerId', '=', 'ord')->toString();
        $joinedKeys = ['ord.$id', 'ord.customerId', 'ord.amount', 'ord.label', 'ord.flags', 'ord.scores'];
        $readableOrderIds = \array_map(static fn (Order $order): string => $order->id, $seed->orders);

        $direct = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [Query::limit(100)->toString()]);
        $this->assertArrayNotHasKey('errors', $direct['body']);
        $mainKeys = [];
        foreach ($this->joinListRecords($direct) as $record) {
            $mainKeys[$record['_id']] = \array_keys($this->decodeJoinData($record));
        }

        $listed = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [$join, Query::limit(100)->toString()]);
        $this->assertArrayNotHasKey('errors', $listed['body']);
        $records = $this->joinListRecords($listed);
        $this->assertCount(\count(Join::Inner->pairs($seed->customers, $seed->orders)), $records);

        foreach ($records as $record) {
            $data = $this->decodeJoinData($record);
            $this->assertArrayHasKey($record['_id'], $mainKeys);
            $this->assertSame($this->sortedStrings([...$mainKeys[$record['_id']], ...$joinedKeys]), $this->sortedStrings(\array_keys($data)));
            $this->assertSame($record['_id'], $data['ord.customerId']);
            $this->assertContains($data['ord.$id'], $readableOrderIds);
            $this->assertJoinedOrderValues($seed->order($data['ord.$id']), $data);
        }

        $got = $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($fixture->databaseId, $fixture->customersId, 'alice', [$join]));
        $this->assertArrayNotHasKey('errors', $got['body']);
        $data = $this->decodeJoinData($this->joinGetRecord($got));
        $this->assertSame($this->sortedStrings([...$mainKeys['alice'], ...$joinedKeys]), $this->sortedStrings(\array_keys($data)));
        $this->assertSame('alice', $data['ord.customerId']);
        $this->assertContains($data['ord.$id'], $readableOrderIds);
        $this->assertJoinedOrderValues($seed->order($data['ord.$id']), $data);
    }

    public function testBulkUpdateWithJoinQueryRejected(): void
    {
        $databaseId = $this->setupAggregateDatabase();
        $notesId = $this->createBulkNotes($databaseId);
        $peersId = $this->createSeedContainer($databaseId, 'bulkPeers' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        foreach ([$peersId, Database::METADATA] as $joined) {
            $queries = [Query::leftJoin($joined, '$id', '$id', '=', 'peer')->toString()];

            $rest = $this->client->call(Client::METHOD_PATCH, $this->joinRecordUrl($databaseId, $notesId), $this->joinServerHeaders(), [
                'data' => ['note' => 'rewritten'],
                'queries' => $queries,
            ]);
            $graphql = $this->graphqlJoinWithKey($this->getQuery($this->isTablesDB() ? self::UPDATE_ROWS : self::UPDATE_DOCUMENTS), [
                'databaseId' => $databaseId,
                $this->joinContainerIdParam() => $notesId,
                'data' => ['note' => 'rewritten'],
                'queries' => $queries,
            ], $this->getProject()['apiKey']);

            $this->assertRejectedLikeRest($graphql, $rest, "A bulk update joining '{$joined}' must be rejected");
        }

        $this->assertBulkNotesUntouched($databaseId, $notesId);
    }

    public function testBulkDeleteWithJoinQueryRejected(): void
    {
        $databaseId = $this->setupAggregateDatabase();
        $notesId = $this->createBulkNotes($databaseId);
        $peersId = $this->createSeedContainer($databaseId, 'bulkPeers' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        foreach ([$peersId, Database::METADATA] as $joined) {
            $queries = [Query::leftJoin($joined, '$id', '$id', '=', 'peer')->toString()];

            $rest = $this->client->call(Client::METHOD_DELETE, $this->joinRecordUrl($databaseId, $notesId), $this->joinServerHeaders(), [
                'queries' => $queries,
            ]);
            $graphql = $this->graphqlJoinWithKey($this->getQuery($this->isTablesDB() ? self::DELETE_ROWS : self::DELETE_DOCUMENTS), [
                'databaseId' => $databaseId,
                $this->joinContainerIdParam() => $notesId,
                'queries' => $queries,
            ], $this->getProject()['apiKey']);

            $this->assertRejectedLikeRest($graphql, $rest, "A bulk delete joining '{$joined}' must be rejected");
        }

        $this->assertBulkNotesUntouched($databaseId, $notesId);
    }

    public function testStatisticalAggregatesComputeOverReadableRowsOnly(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();

        $result = $this->graphqlRecords($fixture->databaseId, $fixture->ordersId, \array_map(
            static fn (Statistic $statistic): string => $statistic->query()->toString(),
            Statistic::cases(),
        ));

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->graphqlRows($result);
        $this->assertCount(1, $rows);

        foreach (Statistic::cases() as $statistic) {
            $this->assertStatistic($statistic->expected($seed), $statistic, $rows[0]);
        }
    }

    public function testAggregatesOverAnEmptySetReturnZeroCountsAndNulls(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();

        $result = $this->graphqlRecords($fixture->databaseId, $fixture->ordersId, [
            Query::equal('label', ['nonexistent'])->toString(),
            ...\array_map(static fn (Statistic $statistic): string => $statistic->query()->toString(), Statistic::cases()),
        ]);

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->graphqlRows($result);
        $this->assertCount(1, $rows);

        foreach (Statistic::cases() as $statistic) {
            $this->assertStatistic($statistic->overEmptySet(), $statistic, $rows[0]);
        }
    }

    public function testNumericAggregatesRejectStringAndArrayAttributes(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();

        foreach (['label', 'scores'] as $attribute) {
            foreach (Statistic::cases() as $statistic) {
                if (!$statistic->requiresNumber()) {
                    continue;
                }

                $queries = [$statistic->on($attribute)->toString()];
                $this->assertRejectedLikeRest(
                    $this->graphqlRecords($fixture->databaseId, $fixture->ordersId, $queries),
                    $this->restRecords($fixture->databaseId, $fixture->ordersId, $queries),
                    "{$statistic->method()->value}('{$attribute}')",
                );
            }
        }

        $labels = $this->seedVisibleToCaller()->labels();
        $accepted = $this->graphqlRecords($fixture->databaseId, $fixture->ordersId, [
            Query::count('label', 'labelTotal')->toString(),
            Query::min('label', 'firstLabel')->toString(),
            Query::max('label', 'lastLabel')->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $accepted['body']);
        $row = $this->graphqlRows($accepted)[0];
        $this->assertSame(\count($labels), $this->aggregateInteger($row, 'labelTotal'));
        $this->assertSame(\min($labels), $row['firstLabel'] ?? null);
        $this->assertSame(\max($labels), $row['lastLabel'] ?? null);
    }

    public function testJoinQueriesRejectedWhereAdapterLacksJoins(): void
    {
        if ($this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter supports join queries');
        }

        $databaseId = $this->setupAggregateDatabase();
        $probesId = $this->createProbeContainer($databaseId);
        $peersId = $this->createSeedContainer($databaseId, 'probePeers' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);
        $queries = [Query::leftJoin($peersId, '$id', '$id', '=', 'peer')->toString()];

        $plain = $this->graphqlRecords($databaseId, $probesId, []);
        $this->assertArrayNotHasKey('errors', $plain['body']);
        $this->assertSame(['probe'], \array_column($this->joinListRecords($plain), '_id'));

        $this->assertRejectedLikeRest(
            $this->graphqlRecords($databaseId, $probesId, $queries),
            $this->restRecords($databaseId, $probesId, $queries),
            'A join on an adapter without joins',
        );

        $this->assertRejectedLikeRest(
            $this->graphqlJoin($this->joinGetQuery(), $this->joinGetVariables($databaseId, $probesId, 'probe', $queries)),
            $this->client->call(Client::METHOD_GET, $this->joinRecordUrl($databaseId, $probesId, 'probe'), $this->restHeaders(), ['queries' => $queries]),
            'A join on a single record on an adapter without joins',
        );
    }

    public function testAggregateQueriesRejectedWhereAdapterLacksAggregations(): void
    {
        if ($this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter supports aggregation queries');
        }

        $databaseId = $this->setupAggregateDatabase();
        $probesId = $this->createProbeContainer($databaseId);

        $plain = $this->graphqlRecords($databaseId, $probesId, []);
        $this->assertArrayNotHasKey('errors', $plain['body']);
        $this->assertSame(['probe'], \array_column($this->joinListRecords($plain), '_id'));

        $aggregations = [
            [Query::count('*', 'rowCount')->toString()],
            [Query::count('*', 'rowCount')->toString(), Query::groupBy(['$id'])->toString()],
        ];

        foreach ($aggregations as $queries) {
            $this->assertRejectedLikeRest(
                $this->graphqlRecords($databaseId, $probesId, $queries),
                $this->restRecords($databaseId, $probesId, $queries),
                'An aggregation on an adapter without aggregations',
            );
        }
    }

    protected function setupAggregateDatabase(): string
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (isset(self::$aggregateDatabases[$cacheKey])) {
            return self::$aggregateDatabases[$cacheKey];
        }

        $database = $this->client->call(Client::METHOD_POST, $this->joinApiBase(), $this->joinServerHeaders(), [
            'databaseId' => ID::unique(),
            'name' => 'aggregateGraphQL',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);

        return self::$aggregateDatabases[$cacheKey] = $database['body']['$id'];
    }

    protected function setupRestrictedOrdersFixture(): Fixture
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (isset(self::$restrictedOrdersFixtures[$cacheKey])) {
            return self::$restrictedOrdersFixtures[$cacheKey];
        }

        $databaseId = $this->setupAggregateDatabase();
        $suffix = ID::unique();
        $documentLevel = [Permission::create(Role::any())];

        $fixture = new Fixture(
            databaseId: $databaseId,
            customersId: $this->createSeedContainer($databaseId, 'roCustomers' . $suffix, true, $documentLevel),
            ordersId: $this->createSeedContainer($databaseId, 'roOrders' . $suffix, true, $documentLevel),
            paymentsId: $this->createSeedContainer($databaseId, 'roPayments' . $suffix, false, [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ]),
        );

        $attributes = [
            [$fixture->customersId, 'string', ['key' => 'name', 'size' => 64, 'required' => true]],
            [$fixture->ordersId, 'string', ['key' => 'customerId', 'size' => 36, 'required' => false]],
            [$fixture->ordersId, 'integer', ['key' => 'amount', 'required' => true]],
            [$fixture->ordersId, 'string', ['key' => 'label', 'size' => 32, 'required' => true]],
            [$fixture->ordersId, 'integer', ['key' => 'flags', 'required' => true]],
            [$fixture->ordersId, 'integer', ['key' => 'scores', 'required' => false, 'array' => true]],
            [$fixture->paymentsId, 'string', ['key' => 'orderId', 'size' => 36, 'required' => true]],
            [$fixture->paymentsId, 'integer', ['key' => 'amount', 'required' => true]],
        ];

        foreach ($attributes as [$containerId, $type, $payload]) {
            $created = $this->createJoinAttribute($databaseId, $containerId, $type, $payload);
            $this->assertSame(202, $created['headers']['status-code']);
        }

        foreach ($attributes as [$containerId, , $payload]) {
            $this->waitForJoinAttribute($databaseId, $containerId, $payload['key']);
        }

        $seed = Seed::create();

        foreach ($seed->customers as $customer) {
            $this->createSeedRecord($databaseId, $fixture->customersId, $customer->id, [
                'name' => $customer->name,
            ], $this->seedPermissions($customer->hidden));
        }

        foreach ($seed->orders as $order) {
            $this->createSeedRecord($databaseId, $fixture->ordersId, $order->id, [
                'customerId' => $order->customerId,
                'amount' => $order->amount,
                'label' => $order->label,
                'flags' => $order->flags,
                'scores' => [$order->flags, $order->amount],
            ], $this->seedPermissions($order->hidden));
        }

        foreach ($seed->payments as $payment) {
            $this->createSeedRecord($databaseId, $fixture->paymentsId, $payment->id, [
                'orderId' => $payment->orderId,
                'amount' => $payment->amount,
            ], $this->seedPermissions(false));
        }

        return self::$restrictedOrdersFixtures[$cacheKey] = $fixture;
    }

    protected function seedVisibleToCaller(): Seed
    {
        return Seed::create()->visibleTo($this->getSide() === 'client');
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function graphqlRecords(string $databaseId, string $containerId, array $queries): array
    {
        return $this->graphqlJoin($this->joinListQuery(), $this->joinListVariables($databaseId, $containerId, $queries));
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    protected function graphqlRows(array $result): array
    {
        return \array_map(fn (array $record): array => $this->decodeJoinData($record), $this->joinListRecords($result));
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function graphqlTotal(array $result): mixed
    {
        return $result['body']['data'][$this->joinListField()]['total'] ?? null;
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function restRecords(string $databaseId, string $containerId, array $queries): array
    {
        return $this->client->call(Client::METHOD_GET, $this->joinRecordUrl($databaseId, $containerId), $this->restHeaders(), [
            'queries' => $queries,
        ]);
    }

    /**
     * GraphQL reports only the message of the REST error it wraps, so a rejection is pinned by the REST
     * response to the same request and a GraphQL error carrying exactly its message.
     *
     * @param array<string, mixed> $graphql
     * @param array<string, mixed> $rest
     */
    private function assertRejectedLikeRest(array $graphql, array $rest, string $message): void
    {
        $this->assertSame(400, $rest['headers']['status-code'], $message);
        $this->assertSame('general_query_invalid', $rest['body']['type'], $message);
        $this->assertArrayHasKey('errors', $graphql['body'], $message);
        $this->assertSame($rest['body']['message'], $graphql['body']['errors'][0]['message'] ?? null, $message);
    }

    private function assertGraphQLJoinMatchesReadableRows(Join $join): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $this->assertGraphQLDirectReadsMatchSeed($fixture, $seed);

        $pairs = $join->pairs($seed->customers, $seed->orders);
        $joinQuery = $join->query($fixture->ordersId, 'ord')->toString();

        $listed = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::select(['name', 'ord.amount'])->toString(),
            Query::limit(100)->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $listed['body']);
        $this->assertPairRows($pairs, $this->graphqlRows($listed), "Rows of the {$join->name} join");
        $this->assertSame(\count($pairs), $this->graphqlTotal($listed), "Total of the {$join->name} join");

        $aggregated = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::count('*', 'rowCount')->toString(),
            Query::count('ord.$id', 'orderCount')->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
            Query::min('ord.amount', 'amountMinimum')->toString(),
            Query::max('ord.amount', 'amountMaximum')->toString(),
            Query::avg('ord.amount', 'amountAverage')->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $aggregated['body']);
        $rows = $this->graphqlRows($aggregated);
        $this->assertCount(1, $rows);
        $this->assertSummaryRow(Summary::of($pairs), $rows[0], "Aggregates of the {$join->name} join");

        $grouped = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::groupBy(['ord.label'])->toString(),
            Query::count('*', 'rowCount')->toString(),
            Query::count('ord.$id', 'orderCount')->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $grouped['body']);
        $this->assertGroupRows($pairs, $this->graphqlRows($grouped), "Groups of the {$join->name} join");

        $filtered = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::groupBy(['ord.label'])->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
            Query::having([Query::greaterThan('amountSum', Seed::GROUP_THRESHOLD)])->toString(),
        ]);

        $this->assertArrayNotHasKey('errors', $filtered['body']);
        $this->assertGroupLabels(Summary::labelsAbove($pairs, Seed::GROUP_THRESHOLD), $this->graphqlRows($filtered), "Groups of the {$join->name} join above the having threshold");
    }

    private function assertGraphQLDirectReadsMatchSeed(Fixture $fixture, Seed $seed): void
    {
        $customers = $this->graphqlRecords($fixture->databaseId, $fixture->customersId, [Query::limit(100)->toString()]);
        $this->assertArrayNotHasKey('errors', $customers['body']);
        $this->assertSame(
            $this->sortedStrings(\array_map(static fn (Customer $customer): string => $customer->id, $seed->customers)),
            $this->sortedStrings(\array_column($this->joinListRecords($customers), '_id')),
            'Customers a direct read returns',
        );

        $orders = $this->graphqlRecords($fixture->databaseId, $fixture->ordersId, [Query::limit(100)->toString()]);
        $this->assertArrayNotHasKey('errors', $orders['body']);
        $this->assertSame(
            $this->sortedStrings(\array_map(static fn (Order $order): string => $order->id, $seed->orders)),
            $this->sortedStrings(\array_column($this->joinListRecords($orders), '_id')),
            'Orders a direct read returns',
        );
    }

    /**
     * @return list<string>
     */
    private function selfJoins(string $collectionId, int $count): array
    {
        return \array_map(
            static fn (int $index): string => Query::join($collectionId, '$id', '$id', '=', 'peer' . $index)->toString(),
            \range(1, $count),
        );
    }

    private function createBulkNotes(string $databaseId): string
    {
        $notesId = $this->createSeedContainer($databaseId, 'bulkNotes' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        if ($this->getSupportForAttributes()) {
            $created = $this->createJoinAttribute($databaseId, $notesId, 'string', ['key' => 'note', 'size' => 32, 'required' => true]);
            $this->assertSame(202, $created['headers']['status-code']);
            $this->waitForJoinAttribute($databaseId, $notesId, 'note');
        }

        foreach (['n1', 'n2'] as $noteId) {
            $this->createSeedRecord($databaseId, $notesId, $noteId, ['note' => 'original'], [Permission::read(Role::any())]);
        }

        return $notesId;
    }

    private function assertBulkNotesUntouched(string $databaseId, string $notesId): void
    {
        $listed = $this->client->call(Client::METHOD_GET, $this->joinRecordUrl($databaseId, $notesId), $this->joinServerHeaders(), [
            'queries' => [Query::limit(100)->toString()],
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $notes = \array_column($listed['body'][$this->joinItemsKey()], 'note', '$id');
        \ksort($notes);
        $this->assertSame(['n1' => 'original', 'n2' => 'original'], $notes);
    }

    private function createProbeContainer(string $databaseId): string
    {
        $probesId = $this->createSeedContainer($databaseId, 'probes' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);
        $created = $this->createJoinAttribute($databaseId, $probesId, 'string', ['key' => 'name', 'size' => 32, 'required' => false]);
        $this->assertSame(202, $created['headers']['status-code']);
        $this->waitForJoinAttribute($databaseId, $probesId, 'name');
        $this->createSeedRecord($databaseId, $probesId, 'probe', ['name' => 'probe'], [Permission::read(Role::any())]);

        return $probesId;
    }

    /**
     * @param list<string> $permissions
     */
    private function createSeedContainer(string $databaseId, string $name, bool $documentSecurity, array $permissions): string
    {
        $created = $this->client->call(Client::METHOD_POST, $this->joinContainerUrl($databaseId), $this->joinServerHeaders(), [
            $this->joinContainerIdParam() => ID::unique(),
            'name' => $name,
            $this->joinSecurityParam() => $documentSecurity,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        return $created['body']['$id'];
    }

    /**
     * @param array<string, mixed>|\stdClass $data
     * @param list<string> $permissions
     */
    private function createSeedRecord(string $databaseId, string $containerId, string $recordId, array|\stdClass $data, array $permissions): void
    {
        $created = $this->client->call(Client::METHOD_POST, $this->joinRecordUrl($databaseId, $containerId), $this->joinServerHeaders(), [
            $this->joinRecordIdParam() => $recordId,
            'data' => $data,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
    }

    /**
     * @return list<string>
     */
    private function seedPermissions(bool $hidden): array
    {
        return $hidden
            ? [Permission::read(Role::user(Seed::HIDDEN_USER))]
            : [Permission::read(Role::any())];
    }

    /**
     * @return array<string, string>
     */
    private function restHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }
}
