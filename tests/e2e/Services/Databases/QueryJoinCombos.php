<?php

namespace Tests\E2E\Services\Databases;

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
}
