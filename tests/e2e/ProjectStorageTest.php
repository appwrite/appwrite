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
        $file = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'], [
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 200);
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
        $this->assertCount(1, $file['body']['$permissions']['read']);
        $this->assertCount(1, $file['body']['$permissions']['write']);

        return $data;
    }
}
