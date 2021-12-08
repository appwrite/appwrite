<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;

trait StorageBase
{
    public function testCreateBucketFile(): array
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
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ["jpg", "png"],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertIsInt($file['body']['dateCreated']);
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
        $this->assertTrue(md5_file(realpath(__DIR__ . '/../../../resources/logo.png')) != $file['body']['signature']); // should validate that the file is encrypted

        /**
         * Test for Large File above 20MB
         * This should also validate the test for when Bucket encryption
         * is disabled as we are using same test
         */
        $bucket2 = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()), [
            'bucketId' => 'unique()',
            'name' => 'Test Bucket 2',
            'permission' => 'file',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        $this->assertEquals(201, $bucket2['headers']['status-code']);
        $this->assertNotEmpty($bucket2['body']['$id']);
        
        /**
         * Chunked Upload
         */

        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5*1024*1024;
        $handle = @fopen($source, "rb");
        $fileId = 'unique()';
        $mimeType = mime_content_type($source);
        $counter = 0;
        $size = filesize($source);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';
        while (!feof($handle)) {
            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'large-file.mp4');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size) . '/' . $size;
            if(!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }
            $largeFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket2['body']['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'read' => ['role:all'],
                'write' => ['role:all'],
            ]);
            $counter++;
            $id = $largeFile['body']['$id'];
        }
        @fclose($handle);
        
        $this->assertEquals(201, $largeFile['headers']['status-code']);
        $this->assertNotEmpty($largeFile['body']['$id']);
        $this->assertIsInt($largeFile['body']['dateCreated']);
        $this->assertEquals('large-file.mp4', $largeFile['body']['name']);
        $this->assertEquals('video/mp4', $largeFile['body']['mimeType']);
        $this->assertEquals($totalSize, $largeFile['body']['sizeOriginal']);
        $this->assertEquals(md5_file(realpath(__DIR__ . '/../../../resources/disk-a/large-file.mp4')), $largeFile['body']['signature']); // should validate that the file is not encrypted

        /**
         * Test for FAILURE unknown Bucket
         */

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/empty/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        $this->assertEquals(404, $res['headers']['status-code']);

        /**
         * Test for FAILURE file above bucket's file size limit
         */

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-b/kitten-1.png'), 'image/png', 'kitten-1.png'),
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);
        $this->assertEquals('File size not allowed', $res['body']['message']);

        /**
         * Test for FAILURE unsupported bucket file extension
         */

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-a/kitten-3.gif'), 'image/gif', 'kitten-3.gif'),
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);
        $this->assertEquals('File extension not allowed', $res['body']['message']);

        return ['bucketId' => $bucketId, 'fileId' => $file['body']['$id'],  'largeFileId' => $largeFile['body']['$id'], 'largeBucketId' => $bucket2['body']['$id']];
    }

    /**
     * @depends testCreateBucketFile
     */
    public function testListBucketFiles(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertGreaterThan(0, $files['body']['sum']);
        $this->assertGreaterThan(0, count($files['body']['files']));

        /**
         * Test for FAILURE unknown Bucket
         */

        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/empty/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(404, $files['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateBucketFile
     */
    public function testGetBucketFile(array $data): array
    {
        $bucketId = $data['bucketId'];
        /**
         * Test for SUCCESS
         */
        $file1 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file1['headers']['status-code']);
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

        $file2 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file2['headers']['status-code']);
        $this->assertEquals('image/png', $file2['headers']['content-type']);
        $this->assertNotEmpty($file2['body']);

        //new image preview features
        $file3 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
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

        $file4 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
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

        $file5 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file5['headers']['status-code']);
        $this->assertEquals('attachment; filename="logo.png"', $file5['headers']['content-disposition']);
        $this->assertEquals('image/png', $file5['headers']['content-type']);
        $this->assertNotEmpty($file5['body']);

        // Test ranged download
        $file51 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=0-99',
        ], $this->getHeaders()));

        $path = __DIR__ . '/../../../resources/logo.png';
        $originalChunk = \file_get_contents($path, false, null, 0, 100);

        $this->assertEquals(206, $file51['headers']['status-code']);
        $this->assertEquals('attachment; filename="logo.png"', $file51['headers']['content-disposition']);
        $this->assertEquals('image/png', $file51['headers']['content-type']);
        $this->assertNotEmpty($file51['body']);
        $this->assertEquals($originalChunk, $file51['body']);
        
        // Test ranged download - with invalid range
        $file52 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=0-',
        ], $this->getHeaders()));

        $this->assertEquals(206, $file52['headers']['status-code']);
        
        // Test ranged download - with invalid range
        $file53 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=988',
        ], $this->getHeaders()));
        
        $this->assertEquals(416, $file53['headers']['status-code']);
        
        // Test ranged download - with invalid range
        $file54 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=-988',
        ], $this->getHeaders()));
        
        $this->assertEquals(416, $file54['headers']['status-code']);
        
        $file6 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file6['headers']['status-code']);
        $this->assertEquals('image/png', $file6['headers']['content-type']);
        $this->assertNotEmpty($file6['body']);

        // Test for negative angle values in fileGetPreview
        $file7 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '-315',
        ]);
        
        $this->assertEquals(200, $file7['headers']['status-code']);
        $this->assertEquals('image/png', $file7['headers']['content-type']);
        $this->assertNotEmpty($file7['body']);

        $image = new \Imagick();
        $image->readImageBlob($file7['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());

        /**
         * Test large files decompress successfully
         */
        $file7 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['largeBucketId'] . '/files/' . $data['largeFileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $fileData = $file7['body'];
        $this->assertEquals(200, $file7['headers']['status-code']);
        $this->assertEquals('attachment; filename="large-file.mp4"', $file7['headers']['content-disposition']);
        $this->assertEquals('video/mp4', $file7['headers']['content-type']);
        $this->assertNotEmpty($fileData);
        $this->assertEquals(md5_file(realpath(__DIR__ . '/../../../resources/disk-a/large-file.mp4')), md5($fileData)); // validate the file is downloaded correctly

        /**
         * Test for FAILURE unknown Bucket
         */

        $file8 = $this->client->call(Client::METHOD_GET, '/storage/buckets/empty/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 2
        ]);

        $this->assertEquals(404, $file8['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateBucketFile
     */
    public function testUpdateBucketFile(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['role:all', 'user:x'],
            'write' => ['role:all', 'user:x'],
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
        $this->assertCount(2, $file['body']['$read']);
        $this->assertCount(2, $file['body']['$write']);

        /**
         * Test for FAILURE unknown Bucket
         */

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/empty/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['role:all', 'user:x'],
            'write' => ['role:all', 'user:x'],
        ]);

        $this->assertEquals(404, $file['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateBucketFile
     */
    public function testDeleteBucketFile(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
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

        return $data;
    }

}
