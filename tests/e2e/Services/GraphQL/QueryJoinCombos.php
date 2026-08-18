<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait QueryJoinCombos
{
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
                Permission::read(Role::any()),
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
            '$sequence',
            '$createdAt',
            '$updatedAt',
            '$tenant',
            '$version',
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

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinComboClientHidden($result, $rows, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
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
        $ordersId = $this->createJoinHardcoreContainer($databaseId, 'jhOrders' . $suffix, true, $any);
        $midId = $this->createJoinHardcoreContainer($databaseId, 'jhMid' . $suffix, false, $any);
        $secretsId = $this->createJoinHardcoreContainer($databaseId, 'jhSecrets' . $suffix, true, $any);
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

    protected function jsonContainsExactString(mixed $value, string $needle, string|int|null $key = null): bool
    {
        if (\is_string($value)) {
            if ($this->isIgnoredJoinSecretKey($key)) {
                return false;
            }

            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return $this->jsonContainsExactString($decoded, $needle);
            }

            return $value === $needle;
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $childKey => $child) {
            if ($this->jsonContainsExactString($child, $needle, $childKey)) {
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
            Query::contains('ord.label', ['visible'])->toString(),
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
            $this->assertArrayNotHasKey('errors', $result['body']);
            $this->assertSame(0, \count($rows));
            $this->assertSame(0, $this->joinHardcoreTotal($result));
            $this->assertJoinHardcoreClientHidden($result, $rows, $amounts);
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8686));
            $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '8686'));
            $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 5151));
            $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '5151'));
        }
    }
}
