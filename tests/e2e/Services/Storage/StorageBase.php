<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Utopia\Image\Image;

trait StorageBase
{
    public function testCreateFile():array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertIsInt($file['body']['dateCreated']);
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);

        /**
         * Test for FAILURE
         */
        return ['fileId' => $file['body']['$id']];
    }
    
    /**
     * @depends testCreateFile
     */
    public function testGetFile(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $file1 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($file1['headers']['status-code'], 200);
        $this->assertNotEmpty($file1['body']['$id']);
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
        $this->assertIsArray($file1['body']['$read']);
        $this->assertIsArray($file1['body']['$write']);
        $this->assertCount(1, $file1['body']['$read']);
        $this->assertCount(1, $file1['body']['$write']);

        $file2 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file2['headers']['status-code']);
        $this->assertEquals('image/png', $file2['headers']['content-type']);
        $this->assertNotEmpty($file2['body']);
        
        //new image preview features
        $file3 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '45',
        ]);
        

        $this->assertEquals(200, $file3['headers']['status-code']);
        $this->assertEquals('image/png', $file3['headers']['content-type']);
        $this->assertNotEmpty($file3['body']);

        $image = new \Imagick();
        $image->readImageBlob($file3['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());

        $file4 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 200,
            'height' => 80,
            'borderWidth' => '5',
            'borderColor' => 'ff0000',
            'output' => 'jpg',
        ]);
        
        $this->assertEquals(200, $file4['headers']['status-code']);
        $this->assertEquals('image/jpeg', $file4['headers']['content-type']);
        $this->assertNotEmpty($file4['body']);
        
        $image = new \Imagick();
        $image->readImageBlob($file4['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.jpg');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('JPEG', $image->getImageFormat());

        $file5 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file5['headers']['status-code']);
        $this->assertEquals('attachment; filename="logo.png"', $file5['headers']['content-disposition']);
        $this->assertEquals('image/png', $file5['headers']['content-type']);
        $this->assertNotEmpty($file5['body']);

        $file6 = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'] . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file6['headers']['status-code']);
        $this->assertEquals('image/png', $file6['headers']['content-type']);
        $this->assertNotEmpty($file6['body']);

        /**
         * Test for FAILURE
         */

        return $data;
    }
    
    /**
     * @depends testGetFile
     */
    public function testListFiles(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $files = $this->client->call(Client::METHOD_GET, '/storage/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertGreaterThan(0, $files['body']['sum']);
        $this->assertGreaterThan(0, count($files['body']['files']));

        /**
         * Test for FAILURE
         */
        
        return $data;
    }

    /**
     * @depends testListFiles
     */
    public function testUpdateFile(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
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
        $this->assertIsArray($file['body']['$read']);
        $this->assertIsArray($file['body']['$write']);
        $this->assertCount(1, $file['body']['$read']);
        $this->assertCount(1, $file['body']['$write']);
        
        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testUpdateFile
     */
    public function testDeleteFile(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $file['headers']['status-code']);
                
        /**
         * Test for FAILURE
         */
        
        return $data;
    }

    public function testCreateBucket():array
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test Bucket',
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertIsInt($bucket['body']['dateCreated']);
        $this->assertEquals('Test Bucket', $bucket['body']['name']);
        $this->assertEquals(true, $bucket['body']['enabled']);
        $bucketId = $bucket['body']['$id'];
        /**
         * Test for FAILURE
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '',
        ]);
        $this->assertEquals(400, $bucket['headers']['status-code']);

        return ['bucketId' => $bucketId];
    }
}