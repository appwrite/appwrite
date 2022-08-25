<?php

namespace Tests\E2E\Services\Storage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;

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

        $this->assertEquals(400, $response['headers']['status-code']);

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
        $this->assertEquals(13, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsArray($response['body']['filesStorage']);
        $this->assertIsArray($response['body']['filesCount']);
    }

    public function testGetStorageBucketUsage()
    {
        //create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => 'unique()',
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
        $this->assertIsArray($response['body']['filesCount']);
        $this->assertIsArray($response['body']['filesCreate']);
        $this->assertIsArray($response['body']['filesRead']);
        $this->assertIsArray($response['body']['filesUpdate']);
        $this->assertIsArray($response['body']['filesDelete']);
        $this->assertIsArray($response['body']['filesStorage']);
    }
}
