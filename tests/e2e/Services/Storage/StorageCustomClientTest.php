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

    public function testCreateFileDefaultPermissions(): array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertContains('user:'.$this->getUser()['$id'], $file['body']['$read']);
        $this->assertContains('user:'.$this->getUser()['$id'], $file['body']['$write']);
        $this->assertIsInt($file['body']['dateCreated']);
        $this->assertEquals('permissions.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        return $file['body'];
    }

    public function testCreateFileAbusePermissions(): void
    {
        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'permissions.png'),
            'folderId' => 'xyz',
            'read' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Read permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:'.$this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
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
        $this->assertStringContainsString('user:'.$this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
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
        $this->assertStringContainsString('user:'.$this->getUser()['$id'], $file['body']['message']);
    }

    /**
     * @depends testCreateFileDefaultPermissions
     */
    public function testUpdateFileAbusePermissions(array $data): void
    {
        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Read permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:'.$this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'write' => ['user:notme']
        ]);

        $this->assertEquals($file['headers']['status-code'], 400);
        $this->assertStringStartsWith('Write permissions must be one of:', $file['body']['message']);
        $this->assertStringContainsString('role:all', $file['body']['message']);
        $this->assertStringContainsString('role:member', $file['body']['message']);
        $this->assertStringContainsString('user:'.$this->getUser()['$id'], $file['body']['message']);

        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['$id'], array_merge([
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
        $this->assertStringContainsString('user:'.$this->getUser()['$id'], $file['body']['message']);
    }
}