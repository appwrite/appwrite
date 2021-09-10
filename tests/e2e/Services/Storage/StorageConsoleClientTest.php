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
        $this->assertEquals(count($response['body']), 3);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['storage']);
        $this->assertIsArray($response['body']['files']);
    }

    public function testGetStorageBucketUsage()
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/storage/default/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        // TODO: Uncomment once we implement check for missing bucketId in the usage endpoint.

        // $response = $this->client->call(Client::METHOD_GET, '/storage/randomBucketId/usage', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id']
        // ], $this->getHeaders()), [
        //     'range' => '24h'
        // ]);

        // $this->assertEquals($response['headers']['status-code'], 404);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/storage/default/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 6);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['files.count']);
        $this->assertIsArray($response['body']['files.create']);
        $this->assertIsArray($response['body']['files.read']);
        $this->assertIsArray($response['body']['files.update']);
        $this->assertIsArray($response['body']['files.delete']);
    }
}