<?php

namespace Tests\E2E\Services\Webhooks;

use CURLFile;
use Tests\E2E\Client;

trait WebhooksBase
{
    public function testCreateFile():array
    {
        echo 'hello';
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        
        $webhook = $this->getLastRequest();

        var_dump($webhook);

        /**
         * Test for FAILURE
         */
        return ['fileId' => $file['body']['$id']];
    }
    
    // /**
    //  * @depends testCreateFile
    //  */
    // public function testGetFile(array $data):array
    // {
    //     /**
    //      * Test for SUCCESS
    //      */
    //     $file1 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'], array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals($file1['headers']['status-code'], 200);
    //     $this->assertNotEmpty($file1['body']['$id']);
    //     $this->assertIsInt($file1['body']['dateCreated']);
    //     $this->assertEquals('logo.png', $file1['body']['name']);
    //     $this->assertEquals('image/png', $file1['body']['mimeType']);
    //     $this->assertEquals(47218, $file1['body']['sizeOriginal']);
    //     //$this->assertEquals(54944, $file1['body']['sizeActual']);
    //     //$this->assertEquals('gzip', $file1['body']['algorithm']);
    //     //$this->assertEquals('1', $file1['body']['fileOpenSSLVersion']);
    //     //$this->assertEquals('aes-128-gcm', $file1['body']['fileOpenSSLCipher']);
    //     //$this->assertNotEmpty($file1['body']['fileOpenSSLTag']);
    //     //$this->assertNotEmpty($file1['body']['fileOpenSSLIV']);
    //     $this->assertIsArray($file1['body']['$permissions']['read']);
    //     $this->assertIsArray($file1['body']['$permissions']['write']);
    //     $this->assertCount(1, $file1['body']['$permissions']['read']);
    //     $this->assertCount(1, $file1['body']['$permissions']['write']);

    //     $file2 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/preview', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(200, $file2['headers']['status-code']);
    //     $this->assertEquals('image/png', $file2['headers']['content-type']);
    //     $this->assertNotEmpty($file2['body']);

    //     $file3 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/download', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(200, $file3['headers']['status-code']);
    //     $this->assertEquals('attachment; filename="logo.png"', $file3['headers']['content-disposition']);
    //     $this->assertEquals('image/png', $file3['headers']['content-type']);
    //     $this->assertNotEmpty($file3['body']);

    //     $file4 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/view', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(200, $file4['headers']['status-code']);
    //     $this->assertEquals('image/png', $file4['headers']['content-type']);
    //     $this->assertNotEmpty($file4['body']);

    //     /**
    //      * Test for FAILURE
    //      */

    //     return $data;
    // }
    
}