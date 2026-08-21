<?php

namespace Tests\E2E\Services\Databases\Queries;

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
        $cacheKey = $this->getCacheKey();
        if (!empty(self::$joinComboCache[$cacheKey])) {
            return self::$joinComboCache[$cacheKey];
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
            'name' => 'jcCustomers' . $suffix,
            $this->getSecurityParam() => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $customers['headers']['status-code']);
        $customersId = $customers['body']['$id'];

        $public = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jcPublic' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $public['headers']['status-code']);
        $publicId = $public['body']['$id'];

        $secret = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => 'jcSecret' . $suffix,
            $this->getSecurityParam() => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertSame(201, $secret['headers']['status-code']);
        $secretId = $secret['body']['$id'];

        $this->createAttribute($databaseId, $customersId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $publicId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $publicId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);

        $this->waitForAttribute($databaseId, $customersId, 'name');
        $this->waitForAttribute($databaseId, $publicId, 'customerId');
        $this->waitForAttribute($databaseId, $publicId, 'amount');
        $this->waitForAttribute($databaseId, $secretId, 'customerId');
        $this->waitForAttribute($databaseId, $secretId, 'amount');
        $this->waitForAttribute($databaseId, $secretId, 'secret');

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

        $publicRow = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $publicId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => [
                'customerId' => $aliceId,
                'amount' => 313,
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $publicRow['headers']['status-code']);

        $secretRow = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $secretId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
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
            foreach ($row as $key => $value) {
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
            '$version',
            '$collection',
            '$distance',
            '$deletedAt',
            '$internalId',
            '$skipPermissionsUpdate',
        ], true);
    }

    protected function assertJoinComboClientHidden(string $encoded, array $amounts): void
    {
        $this->assertStringNotContainsString('combo-secret-alpha', $encoded);
        $this->assertStringNotContainsString('user:combo-hidden', $encoded);
        $this->assertSame(false, \in_array(777, $amounts, true));
        $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 777));
    }

    public function testJoinComboListLeftAndInnerOmitsSecret(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinComboFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $this->joinComboLeftAndInnerQueries($data, [
                Query::select(['name', 'pub.amount', 'sec.amount', 'sec.secret'])->toString(),
            ]),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = $this->joinComboAmounts($rows);
        $encoded = (string) \json_encode($result['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinComboClientHidden($encoded, $amounts);
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

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $this->joinComboLeftAndInnerQueries($data, [
                Query::select(['name', 'pub.amount', 'sec.amount', 'sec.secret'])->toString(),
            ]),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame($data['aliceId'], $result['body']['$id']);
        $amounts = $this->joinComboAmounts([$result['body']]);
        $encoded = (string) \json_encode($result['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinComboClientHidden($encoded, $amounts);
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

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $this->joinComboLeftAndInnerQueries($data, [
                Query::select(['name', 'pub.amount', 'sec.amount', 'sec.secret'])->toString(),
                Query::equal('sec.secret', ['combo-secret-alpha'])->toString(),
                Query::equal('sec.amount', [777])->toString(),
            ]),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()] ?? [];
        $this->assertSame(true, \is_array($rows));
        $amounts = $this->joinComboAmounts($rows);
        $encoded = (string) \json_encode($result['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($rows));
            $this->assertSame(0, (int) ($result['body']['total'] ?? 0));
            $this->assertJoinComboClientHidden($encoded, $amounts);
        }
    }

    public function testJoinComboListSelectPermissionsOmitsRole(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinComboFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $this->joinComboLeftAndInnerQueries($data, [
                Query::select(['name', 'pub.amount', 'sec.secret', 'sec.$permissions', 'pub.$permissions'])->toString(),
            ]),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $amounts = $this->joinComboAmounts($rows);
        $encoded = (string) \json_encode($result['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinComboClientHidden($encoded, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }
    }

    protected function setupJoinHardcoreFixture(): array
    {
        $cacheKey = $this->getCacheKey() . ':hardcore';
        if (!empty(self::$joinHardcoreCache[$cacheKey])) {
            return self::$joinHardcoreCache[$cacheKey];
        }

        $data = $this->setupDatabase();
        $databaseId = $data['databaseId'];
        $suffix = ID::unique();

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $any = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ];
        $readAny = [Permission::read(Role::any())];
        $hidden = [Permission::read(Role::user('combo-hard-hidden'))];
        $midHidden = [Permission::read(Role::user('jh-mid-hidden'))];

        $customersId = $this->createJoinHardcoreContainer($databaseId, $serverHeaders, 'jhCustomers' . $suffix, true, $any);
        $ordersId = $this->createJoinHardcoreContainer($databaseId, $serverHeaders, 'jhOrders' . $suffix, true, $any);
        $midId = $this->createJoinHardcoreContainer($databaseId, $serverHeaders, 'jhMid' . $suffix, false, $any);
        $secretsId = $this->createJoinHardcoreContainer($databaseId, $serverHeaders, 'jhSecrets' . $suffix, true, $any);
        $rightId = $this->createJoinHardcoreContainer($databaseId, $serverHeaders, 'jhRight' . $suffix, true, $any);

        $this->createAttribute($databaseId, $customersId, 'string', [
            'key' => 'name',
            'size' => 64,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $customersId, 'string', [
            'key' => 'code',
            'size' => 32,
            'required' => true,
        ]);
        $this->createAttribute($databaseId, $ordersId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $ordersId, 'string', [
            'key' => 'partnerCode',
            'size' => 32,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $ordersId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $ordersId, 'string', [
            'key' => 'label',
            'size' => 64,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $midId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $midId, 'string', [
            'key' => 'note',
            'size' => 64,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $midId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretsId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretsId, 'string', [
            'key' => 'midId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretsId, 'integer', [
            'key' => 'amount',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretsId, 'string', [
            'key' => 'secret',
            'size' => 128,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $secretsId, 'integer', [
            'key' => 'payload',
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $rightId, 'string', [
            'key' => 'customerId',
            'size' => 36,
            'required' => false,
        ]);
        $this->createAttribute($databaseId, $rightId, 'string', [
            'key' => 'tag',
            'size' => 32,
            'required' => false,
        ]);

        $this->waitForAttribute($databaseId, $customersId, 'name');
        $this->waitForAttribute($databaseId, $customersId, 'code');
        $this->waitForAttribute($databaseId, $ordersId, 'customerId');
        $this->waitForAttribute($databaseId, $ordersId, 'partnerCode');
        $this->waitForAttribute($databaseId, $ordersId, 'amount');
        $this->waitForAttribute($databaseId, $ordersId, 'label');
        $this->waitForAttribute($databaseId, $midId, 'customerId');
        $this->waitForAttribute($databaseId, $midId, 'note');
        $this->waitForAttribute($databaseId, $midId, 'amount');
        $this->waitForAttribute($databaseId, $secretsId, 'customerId');
        $this->waitForAttribute($databaseId, $secretsId, 'midId');
        $this->waitForAttribute($databaseId, $secretsId, 'amount');
        $this->waitForAttribute($databaseId, $secretsId, 'secret');
        $this->waitForAttribute($databaseId, $secretsId, 'payload');
        $this->waitForAttribute($databaseId, $rightId, 'customerId');
        $this->waitForAttribute($databaseId, $rightId, 'tag');

        $alice = $this->createJoinHardcoreRecord($databaseId, $customersId, $serverHeaders, [
            'name' => 'Alice',
            'code' => 'ALICE',
        ], $readAny);
        $bob = $this->createJoinHardcoreRecord($databaseId, $customersId, $serverHeaders, [
            'name' => 'Bob',
            'code' => 'BOB',
        ], $readAny);
        $carol = $this->createJoinHardcoreRecord($databaseId, $customersId, $serverHeaders, [
            'name' => 'Carol',
            'code' => 'CAROL',
        ], $readAny);
        $dave = $this->createJoinHardcoreRecord($databaseId, $customersId, $serverHeaders, [
            'name' => 'Dave',
            'code' => 'DAVE',
        ], $readAny);

        $aliceId = $alice['$id'];
        $bobId = $bob['$id'];
        $carolId = $carol['$id'];
        $daveId = $dave['$id'];

        $order200 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'customerId' => $aliceId,
            'partnerCode' => 'CAROL',
            'amount' => 200,
            'label' => 'visible-gamma',
        ], $readAny);
        $order313 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'customerId' => $aliceId,
            'partnerCode' => 'BOB',
            'amount' => 313,
            'label' => 'visible-alpha',
        ], $readAny);
        $order424 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'customerId' => $bobId,
            'partnerCode' => 'ALICE',
            'amount' => 424,
            'label' => 'visible-beta',
        ], $readAny);
        $order100 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'customerId' => $daveId,
            'partnerCode' => 'DAVE',
            'amount' => 100,
            'label' => 'visible-delta',
        ], $readAny);
        $order700 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'partnerCode' => 'ZZZ',
            'amount' => 700,
            'label' => 'visible-orphan',
        ], $readAny);
        $order8686 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'customerId' => $aliceId,
            'partnerCode' => 'ALICE',
            'amount' => 8686,
            'label' => 'combo-hard-alpha',
        ], $hidden);
        $order5151 = $this->createJoinHardcoreRecord($databaseId, $ordersId, $serverHeaders, [
            'partnerCode' => 'ZZZ',
            'amount' => 5151,
            'label' => 'combo-hard-alpha',
        ], $hidden);

        $midAlice = $this->createJoinHardcoreRecord($databaseId, $midId, $serverHeaders, [
            'customerId' => $aliceId,
            'note' => 'mid-visible',
            'amount' => 111,
        ], $midHidden);
        $this->createJoinHardcoreRecord($databaseId, $midId, $serverHeaders, [
            'customerId' => $bobId,
            'note' => 'mid-bob',
            'amount' => 122,
        ], $midHidden);
        $this->createJoinHardcoreRecord($databaseId, $midId, $serverHeaders, [
            'customerId' => $daveId,
            'note' => 'mid-dave',
            'amount' => 133,
        ], $midHidden);

        $this->createJoinHardcoreRecord($databaseId, $secretsId, $serverHeaders, [
            'customerId' => $aliceId,
            'midId' => $midAlice['$id'],
            'amount' => 8686,
            'secret' => 'combo-hard-alpha',
            'payload' => 5151,
        ], $hidden);

        $this->createJoinHardcoreRecord($databaseId, $rightId, $serverHeaders, [
            'customerId' => $aliceId,
            'tag' => 'right-ok',
        ], $readAny);
        $this->createJoinHardcoreRecord($databaseId, $rightId, $serverHeaders, [
            'customerId' => $bobId,
            'tag' => 'right-bob',
        ], $readAny);
        $this->createJoinHardcoreRecord($databaseId, $rightId, $serverHeaders, [
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

    protected function createJoinHardcoreContainer(
        string $databaseId,
        array $serverHeaders,
        string $name,
        bool $documentSecurity,
        array $permissions,
    ): string {
        $result = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
            $this->getContainerIdParam() => ID::unique(),
            'name' => $name,
            $this->getSecurityParam() => $documentSecurity,
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
    protected function createJoinHardcoreRecord(
        string $databaseId,
        string $containerId,
        array $serverHeaders,
        array $data,
        array $permissions,
    ): array {
        $result = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $serverHeaders, [
            $this->getRecordIdParam() => ID::unique(),
            'data' => $data,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $result['headers']['status-code']);

        return $result['body'];
    }

    /**
     * @return array<string, string>
     */
    protected function joinHardcoreHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function joinHardcoreList(string $databaseId, string $containerId, array $queries): array
    {
        return $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $containerId),
            $this->joinHardcoreHeaders(),
            ['queries' => $queries],
        );
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function joinHardcoreGet(string $databaseId, string $containerId, string $recordId, array $queries): array
    {
        return $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $containerId, $recordId),
            $this->joinHardcoreHeaders(),
            ['queries' => $queries],
        );
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    protected function joinHardcoreRows(array $result): array
    {
        $rows = $result['body'][$this->getRecordResource()] ?? [];
        $this->assertSame(true, \is_array($rows));

        return $rows;
    }

    protected function joinHardcoreField(array $row, string $suffix): mixed
    {
        if (\array_key_exists($suffix, $row)) {
            return $row[$suffix];
        }

        foreach ($row as $key => $value) {
            if (\is_string($key) && \str_ends_with($key, '.' . $suffix)) {
                return $value;
            }
        }

        return null;
    }

    protected function joinHardcoreCursorId(array $row): string
    {
        $id = $row['$id'] ?? '';

        return \is_string($id) ? $id : '';
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

    protected function assertJoinHardcoreClientHidden(string $encoded, array $amounts = []): void
    {
        $this->assertStringNotContainsString('combo-hard-alpha', $encoded);
        $this->assertStringNotContainsString('user:combo-hard-hidden', $encoded);
        $this->assertSame(false, \in_array(8686, $amounts, true));
        $this->assertSame(false, \in_array(5151, $amounts, true));
        $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 8686));
        $this->assertSame(false, $this->encodedJsonContainsScalar($encoded, 5151));
        $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '8686'));
        $this->assertSame(false, $this->encodedJsonContainsExactString($encoded, '5151'));
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
        $rows = $this->joinHardcoreRows($listed);
        $amounts = $this->joinComboAmounts($rows);
        $encoded = (string) \json_encode($listed['body']);

        $alicePairs = [];
        foreach ($rows as $row) {
            $this->assertArrayHasKey('alpha.amount', $row);
            $this->assertArrayHasKey('beta.amount', $row);
            if (($row['name'] ?? null) === 'Alice') {
                $alicePairs[] = [(int) $row['alpha.amount'], (int) $row['beta.amount']];
            }
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['$id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array([313, 424], $alicePairs, true));
            $this->assertSame(false, \in_array([313, 313], $alicePairs, true));
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
        $independentRows = $this->joinHardcoreRows($independent);
        $independentEncoded = (string) \json_encode($independent['body']);
        $independentAmounts = $this->joinComboAmounts($independentRows);

        if ($this->getSide() === 'client') {
            $this->assertGreaterThanOrEqual(1, \count($independentRows));
            foreach ($independentRows as $row) {
                $this->assertSame('Alice', $row['name'] ?? null);
                $this->assertSame($data['aliceId'], $row['$id'] ?? null);
                $this->assertSame(313, (int) $row['alpha.amount']);
                $this->assertSame(424, (int) $row['beta.amount']);
            }
            $this->assertJoinHardcoreClientHidden($independentEncoded, $independentAmounts);
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
        $hiddenEncoded = (string) \json_encode($hiddenOnly['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, (int) ($hiddenOnly['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($hiddenEncoded, $this->joinComboAmounts($hiddenRows));
        }
    }

    public function testJoinHardcoreSelfJoinOnIdDoesNotSmashIdentity(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupJoinHardcoreFixture();

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['customersId'], '$id', '$id', '=', 'peer')->toString(),
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'peer.name', 'peer.$id', 'ord.amount', 'ord.$id'])->toString(),
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $rows = $this->joinHardcoreRows($listed);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($listed['body']);
        $amounts = $this->joinComboAmounts($rows);

        foreach ($rows as $row) {
            $id = $row['$id'] ?? null;
            $this->assertSame(true, \in_array($id, $data['customerIds'], true));
            $this->assertSame(false, \in_array($id, $data['orderIds'], true));
            $peerId = $row['peer.$id'] ?? null;
            if (\is_string($peerId) && $peerId !== '') {
                $this->assertSame(true, \in_array($peerId, $data['customerIds'], true));
            }
        }

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $got = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($data['databaseId'], $data['customersId'], $data['aliceId']),
            $this->joinHardcoreHeaders(),
            [
                'queries' => [
                    Query::join($data['customersId'], '$id', '$id', '=', 'peer')->toString(),
                    Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                    Query::select(['name', 'peer.name', 'peer.$id', 'ord.amount'])->toString(),
                ],
            ],
        );

        $this->assertSame(200, $got['headers']['status-code']);
        $this->assertSame($data['aliceId'], $got['body']['$id']);
        $this->assertSame('Alice', $got['body']['name'] ?? null);
        $this->assertSame(false, \in_array($got['body']['$id'] ?? null, $data['orderIds'], true));

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($got['body']),
                $this->joinComboAmounts([$got['body']]),
            );
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
        $rows = $this->joinHardcoreRows($result);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($result['body']);
        $amounts = $this->joinComboAmounts($rows);
        $names = [];
        $notes = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
            $note = $row['mid.note'] ?? $row['note'] ?? null;
            if (\is_string($note) && $note !== '') {
                $notes[] = $note;
            }
            $this->assertNotSame('Carol', $name);
            $id = $row['$id'] ?? null;
            if (\is_string($id) && $id !== '') {
                $this->assertSame(true, \in_array($id, $data['customerIds'], true));
                $this->assertSame(false, \in_array($id, $data['orderIds'], true));
            }
        }

        $this->assertSame(false, \in_array('Carol', $names, true));
        $this->assertSame(true, \in_array('mid-visible', $notes, true));

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
        $rows = $this->joinHardcoreRows($result);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($result['body']);
        $amounts = $this->joinComboAmounts($rows);
        $notes = [];

        foreach ($rows as $row) {
            $note = $row['mid.note'] ?? $row['note'] ?? null;
            if (\is_string($note) && $note !== '') {
                $notes[] = $note;
            }
            if (($row['name'] ?? null) === 'Alice') {
                $this->assertSame('mid-visible', $note);
                $midAmount = $row['mid.amount'] ?? $row['amount'] ?? null;
                $this->assertSame(111, (int) $midAmount);
            }
        }

        $this->assertSame(true, \in_array('mid-visible', $notes, true));

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
            foreach ($rows as $row) {
                $this->assertNotSame('combo-hard-alpha', $row['sec.secret'] ?? $row['secret'] ?? null);
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
        $rows = $this->joinHardcoreRows($ordered);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($ordered['body']);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame(true, \in_array(700, $amounts, true));
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
        $firstRows = $this->joinHardcoreRows($firstPage);
        $this->assertSame(1, \count($firstRows));

        $after = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $after['headers']['status-code']);
        $afterRows = $this->joinHardcoreRows($after);
        $this->assertSame(1, \count($afterRows));
        $afterId = $afterRows[0]['$id'] ?? '';
        $this->assertNotSame('', $afterId);
        $this->assertNotSame($cursorId, $afterId);
        $afterEncoded = (string) \json_encode($after['body']);

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($afterEncoded, $this->joinComboAmounts($afterRows));
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
        $beforeRows = $this->joinHardcoreRows($before);
        $this->assertLessThanOrEqual(1, \count($beforeRows));
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($before['body']),
                $this->joinComboAmounts($beforeRows),
            );
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
        $mixedRows = $this->joinHardcoreRows($mixed);
        $mixedEncoded = (string) \json_encode($mixed['body']);
        $mixedAmounts = $this->joinComboAmounts($mixedRows);

        if ($this->getSide() === 'client') {
            $this->assertNotEmpty($mixedRows);
            foreach ($mixedRows as $row) {
                $this->assertSame('Alice', $row['name'] ?? null);
                $this->assertSame($data['aliceId'], $row['$id'] ?? null);
            }
            $this->assertSame(true, \in_array(313, $mixedAmounts, true));
            $this->assertJoinHardcoreClientHidden($mixedEncoded, $mixedAmounts);
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
        $hiddenEncoded = (string) \json_encode($hiddenOnly['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, (int) ($hiddenOnly['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($hiddenEncoded, $this->joinComboAmounts($hiddenRows));
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
        $rows = $this->joinHardcoreRows($ordered);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($ordered['body']);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $first = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $first['headers']['status-code']);
        $firstRows = $this->joinHardcoreRows($first);
        $this->assertSame(1, \count($firstRows));
        $this->assertSame('Alice', $firstRows[0]['name'] ?? null);
        $cursorId = $this->joinHardcoreCursorId($firstRows[0]);
        $this->assertNotSame('', $cursorId);
        $this->assertSame($data['aliceId'], $cursorId);

        $after = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $after['headers']['status-code']);
        $afterRows = $this->joinHardcoreRows($after);
        $this->assertSame(1, \count($afterRows));
        $afterName = (string) ($afterRows[0]['name'] ?? '');
        $this->assertSame(true, $afterName >= 'Alice');
        $afterId = $afterRows[0]['$id'] ?? '';
        $this->assertNotSame('', $afterId);
        $this->assertNotSame($cursorId, $afterId);
        $this->assertSame(false, \in_array($afterId, $data['orderIds'], true));

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($after['body']),
                $this->joinComboAmounts($afterRows),
            );
        }

        $before = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            ...$orderQueries,
            Query::cursorBefore(new Document(['$id' => $afterId]))->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertSame(200, $before['headers']['status-code']);
        $beforeRows = $this->joinHardcoreRows($before);
        $this->assertLessThanOrEqual(1, \count($beforeRows));
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($before['body']),
                $this->joinComboAmounts($beforeRows),
            );
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
        $containsRows = $this->joinHardcoreRows($contains);
        $this->assertNotEmpty($containsRows);
        $containsEncoded = (string) \json_encode($contains['body']);
        $containsAmounts = $this->joinComboAmounts($containsRows);
        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $containsAmounts, true));
            $this->assertJoinHardcoreClientHidden($containsEncoded, $containsAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $containsAmounts, true));
        }

        $between = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::between('ord.amount', 100, 500)->toString(),
            $select,
        ]);
        $this->assertSame(200, $between['headers']['status-code']);
        $betweenRows = $this->joinHardcoreRows($between);
        $this->assertNotEmpty($betweenRows);
        $betweenAmounts = $this->joinComboAmounts($betweenRows);
        foreach ($betweenAmounts as $amount) {
            $this->assertSame(true, $amount >= 100 && $amount <= 500);
        }
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden((string) \json_encode($between['body']), $betweenAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $betweenAmounts, true));
        }

        $starts = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::startsWith('ord.label', 'visible')->toString(),
            $select,
        ]);
        $this->assertSame(200, $starts['headers']['status-code']);
        $startsRows = $this->joinHardcoreRows($starts);
        $this->assertNotEmpty($startsRows);
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($starts['body']),
                $this->joinComboAmounts($startsRows),
            );
        }

        $byId = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::equal('ord.$id', [$data['order313Id']])->toString(),
            $select,
        ]);
        $this->assertSame(200, $byId['headers']['status-code']);
        $byIdRows = $this->joinHardcoreRows($byId);
        $this->assertNotEmpty($byIdRows);
        foreach ($byIdRows as $row) {
            $this->assertSame($data['aliceId'], $row['$id'] ?? null);
            $joinId = $row['ord.$id'] ?? null;
            if (\is_string($joinId) && $joinId !== '') {
                $this->assertSame($data['order313Id'], $joinId);
            }
        }
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($byId['body']),
                $this->joinComboAmounts($byIdRows),
            );
        }

        $byCreated = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::between('ord.$createdAt', '1970-01-01', '2099-12-31')->toString(),
            $select,
        ]);
        $this->assertSame(200, $byCreated['headers']['status-code']);
        $createdRows = $this->joinHardcoreRows($byCreated);
        $this->assertNotEmpty($createdRows);
        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($byCreated['body']),
                $this->joinComboAmounts($createdRows),
            );
        }

        $search = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            $join,
            Query::search('ord.label', 'visible')->toString(),
            $select,
        ]);
        if ($search['headers']['status-code'] === 200) {
            $searchRows = $this->joinHardcoreRows($search);
            if ($this->getSide() === 'client') {
                $this->assertJoinHardcoreClientHidden(
                    (string) \json_encode($search['body']),
                    $this->joinComboAmounts($searchRows),
                );
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
        $rows = $this->joinHardcoreRows($result);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($result['body']);
        $amounts = $this->joinComboAmounts($rows);
        $orphanSeen = false;

        foreach ($rows as $row) {
            $id = $row['$id'] ?? null;
            $name = $row['name'] ?? null;
            $this->assertSame(false, \in_array($id, $data['orderIds'], true));
            $this->assertArrayNotHasKey('ord.$id', $row);
            $this->assertArrayNotHasKey('ord.$permissions', $row);

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
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
        $encoded = (string) \json_encode($result['body']);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($rows));
            $this->assertSame(0, (int) ($result['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
        $controlRows = $this->joinHardcoreRows($control);
        $this->assertNotEmpty($controlRows);
        $controlNames = [];
        foreach ($controlRows as $row) {
            $name = $row['name'] ?? null;
            if (\is_string($name) && $name !== '') {
                $controlNames[] = $name;
            }
        }
        $this->assertSame(true, \in_array('Alice', $controlNames, true));

        $listed = $this->joinHardcoreList($data['databaseId'], $data['customersId'], $joinQueries);
        $this->assertSame(200, $listed['headers']['status-code']);
        $rows = $this->joinHardcoreRows($listed);
        $this->assertNotEmpty($rows);
        $this->assertSame(true, \is_int($listed['body']['total'] ?? null) || \is_numeric($listed['body']['total'] ?? null));
        $this->assertGreaterThanOrEqual(1, (int) ($listed['body']['total'] ?? 0));
        $encoded = (string) \json_encode($listed['body']);
        $amounts = $this->joinComboAmounts($rows);

        foreach ($rows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['$id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            foreach ([200, 313, 424, 100] as $visible) {
                $this->assertSame(true, \in_array($visible, $amounts, true));
            }
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($control['body']),
                $this->joinComboAmounts($controlRows),
            );
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
        }

        $got = $this->joinHardcoreGet($data['databaseId'], $data['customersId'], $data['aliceId'], $joinQueries);
        $this->assertSame(200, $got['headers']['status-code']);
        $this->assertSame($data['aliceId'], $got['body']['$id']);
        $this->assertSame('Alice', $got['body']['name'] ?? null);
        $this->assertSame(false, \in_array($got['body']['$id'] ?? null, $data['orderIds'], true));
        $this->assertNotSame($data['order8686Id'], $got['body']['$id']);

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($got['body']),
                $this->joinComboAmounts([$got['body']]),
            );
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
        $rows = $this->joinHardcoreRows($listed);
        $encoded = (string) \json_encode($listed['body']);
        $amounts = $this->joinComboAmounts($rows);
        $alicePairs = [];

        foreach ($rows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['$id'] ?? null);
            if (($row['name'] ?? null) === 'Alice') {
                $alicePairs[] = [(int) $row['alpha.amount'], (int) $row['beta.amount']];
            }
        }

        if ($this->getSide() === 'client') {
            $this->assertNotEmpty($rows);
            $this->assertSame(true, \in_array([313, 424], $alicePairs, true));
            foreach ($rows as $row) {
                $this->assertSame('Alice', $row['name'] ?? null);
                $this->assertSame($data['aliceId'], $row['$id'] ?? null);
                $this->assertSame(313, (int) $row['alpha.amount']);
                $this->assertSame(424, (int) $row['beta.amount']);
            }
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
        $hiddenEncoded = (string) \json_encode($hiddenOnly['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, (int) ($hiddenOnly['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($hiddenEncoded, $this->joinComboAmounts($hiddenRows));
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
        $leftRows = $this->joinHardcoreRows($left);
        $this->assertNotEmpty($leftRows);
        $leftEncoded = (string) \json_encode($left['body']);
        $leftAmounts = $this->joinComboAmounts($leftRows);
        $aliceSeen = false;

        foreach ($leftRows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            if (($row['name'] ?? null) === 'Alice') {
                $aliceSeen = true;
                $this->assertSame($data['aliceId'], $row['$id'] ?? null);
                $secret = $row['sec.secret'] ?? $row['secret'] ?? null;
                if ($this->getSide() === 'client') {
                    $this->assertSame(true, $secret === null || $secret === '');
                }
            }
        }

        $this->assertSame(true, $aliceSeen);

        if ($this->getSide() === 'client') {
            $this->assertJoinHardcoreClientHidden($leftEncoded, $leftAmounts);
        }

        $inner = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::join($data['secretsId'], '$id', 'customerId', '=', 'sec')->toString(),
            Query::equal('sec.amount', [8686])->toString(),
            Query::select(['name', 'sec.amount', 'sec.secret'])->toString(),
        ]);
        $this->assertSame(200, $inner['headers']['status-code']);
        $innerRows = $this->joinHardcoreRows($inner);
        $innerEncoded = (string) \json_encode($inner['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($innerRows));
            $this->assertSame(0, (int) ($inner['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($innerEncoded, $this->joinComboAmounts($innerRows));
        }

        $foj = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ]);
        $this->assertSame(200, $foj['headers']['status-code']);
        $fojRows = $this->joinHardcoreRows($foj);
        $this->assertNotEmpty($fojRows);
        $fojEncoded = (string) \json_encode($foj['body']);
        $fojAmounts = $this->joinComboAmounts($fojRows);

        foreach ($fojRows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['$id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(700, $fojAmounts, true));
            $this->assertJoinHardcoreClientHidden($fojEncoded, $fojAmounts);
        } else {
            $this->assertSame(true, \in_array(313, $fojAmounts, true) || \in_array(700, $fojAmounts, true));
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
        $rows = $this->joinHardcoreRows($ordered);
        $this->assertNotEmpty($rows);
        $encoded = (string) \json_encode($ordered['body']);
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
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
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
            $this->assertJoinHardcoreClientHidden(
                (string) \json_encode($after['body']),
                $this->joinComboAmounts($afterRows),
            );
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
        $this->assertSame($data['aliceId'], $got['body']['$id']);
        $this->assertSame('Alice', $got['body']['name'] ?? null);
        $this->assertSame(false, \in_array($got['body']['$id'] ?? null, $data['orderIds'], true));
        $this->assertNotSame($data['order8686Id'], $got['body']['$id']);
        $this->assertNotSame($data['order5151Id'], $got['body']['$id']);

        $orderId = $got['body']['ord.$id'] ?? null;
        if (\is_string($orderId) && $orderId !== '') {
            $this->assertSame(true, \in_array($orderId, $data['orderIds'], true));
            $this->assertNotSame($data['aliceId'], $orderId);
        }

        $encoded = (string) \json_encode($got['body']);
        $amounts = $this->joinComboAmounts([$got['body']]);

        if ($this->getSide() === 'client') {
            if (\is_string($orderId) && $orderId !== '') {
                $this->assertNotSame($data['order8686Id'], $orderId);
                $this->assertNotSame($data['order5151Id'], $orderId);
            }
            $permissions = $got['body']['ord.$permissions'] ?? [];
            if (\is_string($permissions)) {
                $decoded = \json_decode($permissions, true);
                $permissions = \is_array($decoded) ? $decoded : [$permissions];
            }
            if (\is_array($permissions)) {
                $this->assertSame(false, \in_array('user:combo-hard-hidden', $permissions, true));
            }
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true) || \in_array(200, $amounts, true));
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
        $rows = $this->joinHardcoreRows($listed);
        $this->assertNotEmpty($rows);
        $this->assertSame(true, \is_int($listed['body']['total'] ?? null) || \is_numeric($listed['body']['total'] ?? null));
        $this->assertGreaterThanOrEqual(1, (int) ($listed['body']['total'] ?? 0));
        $encoded = (string) \json_encode($listed['body']);
        $amounts = $this->joinComboAmounts($rows);

        foreach ($rows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['$id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame(true, \in_array(700, $amounts, true));
            $this->assertJoinHardcoreClientHidden($encoded, $amounts);
        } else {
            $this->assertSame(true, \in_array(313, $amounts, true));
            $this->assertSame(true, \in_array(700, $amounts, true));
        }

        $hidden = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::fullOuterJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::equal('ord.amount', [8686])->toString(),
            Query::select(['name', 'ord.amount'])->toString(),
        ]);

        $this->assertSame(200, $hidden['headers']['status-code']);
        $hiddenRows = $this->joinHardcoreRows($hidden);
        $hiddenEncoded = (string) \json_encode($hidden['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($hiddenRows));
            $this->assertSame(0, (int) ($hidden['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($hiddenEncoded, $this->joinComboAmounts($hiddenRows));
        }
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
        $notNullEncoded = (string) \json_encode($notNull['body']);

        if ($this->getSide() === 'client') {
            $this->assertSame(0, \count($notNullRows));
            $this->assertSame(0, (int) ($notNull['body']['total'] ?? 0));
            $this->assertJoinHardcoreClientHidden($notNullEncoded, $this->joinComboAmounts($notNullRows));
        }

        $notEqual = $this->joinHardcoreList($data['databaseId'], $data['customersId'], [
            Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
            Query::notEqual('ord.amount', 8686)->toString(),
            Query::select(['name', 'ord.amount', 'ord.label'])->toString(),
        ]);
        $this->assertSame(200, $notEqual['headers']['status-code']);
        $notEqualRows = $this->joinHardcoreRows($notEqual);
        $this->assertNotEmpty($notEqualRows);
        $notEqualEncoded = (string) \json_encode($notEqual['body']);
        $notEqualAmounts = $this->joinComboAmounts($notEqualRows);

        foreach ($notEqualRows as $row) {
            $this->assertNotSame($data['order8686Id'], $row['$id'] ?? null);
            $this->assertNotSame($data['order5151Id'], $row['$id'] ?? null);
        }

        if ($this->getSide() === 'client') {
            foreach ([200, 313, 424, 100] as $visible) {
                $this->assertSame(true, \in_array($visible, $notEqualAmounts, true));
            }
            $this->assertJoinHardcoreClientHidden($notEqualEncoded, $notEqualAmounts);
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
            $notContainsEncoded = (string) \json_encode($notContains['body']);
            $notContainsAmounts = $this->joinComboAmounts($notContainsRows);

            if ($this->getSide() === 'client') {
                $this->assertJoinHardcoreClientHidden($notContainsEncoded, $notContainsAmounts);
            }
        }
    }
}
