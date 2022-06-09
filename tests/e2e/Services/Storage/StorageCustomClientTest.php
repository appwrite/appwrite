<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Exception;
use SebastianBergmann\RecursionContext\InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class StorageCustomClientTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideClient;

    public function testBucketPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => 'unique()',
            'name' => 'Test Bucket',
            'permission' => 'bucket',
            'read' => ['role:all'],
            'write' => ['role:member'],
        ]);

        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucketId);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $file['body']['$id'];
        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($fileId);
        $this->assertIsInt($file['body']['dateCreated']);
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

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
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
            'bucketId' => 'unique()',
            'name' => 'Test Bucket',
            'permission' => 'file',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertContains('user:' . $this->getUser()['$id'], $file['body']['$read']);
        $this->assertContains('user:' . $this->getUser()['$id'], $file['body']['$write']);
        $this->assertIsInt($file['body']['dateCreated']);
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
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => 'xyz',
            'read' => ['user:notme']
        ]);

        $this->assertEquals(400, $file['headers']['status-code']);
        $this->assertStringStartsWith('Read permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => 'xyz',
            'write' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Write permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => 'xyz',
            'read' => ['user:notme'],
            'write' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Read permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
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
            'read' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Read permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'write' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Write permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['user:notme'],
            'write' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Read permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:' . $this->getUser()['$id'], $file['body']['message']);
    }
}
