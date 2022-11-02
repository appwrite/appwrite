<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\DateTime;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class StorageConsoleClientTest extends Scope
{
    use SideConsole;
    use StorageBase;
    use ProjectCustom;

    public function testGetStorageUsage()
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/storage/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/storage/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(12, count($response['body']));
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['storage']);
        $this->assertIsArray($response['body']['filesCount']);
    }

    public function testGetStorageBucketUsage()
    {
        //create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'permission' => 'file'
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/storage/' . $bucketId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        // TODO: Uncomment once we implement check for missing bucketId in the usage endpoint.

        $response = $this->client->call(Client::METHOD_GET, '/storage/randomBucketId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 404);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/storage/' . $bucketId .  '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 7);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['filesCount']);
        $this->assertIsArray($response['body']['filesCreate']);
        $this->assertIsArray($response['body']['filesRead']);
        $this->assertIsArray($response['body']['filesUpdate']);
        $this->assertIsArray($response['body']['filesDelete']);
        $this->assertIsArray($response['body']['filesStorage']);
    }

    public function testCreateBucketFileWithoutDefaultPermissions(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ["jpg", "png"],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png')
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);

        $file1 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $file['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file1['headers']['status-code']);
        $this->assertCount(0, $file1['body']['$permissions']);
    }
}
