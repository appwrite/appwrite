<?php

namespace Tests\E2E;

use CURLFile;
use Tests\E2E\Client;

class ProjectStorafeTest extends BaseProjects
{
    public function testRegisterSuccess(): array
    {
        return $this->initProject(['files.read', 'files.write']);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testFileCreateSuccess(array $data): array
    {
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'files' => new CURLFile(realpath(__DIR__ . '/../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body'][0]['$uid']);
        $this->assertEquals('files', $file['body'][0]['$collection']);
        $this->assertIsInt($file['body'][0]['dateCreated']);
        $this->assertEquals('logo.png', $file['body'][0]['name']);
        $this->assertEquals('image/png', $file['body'][0]['mimeType']);
        $this->assertEquals(47218, $file['body'][0]['sizeOriginal']);
        $this->assertEquals(54944, $file['body'][0]['sizeActual']);
        $this->assertEquals('gzip', $file['body'][0]['algorithm']);
        $this->assertEquals('1', $file['body'][0]['fileOpenSSLVersion']);
        $this->assertEquals('aes-128-gcm', $file['body'][0]['fileOpenSSLCipher']);
        $this->assertNotEmpty($file['body'][0]['fileOpenSSLTag']);
        $this->assertNotEmpty($file['body'][0]['fileOpenSSLIV']);

        return array_merge($data, ['fileId' => $file['body'][0]['$uid']]);
    }
    
    /**
     * @depends testFileCreateSuccess
     */
    public function testFileReadSuccess(array $data): array
    {
        $file1 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'], [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($file1['headers']['status-code'], 200);
        $this->assertNotEmpty($file1['body']['$uid']);
        $this->assertIsInt($file1['body']['dateCreated']);
        $this->assertEquals('logo.png', $file1['body']['name']);
        $this->assertEquals('image/png', $file1['body']['mimeType']);
        $this->assertEquals(47218, $file1['body']['sizeOriginal']);
        //$this->assertEquals(54944, $file1['body']['sizeActual']);
        //$this->assertEquals('gzip', $file1['body']['algorithm']);
        //$this->assertEquals('1', $file1['body']['fileOpenSSLVersion']);
        //$this->assertEquals('aes-128-gcm', $file1['body']['fileOpenSSLCipher']);
        //$this->assertNotEmpty($file1['body']['fileOpenSSLTag']);
        //$this->assertNotEmpty($file1['body']['fileOpenSSLIV']);
        $this->assertIsArray($file1['body']['$permissions']['read']);
        $this->assertIsArray($file1['body']['$permissions']['write']);
        $this->assertCount(1, $file1['body']['$permissions']['read']);
        $this->assertCount(1, $file1['body']['$permissions']['write']);

        $file2 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/preview', [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals(200, $file2['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $file2['headers']['content-type']);
        $this->assertNotEmpty($file2['body']);

        $file3 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/download', [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals(200, $file3['headers']['status-code']);
        $this->assertEquals('attachment; filename="logo.png"', $file3['headers']['content-disposition']);
        $this->assertEquals('image/png; charset=UTF-8', $file3['headers']['content-type']);
        $this->assertNotEmpty($file3['body']);

        $file4 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/view', [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals(200, $file4['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $file4['headers']['content-type']);
        $this->assertNotEmpty($file4['body']);

        return $data;
    }
    
    /**
     * @depends testFileReadSuccess
     */
    public function testFileListSuccess(array $data): array
    {
        $files = $this->client->call(Client::METHOD_GET, '/storage/files', [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertEquals(1, $files['body']['sum']);
        $this->assertCount(1, $files['body']['files']);
        
        return $data;
    }

    /**
     * @depends testFileListSuccess
     */
    public function testFileUpdateSuccess(array $data): array
    {
        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['fileId'], [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$uid']);
        $this->assertIsInt($file['body']['dateCreated']);
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
        //$this->assertEquals(54944, $file['body']['sizeActual']);
        //$this->assertEquals('gzip', $file['body']['algorithm']);
        //$this->assertEquals('1', $file['body']['fileOpenSSLVersion']);
        //$this->assertEquals('aes-128-gcm', $file['body']['fileOpenSSLCipher']);
        //$this->assertNotEmpty($file['body']['fileOpenSSLTag']);
        //$this->assertNotEmpty($file['body']['fileOpenSSLIV']);
        $this->assertIsArray($file['body']['$permissions']['read']);
        $this->assertIsArray($file['body']['$permissions']['write']);
        $this->assertCount(0, $file['body']['$permissions']['read']);
        $this->assertCount(0, $file['body']['$permissions']['write']);
        
        return $data;
    }

    /**
     * @depends testFileUpdateSuccess
     */
    public function testFileDeleteSuccess(array $data): array
    {
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/files/' . $data['fileId'], [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);
        
        return $data;
    }
}
