<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

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

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(7, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['bucketsTotal']);
        $this->assertIsNumeric($response['body']['filesTotal']);
        $this->assertIsNumeric($response['body']['filesStorageTotal']);
        $this->assertIsArray($response['body']['buckets']);
        $this->assertIsArray($response['body']['files']);
        $this->assertIsArray($response['body']['storage']);
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

        $this->assertEquals(400, $response['headers']['status-code']);

        // TODO: Uncomment once we implement check for missing bucketId in the usage endpoint.

        $response = $this->client->call(Client::METHOD_GET, '/storage/randomBucketId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/storage/' . $bucketId .  '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(7, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['filesTotal']);
        $this->assertIsNumeric($response['body']['filesStorageTotal']);
        $this->assertIsArray($response['body']['files']);
        $this->assertIsArray($response['body']['storage']);
        $this->assertIsArray($response['body']['imageTransformations']);
        $this->assertIsNumeric($response['body']['imageTransformationsTotal']);
    }
    public function testCreateBucketTransformationsDisabledConsole(): void
    {
        // Create a bucket with default settings
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Console Bucket Transformations Disabled',
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        // Create a file in the bucket
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
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
}
