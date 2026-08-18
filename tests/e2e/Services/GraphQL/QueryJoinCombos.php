<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait QueryJoinCombos
{
    private static array $joinComboCache = [];

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

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function assertJoinComboClientHidden(array $result, array $rows, array $amounts): void
    {
        $encoded = $this->joinEncodedBody($result);
        $this->assertStringNotContainsString('combo-secret-alpha', $encoded);
        $this->assertStringNotContainsString('user:combo-hidden', $encoded);
        $this->assertSame(false, \in_array(777, $amounts, true));

        foreach ($rows as $row) {
            $decoded = $this->decodeJoinData($row);
            $decodedEncoded = (string) \json_encode($decoded);
            $this->assertStringNotContainsString('combo-secret-alpha', $decodedEncoded);
            $this->assertStringNotContainsString('user:combo-hidden', $decodedEncoded);
            $this->assertSame(false, \in_array('combo-secret-alpha', $decoded, true));

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

        $this->assertArrayNotHasKey('errors', $result['body']);
        $rows = $this->joinListRecords($result);
        $amounts = $this->joinComboAmounts($rows);

        if ($this->getSide() === 'client') {
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
}
