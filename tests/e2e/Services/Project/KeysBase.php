<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait KeysBase
{
    use Async;

    // =========================================================================
    // Create key tests
    // =========================================================================

    public function testCreateKey(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'My API Key',
            ['users.read', 'users.write'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']['$id']);
        $this->assertSame('My API Key', $key['body']['name']);
        $this->assertSame(['users.read', 'users.write'], $key['body']['scopes']);
        $this->assertNotEmpty($key['body']['secret']);
        $this->assertSame('', $key['body']['expire']);
        $this->assertSame('', $key['body']['accessedAt']);
        $this->assertSame([], $key['body']['sdks']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($key['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($key['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getKey($key['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($key['body']['$id'], $get['body']['$id']);
        $this->assertSame('My API Key', $get['body']['name']);
        $this->assertSame(['users.read', 'users.write'], $get['body']['scopes']);

        // Verify via LIST
        $list = $this->listKeys(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['keys']));

        // Cleanup
        $this->deleteKey($key['body']['$id']);
    }

    public function testCreateKeyWithExpire(): void
    {
        $expire = '2030-01-01T00:00:00.000+00:00';

        $key = $this->createKey(
            ID::unique(),
            'Expiring Key',
            ['users.read'],
            $expire,
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertSame($expire, $key['body']['expire']);

        // Verify via GET
        $get = $this->getKey($key['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($expire, $get['body']['expire']);

        // Cleanup
        $this->deleteKey($key['body']['$id']);
    }

    public function testCreateKeyWithNullScopes(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Null Scopes Key',
            null,
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertSame([], $key['body']['scopes']);

        // Cleanup
        $this->deleteKey($key['body']['$id']);
    }

    public function testCreateKeyWithoutAuthentication(): void
    {
        $response = $this->createKey(
            ID::unique(),
            'No Auth Key',
            ['users.read'],
            null,
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateKeyInvalidId(): void
    {
        $key = $this->createKey(
            '!invalid-id!',
            'Invalid ID Key',
            ['users.read'],
        );

        $this->assertSame(400, $key['headers']['status-code']);
    }

    public function testCreateKeyMissingName(): void
    {
        $response = $this->createKey(
            ID::unique(),
            null,
            ['users.read'],
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateKeyInvalidScope(): void
    {
        $response = $this->createKey(
            ID::unique(),
            'Invalid Scope Key',
            ['invalid.scope'],
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateKeyDuplicateId(): void
    {
        $keyId = ID::unique();

        $key = $this->createKey(
            $keyId,
            'Key Dup 1',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createKey(
            $keyId,
            'Key Dup 2',
            ['users.write'],
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('key_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testCreateKeyCustomId(): void
    {
        $customId = 'my-custom-key-id';

        $key = $this->createKey(
            $customId,
            'Custom ID Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertSame($customId, $key['body']['$id']);

        // Verify via GET
        $get = $this->getKey($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deleteKey($customId);
    }

    // =========================================================================
    // Update key tests
    // =========================================================================

    public function testUpdateKey(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Original Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Update name, scopes, and expire
        $expire = '2031-06-15T12:00:00.000+00:00';
        $updated = $this->updateKey($keyId, 'Updated Key', ['users.write', 'databases.read'], $expire);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($keyId, $updated['body']['$id']);
        $this->assertSame('Updated Key', $updated['body']['name']);
        $this->assertSame(['users.write', 'databases.read'], $updated['body']['scopes']);
        $this->assertSame($expire, $updated['body']['expire']);

        // Verify update persisted via GET
        $get = $this->getKey($keyId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated Key', $get['body']['name']);
        $this->assertSame(['users.write', 'databases.read'], $get['body']['scopes']);
        $this->assertSame($expire, $get['body']['expire']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testUpdateKeyName(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Name Before',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        $updated = $this->updateKey($keyId, 'Name After', ['users.read']);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('Name After', $updated['body']['name']);
        $this->assertSame(['users.read'], $updated['body']['scopes']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testUpdateKeyScopes(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Scopes Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        $updated = $this->updateKey($keyId, 'Scopes Key', ['databases.read', 'databases.write']);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(['databases.read', 'databases.write'], $updated['body']['scopes']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testUpdateKeySetExpire(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'No Expire Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertSame('', $key['body']['expire']);
        $keyId = $key['body']['$id'];

        $expire = '2032-12-31T23:59:59.000+00:00';
        $updated = $this->updateKey($keyId, 'No Expire Key', ['users.read'], $expire);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($expire, $updated['body']['expire']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testUpdateKeyRemoveExpire(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Expire Key',
            ['users.read'],
            '2030-01-01T00:00:00.000+00:00',
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Remove expire by setting to null
        $updated = $this->updateKey($keyId, 'Expire Key', ['users.read'], null);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('', $updated['body']['expire']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testUpdateKeyWithoutAuthentication(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Auth Update Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Attempt update without authentication
        $response = $this->updateKey($keyId, 'Updated Name', ['users.read'], null, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testUpdateKeyNotFound(): void
    {
        $updated = $this->updateKey('non-existent-id', 'New Name', ['users.read']);

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('key_not_found', $updated['body']['type']);
    }

    public function testUpdateKeyInvalidScope(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Invalid Scope Update',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        $updated = $this->updateKey($keyId, 'Invalid Scope Update', ['invalid.scope']);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    // =========================================================================
    // Get key tests
    // =========================================================================

    public function testGetKey(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Get Test Key',
            ['users.read', 'databases.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        $get = $this->getKey($keyId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($keyId, $get['body']['$id']);
        $this->assertSame('Get Test Key', $get['body']['name']);
        $this->assertSame(['users.read', 'databases.read'], $get['body']['scopes']);
        $this->assertNotEmpty($get['body']['secret']);
        $this->assertSame('', $get['body']['expire']);
        $this->assertSame('', $get['body']['accessedAt']);
        $this->assertSame([], $get['body']['sdks']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testGetKeyNotFound(): void
    {
        $get = $this->getKey('non-existent-id');

        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('key_not_found', $get['body']['type']);
    }

    public function testGetKeyWithoutAuthentication(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Auth Get Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Attempt GET without authentication
        $response = $this->getKey($keyId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    // =========================================================================
    // List keys tests
    // =========================================================================

    public function testListKeys(): void
    {
        // Create multiple keys
        $key1 = $this->createKey(
            ID::unique(),
            'List Key Alpha',
            ['users.read'],
        );
        $this->assertSame(201, $key1['headers']['status-code']);

        $key2 = $this->createKey(
            ID::unique(),
            'List Key Beta',
            ['databases.read'],
        );
        $this->assertSame(201, $key2['headers']['status-code']);

        $key3 = $this->createKey(
            ID::unique(),
            'List Key Gamma',
            ['users.write'],
        );
        $this->assertSame(201, $key3['headers']['status-code']);

        // List all
        $list = $this->listKeys(null, true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $list['body']['total']);
        $this->assertGreaterThanOrEqual(3, \count($list['body']['keys']));
        $this->assertIsArray($list['body']['keys']);

        // Verify structure of returned keys
        foreach ($list['body']['keys'] as $key) {
            $this->assertArrayHasKey('$id', $key);
            $this->assertArrayHasKey('$createdAt', $key);
            $this->assertArrayHasKey('$updatedAt', $key);
            $this->assertArrayHasKey('name', $key);
            $this->assertArrayHasKey('scopes', $key);
            $this->assertArrayHasKey('secret', $key);
            $this->assertArrayHasKey('expire', $key);
            $this->assertArrayHasKey('accessedAt', $key);
            $this->assertArrayHasKey('sdks', $key);
        }

        // Cleanup
        $this->deleteKey($key1['body']['$id']);
        $this->deleteKey($key2['body']['$id']);
        $this->deleteKey($key3['body']['$id']);
    }

    public function testListKeysWithLimit(): void
    {
        $key1 = $this->createKey(
            ID::unique(),
            'Limit Key 1',
            ['users.read'],
        );
        $this->assertSame(201, $key1['headers']['status-code']);

        $key2 = $this->createKey(
            ID::unique(),
            'Limit Key 2',
            ['users.write'],
        );
        $this->assertSame(201, $key2['headers']['status-code']);

        // List with limit 1
        $list = $this->listKeys([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertCount(1, $list['body']['keys']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);

        // Cleanup
        $this->deleteKey($key1['body']['$id']);
        $this->deleteKey($key2['body']['$id']);
    }

    public function testListKeysWithoutTotal(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'No Total Key',
            ['users.read'],
        );
        $this->assertSame(201, $key['headers']['status-code']);

        // List with total=false
        $list = $this->listKeys(null, false);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['keys']));

        // Cleanup
        $this->deleteKey($key['body']['$id']);
    }

    public function testListKeysCursorPagination(): void
    {
        $key1 = $this->createKey(
            ID::unique(),
            'Cursor Key 1',
            ['users.read'],
        );
        $this->assertSame(201, $key1['headers']['status-code']);

        $key2 = $this->createKey(
            ID::unique(),
            'Cursor Key 2',
            ['users.write'],
        );
        $this->assertSame(201, $key2['headers']['status-code']);

        // Get first page with limit 1
        $page1 = $this->listKeys([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['keys']);
        $cursorId = $page1['body']['keys'][0]['$id'];

        // Get next page using cursor
        $page2 = $this->listKeys([
            Query::limit(1)->toString(),
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
        ], true);

        $this->assertSame(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['keys']);
        $this->assertNotEquals($cursorId, $page2['body']['keys'][0]['$id']);

        // Cleanup
        $this->deleteKey($key1['body']['$id']);
        $this->deleteKey($key2['body']['$id']);
    }

    public function testListKeysWithoutAuthentication(): void
    {
        $response = $this->listKeys(null, null, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testListKeysInvalidCursor(): void
    {
        $list = $this->listKeys([
            Query::cursorAfter(new Document(['$id' => 'non-existent-id']))->toString(),
        ], true);

        $this->assertSame(400, $list['headers']['status-code']);
    }

    // =========================================================================
    // Delete key tests
    // =========================================================================

    public function testDeleteKey(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Delete Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Verify it exists
        $get = $this->getKey($keyId);
        $this->assertSame(200, $get['headers']['status-code']);

        // Delete
        $delete = $this->deleteKey($keyId);
        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify it no longer exists
        $get = $this->getKey($keyId);
        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('key_not_found', $get['body']['type']);
    }

    public function testDeleteKeyNotFound(): void
    {
        $delete = $this->deleteKey('non-existent-id');

        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('key_not_found', $delete['body']['type']);
    }

    public function testDeleteKeyWithoutAuthentication(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Delete Auth Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Attempt DELETE without authentication
        $response = $this->deleteKey($keyId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Verify it still exists
        $get = $this->getKey($keyId);
        $this->assertSame(200, $get['headers']['status-code']);

        // Cleanup
        $this->deleteKey($keyId);
    }

    public function testDeleteKeyRemovedFromList(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Delete List Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // Get list count before delete
        $listBefore = $this->listKeys(null, true);
        $this->assertSame(200, $listBefore['headers']['status-code']);
        $countBefore = $listBefore['body']['total'];

        // Delete
        $delete = $this->deleteKey($keyId);
        $this->assertSame(204, $delete['headers']['status-code']);

        // Get list count after delete
        $listAfter = $this->listKeys(null, true);
        $this->assertSame(200, $listAfter['headers']['status-code']);
        $this->assertSame($countBefore - 1, $listAfter['body']['total']);

        // Verify the deleted key is not in the list
        $ids = \array_column($listAfter['body']['keys'], '$id');
        $this->assertNotContains($keyId, $ids);
    }

    public function testDeleteKeyDoubleDelete(): void
    {
        $key = $this->createKey(
            ID::unique(),
            'Double Delete Key',
            ['users.read'],
        );

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        // First delete succeeds
        $delete = $this->deleteKey($keyId);
        $this->assertSame(204, $delete['headers']['status-code']);

        // Second delete returns 404
        $delete = $this->deleteKey($keyId);
        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('key_not_found', $delete['body']['type']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<string>|null $scopes
     */
    protected function createKey(string $keyId, ?string $name, ?array $scopes = null, ?string $expire = null, bool $authenticated = true): mixed
    {
        $params = [
            'keyId' => $keyId,
            'scopes' => $scopes,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($expire !== null) {
            $params['expire'] = $expire;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/keys', $headers, $params);
    }

    /**
     * @param array<string>|null $scopes
     */
    protected function updateKey(string $keyId, ?string $name = null, ?array $scopes = null, ?string $expire = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($scopes !== null) {
            $params['scopes'] = $scopes;
        }

        if ($expire !== null) {
            $params['expire'] = $expire;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $headers, $params);
    }

    protected function getKey(string $keyId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/keys/' . $keyId, $headers);
    }

    /**
     * @param array<string>|null $queries
     */
    protected function listKeys(?array $queries, ?bool $total, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/keys', $headers, [
            'queries' => $queries,
            'total' => $total,
        ]);
    }

    protected function deleteKey(string $keyId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $keyId, $headers);
    }
}
