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
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'uniquie()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertNotEmpty($file['body']['$permissions']);
        $this->assertArrayHasKey('write', $file['body']['$permissions']);
        $this->assertArrayHasKey('read', $file['body']['$permissions']);
        $this->assertContains('user:'.$this->getUser()['$id'], $file['body']['$permissions']['read']);
        $this->assertContains('user:'.$this->getUser()['$id'], $file['body']['$permissions']['write']);
        $this->assertIsInt($file['body']['dateCreated']);
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
    }
}