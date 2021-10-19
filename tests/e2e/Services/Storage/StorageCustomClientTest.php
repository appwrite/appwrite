<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class StorageCustomClientTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateFileDefaultPermissions():void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()), [
            'bucketId' => 'unique()',
            'name' => 'Test Bucket',
            'permission' => 'file',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/'. $bucket['body']['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertContains('user:'.$this->getUser()['$id'], $file['body']['$read']);
        $this->assertContains('user:'.$this->getUser()['$id'], $file['body']['$write']);
        $this->assertIsInt($file['body']['dateCreated']);
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
    }
}