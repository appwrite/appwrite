<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;

final class StorageConsoleClientTest extends Scope
{
    use SideConsole;
    use StorageBase;
    use ProjectCustom;

    public function testCreateBucketTransformationsDisabledConsole(): void
    {
        // Create a bucket with default settings
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'Test Console Bucket Transformations Disabled',
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        // Create a file in the bucket
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'transformations.png'),
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);

        // Try to get the file preview
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $preview['headers']['status-code']);

        // Update the bucket to disable transformations
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucket['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test Bucket Transformations Disabled',
            'transformations' => false,
        ]);

        // Try to get the file preview again
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $preview['headers']['status-code']); // Returns 200 since image transformations are not counted for console requests

        // Delete the bucket
        $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testFilePermissionNotAutoSetInConsole(): void
    {
        // Create a bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket Permissions',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        // Create a file without providing permissions (console client should not auto-set permissions)
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'test.png'),
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);

        // Verify file permissions are empty (not auto-set for privileged console user)
        $this->assertIsArray($file['body']['$permissions']);
        $this->assertEmpty($file['body']['$permissions']);

        // Clean up: delete the bucket
        $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testFilePreviewWithImpersonation(): void
    {
        $projectId = $this->getProject()['$id'];
        $adminHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $targetId = Id::unique();
        $target = $this->client->call(Client::METHOD_POST, '/users', $adminHeaders, [
            'userId' => $targetId,
            'email' => 'impersonation-storage-target-' . $targetId . '@example.com',
            'password' => 'password123',
            'name' => 'Storage Target',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);

        $actorId = Id::unique();
        $this->client->call(Client::METHOD_POST, '/users', $adminHeaders, [
            'userId' => $actorId,
            'email' => 'impersonation-storage-actor-' . $actorId . '@example.com',
            'password' => 'password123',
            'name' => 'Storage Actor',
        ]);
        $this->client->call(Client::METHOD_PATCH, '/users/' . $actorId . '/impersonator', $adminHeaders, [
            'impersonator' => true,
        ]);

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $adminHeaders, [
            'bucketId' => Id::unique(),
            'name' => 'Impersonation Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::user($targetId)),
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge(
            $adminHeaders,
            ['content-type' => 'multipart/form-data']
        ), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::user($targetId)),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];

        $session = $this->client->call(Client::METHOD_POST, '/users/' . $actorId . '/sessions', $adminHeaders);
        $this->assertEquals(201, $session['headers']['status-code']);

        $sessionHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-session' => $session['body']['secret'],
        ];

        $denied = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', $sessionHeaders);
        $this->assertEquals(404, $denied['headers']['status-code']);

        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', $sessionHeaders, [
            'impersonateuserid' => $targetId,
        ]);
        $this->assertEquals(200, $preview['headers']['status-code']);
        $this->assertEquals('image/png', $preview['headers']['content-type']);
        $this->assertNotEmpty($preview['body']);

        $view = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view', $sessionHeaders, [
            'impersonateuserid' => $targetId,
        ]);
        $this->assertEquals(200, $view['headers']['status-code']);
    }

    public function testConsoleDefaultBucketIsPrivate(): void
    {
        $guest = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
        ];
        $owner = \array_merge($guest, ['cookie' => 'a_session_console=' . $this->createConsoleSession()]);
        $other = \array_merge($guest, ['cookie' => 'a_session_console=' . $this->createConsoleSession()]);

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/default/files', \array_merge($owner, [
            'content-type' => 'multipart/form-data',
        ]), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/default/files/' . $fileId, $owner);
        $this->assertSame(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        foreach ([$guest, $other] as $headers) {
            $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/default/files', $headers);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotContains($fileId, \array_column($response['body']['files'], '$id'));

            $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/default/files/' . $fileId, $headers);
            $this->assertSame(404, $response['headers']['status-code']);

            $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/default/files/' . $fileId . '/download', $headers);
            $this->assertSame(404, $response['headers']['status-code']);

            $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/default/files/' . $fileId, \array_merge($headers, [
                'content-type' => 'application/json',
            ]), [
                'name' => 'renamed.png',
            ]);
            $this->assertSame(401, $response['headers']['status-code']);

            $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/default/files/' . $fileId, $headers);
            $this->assertSame(401, $response['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/default/files', \array_merge($guest, [
            'content-type' => 'multipart/form-data',
        ]), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/default/files/' . $fileId, $owner);
        $this->assertSame(204, $response['headers']['status-code']);
    }

    private function createConsoleSession(): string
    {
        $headers = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ];
        $email = Id::unique() . '@localhost.test';

        $account = $this->client->call(Client::METHOD_POST, '/account', $headers, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertSame(201, $account['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $headers, [
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        return $session['cookies']['a_session_console'];
    }
}
