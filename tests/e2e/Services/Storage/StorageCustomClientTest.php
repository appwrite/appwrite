<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

final class StorageCustomClientTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideClient;
    use StoragePermissionsScope;

    /**
     * @var array Cached default permissions file data for tests
     */
    private static array $cachedDefaultPermissionsFile = [];

    /**
     * Helper method to set up a file with default permissions for tests.
     * Uses static caching to avoid recreating resources.
     */
    protected function setupDefaultPermissionsFile(): array
    {
        $cacheKey = $this->getProject()['$id'];

        if (!empty(self::$cachedDefaultPermissionsFile[$cacheKey])) {
            return self::$cachedDefaultPermissionsFile[$cacheKey];
        }

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        self::$cachedDefaultPermissionsFile[$cacheKey] = [
            'fileId' => $file['body']['$id'],
            'bucketId' => $bucket['body']['$id']
        ];

        return self::$cachedDefaultPermissionsFile[$cacheKey];
    }

    public function testCachedFilePreview(): void
    {
        /**
        Create a bucket with File Level Security with no permissions.
        Add a file with no permissions.
        Login as UserA from SDK
        Call File Preview from SDK all good userA can't see preview.
        Add read permission to UserA, all good userA can now see preview.
        Remove read permission for UserA.
        Call File Preview from SDK and now userA can't see the preview.
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'permissions' => [],
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'permissions.png',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'permissions.png',
            'permissions' => [],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
    }

    public function testBucketAnyPermissions(): void
    {

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'permissions.png',
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
    }

    public function testBucketUsersPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::create(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'permissions.png',
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
    }

    public function testBucketUserPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'permissions.png',
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'permissions.png',
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $email = Id::unique() . '@localhost.test';
        $password = 'password';
        $user2 = $this->createUser('user2', $email, $password);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));


        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
    }

    public function testBucketTeamPermissions(): void
    {
        $team1 = $this->createTeam(Id::unique(), 'Team 1');
        $team2 = $this->createTeam(Id::unique(), 'Team 1');
        $user1 = $this->createUser(Id::unique(), Id::unique() . '@localhost.test', 'password');
        $user2 = $this->createUser(Id::unique(), Id::unique() . '@localhost.test', 'password');

        $this->addToTeam($user1['$id'], $team1['$id']);
        $this->addToTeam($user2['$id'], $team2['$id']);

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::team(Id::custom($team1['$id']))),
                Permission::read(Role::team(Id::custom($team2['$id']))),
                Permission::create(Role::team(Id::custom($team1['$id']))),
                Permission::update(Role::team(Id::custom($team1['$id']))),
                Permission::delete(Role::team(Id::custom($team1['$id']))),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        // Team 1 create success
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        // Team 1 read success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 2 read success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 preview success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 2 preview success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 download success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 2 download success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 view success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 view success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        // Team 2 create failure
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        // Team 2 update failure
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        // Team 2 delete failure
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        // Team 1 delete success
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
    }

    public function testFileAnyPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [],
            'fileSecurity' => true
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file1 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $fileId = $file1['body']['$id'];
        $this->assertEquals(201, $file1['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file1['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file1['body']['name']);
        $this->assertEquals('image/png', $file1['body']['mimeType']);
        $this->assertEquals(47218, $file1['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
    }

    public function testFileUsersPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [],
            'fileSecurity' => true
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file1 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::users()),
            ],
        ]);

        $fileId = $file1['body']['$id'];
        $this->assertEquals(201, $file1['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file1['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file1['body']['name']);
        $this->assertEquals('image/png', $file1['body']['mimeType']);
        $this->assertEquals(47218, $file1['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(401, $file['headers']['status-code']);
    }

    public function testFileUserPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [],
            'fileSecurity' => true
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file1 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $fileId = $file1['body']['$id'];
        $this->assertEquals(201, $file1['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file1['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file1['body']['name']);
        $this->assertEquals('image/png', $file1['body']['mimeType']);
        $this->assertEquals(47218, $file1['body']['sizeOriginal']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(401, $file['headers']['status-code']);

        $user2 = $this->createUser(Id::unique(), uniqid() . '@localhost.test', 'password');

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(404, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
    }

    public function testFileTeamPermissions(): void
    {
        $team1 = $this->createTeam(Id::unique(), 'Team 1');
        $team2 = $this->createTeam(Id::unique(), 'Team 1');
        $user1 = $this->createUser(Id::unique(), Id::unique() . '@localhost.test', 'password');
        $user2 = $this->createUser(Id::unique(), Id::unique() . '@localhost.test', 'password');

        $this->addToTeam($user1['$id'], $team1['$id']);
        $this->addToTeam($user2['$id'], $team2['$id']);

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [],
            'fileSecurity' => true,
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::team(Id::custom($team1['$id']))),
                Permission::read(Role::team(Id::custom($team2['$id']))),
                Permission::update(Role::team(Id::custom($team1['$id']))),
                Permission::delete(Role::team(Id::custom($team1['$id']))),
            ],
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        // Team 1 read success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 2 read success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 preview success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 2 preview success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 download success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 2 download success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 view success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        // Team 1 view success
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        // Team 1 create failure
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        // Team 2 create failure
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        // Team 2 update failure
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        // Team 2 delete failure
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        // Team 1 delete success
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user1['session'],
        ]);

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
    }

    public function testAllowedPermissions(): void
    {
        /**
         * Test for SUCCESS
         */

        // Bucket aliases write to create, update, delete
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
            'fileSecurity' => true,
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);

        $this->assertContains(Permission::create(Role::user($this->getUser()['$id'])), $bucket['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $bucket['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $bucket['body']['$permissions']);

        // File aliases write to update, delete
        $file1 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::write(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertNotContains(Permission::create(Role::user($this->getUser()['$id'])), $file1['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $file1['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $file1['body']['$permissions']);

        /**
         * Test for FAILURE
         */

        // File does not allow create permission
        $file2 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(400, $file2['headers']['status-code']);
    }

    public function testCreateFileDefaultPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertContains(Permission::read(Role::user($this->getUser()['$id'])), $file['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $file['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $file['body']['$permissions']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

    }

    public function testCreateFileAbusePermissions(): void
    {
        $data = $this->setupDefaultPermissionsFile();
        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => Id::custom('xyz'),
            'permissions' => [
                Permission::read(Role::user(Id::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', (string) $file['body']['message']);
        $this->assertStringContainsString('users', (string) $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], (string) $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => Id::custom('xyz'),
            'permissions' => [
                Permission::update(Role::user(Id::custom('notme'))),
                Permission::delete(Role::user(Id::custom('notme'))),
            ]
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', (string) $file['body']['message']);
        $this->assertStringContainsString('users', (string) $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], (string) $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => Id::custom('xyz'),
            'permissions' => [
                Permission::read(Role::user(Id::custom('notme'))),
                Permission::update(Role::user(Id::custom('notme'))),
                Permission::delete(Role::user(Id::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', (string) $file['body']['message']);
        $this->assertStringContainsString('users', (string) $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], (string) $file['body']['message']);
    }

    public function testUpdateFileAbusePermissions(): void
    {
        $data = $this->setupDefaultPermissionsFile();
        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user(Id::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', (string) $file['body']['message']);
        $this->assertStringContainsString('users', (string) $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], (string) $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::update(Role::user(Id::custom('notme'))),
                Permission::delete(Role::user(Id::custom('notme'))),
            ]
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', (string) $file['body']['message']);
        $this->assertStringContainsString('users', (string) $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], (string) $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user(Id::custom('notme'))),
                Permission::update(Role::user(Id::custom('notme'))),
                Permission::delete(Role::user(Id::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', (string) $file['body']['message']);
        $this->assertStringContainsString('users', (string) $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], (string) $file['body']['message']);
    }

    public function testCreateBucketTransformationsDisabled(): void
    {
        // Create a bucket with default settings
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket Transformations Disabled',
            'permissions' => [
                Permission::read(Role::any())
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        // Create a file in the bucket
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'transformations.png'),
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);

        // Try to get the file preview
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'] . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);
        $this->assertEquals(200, $preview['headers']['status-code']);

        // Update the bucket to disable transformations
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Test Bucket Transformations Disabled',
            'transformations' => false,
        ]);

        // Try to get the file preview again
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'] . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);
        $this->assertEquals(403, $preview['headers']['status-code']);
        $this->assertStringContainsString('Image transformations are disabled for the requested bucket.', (string) $preview['body']['message']);

        // Delete the bucket
        $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testFileEncryptionAndCompression(): void
    {
        // Create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::any())
            ],
            'encryption' => true,
            'compression' => 'gzip'
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        // Create file
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'transformations.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);
        $this->assertSame('gzip', $file['body']['compression']);
        $this->assertTrue($file['body']['encryption']);

        // Get file
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'], [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertSame(200, $file['headers']['status-code']);
        $this->assertSame('gzip', $file['body']['compression']);
        $this->assertTrue($file['body']['encryption']);

        // List files
        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files', [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertSame(200, $files['headers']['status-code']);
        $this->assertSame(1, $files['body']['total']);
        $this->assertSame('gzip', $files['body']['files'][0]['compression']);
        $this->assertTrue($files['body']['files'][0]['encryption']);

        // Update the bucket
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Test Bucket',
            'encryption' => false,
            'compression' => 'none'
        ]);

        // Existing file did not update
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'], [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertSame(200, $file['headers']['status-code']);
        $this->assertSame('gzip', $file['body']['compression']);
        $this->assertTrue($file['body']['encryption']);

        // Create 2nd file
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'transformations.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);
        $this->assertSame('none', $file['body']['compression']);
        $this->assertFalse($file['body']['encryption']);

        // Get file
        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'], [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertSame(200, $file['headers']['status-code']);
        $this->assertSame('none', $file['body']['compression']);
        $this->assertFalse($file['body']['encryption']);

        // List files
        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files', [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertSame(200, $files['headers']['status-code']);
        $this->assertSame(2, $files['body']['total']);
        $this->assertSame('none', $files['body']['files'][1]['compression']);
        $this->assertFalse($files['body']['files'][1]['encryption']);
        $this->assertSame('gzip', $files['body']['files'][0]['compression']);
        $this->assertTrue($files['body']['files'][0]['encryption']);

        // Delete the bucket
        $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(204, $response['headers']['status-code']);
    }

    /**
     * Chunked resume (Content-Range + x-appwrite-id) must stay with the user who
     * started the upload, or with a caller who may update the file. A later
     * chunk must not replace the permissions stored on the first chunk.
     */
    public function testCreateFileResumeRequiresOwnership(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Chunked resume ownership',
            'fileSecurity' => true,
            'encryption' => false,
            'compression' => 'none',
            'antivirus' => false,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $other = $this->createUser(Id::unique(), Id::unique() . '@localhost.test', 'password');
        $ownerId = $this->getUser()['$id'];
        $chunkSize = 5 * 1024 * 1024;
        $totalSize = $chunkSize + 1;
        $firstBody = \str_repeat('a', $chunkSize);
        $nextBody = 'x';
        $firstRange = 'bytes 0-' . (\strlen($firstBody) - 1) . '/' . $totalSize;
        $nextRange = 'bytes ' . $chunkSize . '-' . $chunkSize . '/' . $totalSize;

        $ownerHeaders = array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $otherHeaders = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $other['session'],
        ];

        $ownerPermissions = [
            Permission::read(Role::user($ownerId)),
            Permission::delete(Role::user($ownerId)),
        ];
        $otherPermissions = [
            Permission::read(Role::user($other['$id'])),
            Permission::update(Role::user($other['$id'])),
            Permission::delete(Role::user($other['$id'])),
        ];
        $sharedPermissions = [
            Permission::read(Role::user($ownerId)),
            Permission::update(Role::users()),
            Permission::delete(Role::user($ownerId)),
        ];

        $upload = function (array $headers, string $range, string $body, array $permissions, ?string $resumeId = null) use ($bucketId): array {
            // Resume selects the file through x-appwrite-id. The body id is a
            // different value so a request cannot target that file any other way.
            if ($resumeId !== null) {
                $headers['x-appwrite-id'] = $resumeId;
            }

            $headers['content-range'] = $range;

            return $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', $headers, [
                'fileId' => Id::unique(),
                'file' => new CURLFile('data://text/plain;base64,' . base64_encode($body), 'text/plain', 'resume.txt'),
                'permissions' => $permissions,
            ]);
        };

        $read = function (string $fileId) use ($bucketId): array {
            return $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
        };

        $privateFileId = '';
        $sharedFileId = '';

        try {
            /**
             * Test for SUCCESS
             * The owner can start a chunked upload that does not grant them update.
             */
            $created = $upload($ownerHeaders, $firstRange, $firstBody, $ownerPermissions);
            $this->assertEquals(201, $created['headers']['status-code'], $created['body']['message'] ?? '');
            $privateFileId = $created['body']['$id'];
            $this->assertEquals(1, $created['body']['chunksUploaded']);
            $this->assertEquals(2, $created['body']['chunksTotal']);
            $this->assertEqualsCanonicalizing($ownerPermissions, $created['body']['$permissions']);

            /**
             * Test for FAILURE
             * Another user who can create in the bucket cannot resume that file,
             * including by sending their own permissions on the next chunk.
             */
            $hijack = $upload($otherHeaders, $nextRange, $nextBody, $otherPermissions, $privateFileId);
            $this->assertEquals(401, $hijack['headers']['status-code']);
            $this->assertEquals('user_unauthorized', $hijack['body']['type']);
            $this->assertArrayNotHasKey('name', $hijack['body']);
            $this->assertArrayNotHasKey('signature', $hijack['body']);
            $this->assertArrayNotHasKey('mimeType', $hijack['body']);

            $afterHijack = $read($privateFileId);
            $this->assertEquals(200, $afterHijack['headers']['status-code']);
            $this->assertEquals(1, $afterHijack['body']['chunksUploaded']);
            $this->assertEqualsCanonicalizing($ownerPermissions, $afterHijack['body']['$permissions']);

            /**
             * Test for SUCCESS
             * The owner can finish the upload. Permissions from the first chunk
             * stay in place when a later chunk sends a different set.
             */
            $resumed = $upload($ownerHeaders, $nextRange, $nextBody, [
                Permission::read(Role::user($ownerId)),
            ], $privateFileId);
            $this->assertEquals(201, $resumed['headers']['status-code'], $resumed['body']['message'] ?? '');
            $this->assertEquals($privateFileId, $resumed['body']['$id']);
            $this->assertEquals(2, $resumed['body']['chunksUploaded']);
            $this->assertEquals(2, $resumed['body']['chunksTotal']);
            $this->assertEqualsCanonicalizing($ownerPermissions, $resumed['body']['$permissions']);

            $completed = $read($privateFileId);
            $this->assertEquals(200, $completed['headers']['status-code']);
            $this->assertEqualsCanonicalizing($ownerPermissions, $completed['body']['$permissions']);

            /**
             * Test for FAILURE
             * A caller who cannot read or update the finished file must not
             * receive it back from a chunked resume. Reading it is not found.
             */
            $hidden = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $privateFileId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => $otherHeaders['cookie'],
            ]);
            $this->assertEquals(404, $hidden['headers']['status-code']);
            $this->assertEquals('storage_file_not_found', $hidden['body']['type']);

            $replay = $upload($otherHeaders, $nextRange, $nextBody, $otherPermissions, $privateFileId);
            $this->assertEquals(401, $replay['headers']['status-code']);
            $this->assertEquals('user_unauthorized', $replay['body']['type']);
            $this->assertArrayNotHasKey('$id', $replay['body']);
            $this->assertArrayNotHasKey('name', $replay['body']);
            $this->assertArrayNotHasKey('signature', $replay['body']);
            $this->assertArrayNotHasKey('mimeType', $replay['body']);
            $this->assertArrayNotHasKey('sizeOriginal', $replay['body']);
            $this->assertArrayNotHasKey('chunksTotal', $replay['body']);
            $this->assertArrayNotHasKey('$permissions', $replay['body']);

            /**
             * Test for SUCCESS
             * A different user who may update the file can resume it, and still
             * cannot replace the permissions stored on the first chunk.
             */
            $shared = $upload($ownerHeaders, $firstRange, $firstBody, $sharedPermissions);
            $this->assertEquals(201, $shared['headers']['status-code'], $shared['body']['message'] ?? '');
            $sharedFileId = $shared['body']['$id'];
            $this->assertEqualsCanonicalizing($sharedPermissions, $shared['body']['$permissions']);

            $sharedResume = $upload($otherHeaders, $nextRange, $nextBody, $otherPermissions, $sharedFileId);
            $this->assertEquals(201, $sharedResume['headers']['status-code'], $sharedResume['body']['message'] ?? '');
            $this->assertEquals($sharedFileId, $sharedResume['body']['$id']);
            $this->assertEquals(2, $sharedResume['body']['chunksUploaded']);
            $this->assertEqualsCanonicalizing($sharedPermissions, $sharedResume['body']['$permissions']);

            $sharedRead = $read($sharedFileId);
            $this->assertEquals(200, $sharedRead['headers']['status-code']);
            $this->assertEqualsCanonicalizing($sharedPermissions, $sharedRead['body']['$permissions']);
        } finally {
            foreach ([$privateFileId, $sharedFileId] as $fileId) {
                if ($fileId === '') {
                    continue;
                }

                $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }

            $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
        }
    }

    /**
     * A guest has no user id. Content-Range still has to create the file in
     * that first request. A later request from an unrelated guest must not
     * continue it.
     */
    public function testCreateFileChunkedAsGuest(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Guest chunked upload',
            'fileSecurity' => true,
            'encryption' => false,
            'compression' => 'none',
            'antivirus' => false,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $guestHeaders = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
        $chunkSize = 5 * 1024 * 1024;
        $totalSize = $chunkSize + 1;
        $singleBody = 'guest-single';
        $firstBody = 'guest-first';
        $singleFileId = '';
        $partialFileId = '';

        $upload = function (string $range, string $body, ?string $resumeId = null, ?array $permissions = null) use ($bucketId, $guestHeaders): array {
            $headers = $guestHeaders;
            $headers['content-range'] = $range;
            if ($resumeId !== null) {
                $headers['x-appwrite-id'] = $resumeId;
            }

            $params = [
                'fileId' => Id::unique(),
                'file' => new CURLFile('data://text/plain;base64,' . base64_encode($body), 'text/plain', 'guest.txt'),
            ];
            if ($permissions !== null) {
                $params['permissions'] = $permissions;
            }

            return $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', $headers, $params);
        };

        try {
            /**
             * Test for SUCCESS
             * Single-chunk Content-Range from a guest.
             */
            $single = $upload(
                'bytes 0-' . (\strlen($singleBody) - 1) . '/' . \strlen($singleBody),
                $singleBody,
            );
            $this->assertEquals(201, $single['headers']['status-code'], $single['body']['message'] ?? '');
            $singleFileId = $single['body']['$id'];
            $this->assertEquals(1, $single['body']['chunksTotal']);
            $this->assertEquals(1, $single['body']['chunksUploaded']);
            $this->assertEquals([], $single['body']['$permissions']);

            /**
             * Test for SUCCESS
             * First chunk of a larger upload from a guest.
             */
            $partial = $upload(
                'bytes 0-' . (\strlen($firstBody) - 1) . '/' . $totalSize,
                $firstBody,
            );
            $this->assertEquals(201, $partial['headers']['status-code'], $partial['body']['message'] ?? '');
            $partialFileId = $partial['body']['$id'];
            $this->assertEquals(2, $partial['body']['chunksTotal']);
            $this->assertEquals(1, $partial['body']['chunksUploaded']);
            $this->assertEquals([], $partial['body']['$permissions']);

            /**
             * Test for FAILURE
             * Another guest must not resume that incomplete upload.
             */
            $hijack = $upload(
                'bytes ' . $chunkSize . '-' . $chunkSize . '/' . $totalSize,
                'x',
                $partialFileId,
                [Permission::read(Role::any())],
            );
            $this->assertEquals(401, $hijack['headers']['status-code']);
            $this->assertEquals('user_unauthorized', $hijack['body']['type']);

            $stored = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $partialFileId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals(200, $stored['headers']['status-code']);
            $this->assertEquals(1, $stored['body']['chunksUploaded']);
            $this->assertEquals([], $stored['body']['$permissions']);
        } finally {
            foreach ([$singleFileId, $partialFileId] as $fileId) {
                if ($fileId === '') {
                    continue;
                }

                $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }

            $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
        }
    }

}
