<?php

namespace Tests\E2E\Services\Storage;

use Appwrite\Auth\Auth;
use CURLFile;
use Exception;
use PharIo\Manifest\Author;
use SebastianBergmann\RecursionContext\InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\DateTime;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

class StorageCustomClientTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideClient;
    use StoragePermissionsScope;

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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file['body']['$createdAt']));
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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file['body']['$createdAt']));
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

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file['body']['$createdAt']));
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->client->call(CLient::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'permissions.png',
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $email = ID::unique() . '@localhost.test';
        $password = 'password';
        $user2 = $this->createUser('user2', $email, $password);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

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
        $team1 = $this->createTeam(ID::unique(), 'Team 1');
        $team2 = $this->createTeam(ID::unique(), 'Team 1');
        $user1 = $this->createUser(ID::unique(), ID::unique() . '@localhost.test', 'password');
        $user2 = $this->createUser(ID::unique(), ID::unique() . '@localhost.test', 'password');

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
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::team(ID::custom($team1['$id']))),
                Permission::read(Role::team(ID::custom($team2['$id']))),
                Permission::create(Role::team(ID::custom($team1['$id']))),
                Permission::update(Role::team(ID::custom($team1['$id']))),
                Permission::delete(Role::team(ID::custom($team1['$id']))),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file['body']['$createdAt']));
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        // Team 2 update failure
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        // Team 2 delete failure
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $fileId = $file1['body']['$id'];
        $this->assertEquals($file1['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file1['body']['$createdAt']));
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
            'fileId' => ID::unique(),
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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::users()),
            ],
        ]);

        $fileId = $file1['body']['$id'];
        $this->assertEquals($file1['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file1['body']['$createdAt']));
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
            'fileId' => ID::unique(),
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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $fileId = $file1['body']['$id'];
        $this->assertEquals($file1['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file1['body']['$createdAt']));
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(401, $file['headers']['status-code']);

        $user2 = $this->createUser(ID::unique(), uniqid() . '@localhost.test', 'password');

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 404);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);
    }

    public function testFileTeamPermissions(): void
    {
        $team1 = $this->createTeam(ID::unique(), 'Team 1');
        $team2 = $this->createTeam(ID::unique(), 'Team 1');
        $user1 = $this->createUser(ID::unique(), ID::unique() . '@localhost.test', 'password');
        $user2 = $this->createUser(ID::unique(), ID::unique() . '@localhost.test', 'password');

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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::read(Role::team(ID::custom($team1['$id']))),
                Permission::read(Role::team(ID::custom($team2['$id']))),
                Permission::update(Role::team(ID::custom($team1['$id']))),
                Permission::delete(Role::team(ID::custom($team1['$id']))),
            ],
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertEquals(true, DateTime::isValid($file['body']['$createdAt']));
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        // Team 2 create failure
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        // Team 2 update failure
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ], [
            'permissions' => [],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

        // Team 2 delete failure
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user2['session'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 401);

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
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(400, $file2['headers']['status-code']);
    }

    public function testCreateFileDefaultPermissions(): array
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
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
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertContains(Permission::read(Role::user($this->getUser()['$id'])), $file['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $file['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $file['body']['$permissions']);
        $this->assertEquals(true, DateTime::isValid($file['body']['$createdAt']));
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        return ['fileId' => $file['body']['$id'], 'bucketId' => $bucket['body']['$id']];
    }

    /**
     * @depends testCreateFileDefaultPermissions
     */
    public function testCreateFileAbusePermissions(array $data): void
    {
        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => ID::custom('xyz'),
            'permissions' => [
                Permission::read(Role::user(ID::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', $file['body']['message']);
        $this->assertStringContainsString('users', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => ID::custom('xyz'),
            'permissions' => [
                Permission::update(Role::user(ID::custom('notme'))),
                Permission::delete(Role::user(ID::custom('notme'))),
            ]
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', $file['body']['message']);
        $this->assertStringContainsString('users', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => ID::custom('xyz'),
            'permissions' => [
                Permission::read(Role::user(ID::custom('notme'))),
                Permission::update(Role::user(ID::custom('notme'))),
                Permission::delete(Role::user(ID::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', $file['body']['message']);
        $this->assertStringContainsString('users', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);
    }

    /**
     * @depends testCreateFileDefaultPermissions
     */
    public function testUpdateFileAbusePermissions(array $data): void
    {
        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user(ID::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', $file['body']['message']);
        $this->assertStringContainsString('users', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::update(Role::user(ID::custom('notme'))),
                Permission::delete(Role::user(ID::custom('notme'))),
            ]
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', $file['body']['message']);
        $this->assertStringContainsString('users', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user(ID::custom('notme'))),
                 Permission::create(Role::user(ID::custom('notme'))),
                    Permission::update(Role::user(ID::custom('notme'))),
                    Permission::delete(Role::user(ID::custom('notme'))),
            ],
        ]);

        $this->assertEquals(401, $file['headers']['status-code']);
        $this->assertStringStartsWith('Permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('any', $file['body']['message']);
        $this->assertStringContainsString('users', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);
    }
}
