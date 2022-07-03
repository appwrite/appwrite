<?php

namespace Tests\E2E\Services\Storage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class VideoCustomServerTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateBucketFile(): array
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => 'unique()',
            'name' => 'Test Bucket 2',
            'permission' => 'file',
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        //$source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $source = __DIR__ . "/../../../resources/disk-a/very-large-file-1.mov";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024;
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
            $curlFile = new \CURLFile('data:' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'very-large-file-1.mov');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size) . '/' . $size;

            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'read' => ['role:all'],
                'write' => ['role:all'],
            ]);
            $counter++;

            $this->assertNotEmpty($file['body']['$id']);
            $id = $file['body']['$id'];
        }
        @fclose($handle);

        return [
            'bucketId' => $bucket['body']['$id'],
            'fileId'  => $id,
            ];
    }

    /**
     * @depends testCreateBucketFile
     */
    public function testTranscodingRendition($data): array
    {

        $response = $this->client->call(Client::METHOD_POST, '/video/buckets/' . $data['bucketId'] . '/files/' .  $data['fileId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
       ]);

        $videoId = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/video/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' =>  $this->getProject()['apiKey'],
        ]);


        $profileId = $response['body']['profiles'][0]['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/video/' . $videoId . '/rendition/' .  $profileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        var_dump($response['body']);

//          var_dump($pid);
//          var_dump($key);
//          var_dump($fid);
//          var_dump($bid);
//
////
////        $pid = '62b1956c92e0e39ef577';
////        $key = 'f9b8dd3800b93a3dd513cf7cbcbd436cd61850b2c101662210e8ee2f052f796b3d4c7a08149634ce90da6037f6164d7faa36b32b91b568524e6720014e149b83f7a970c28de1a14a97a69010be325d142d51ca51f0f1b29783a7c1f4689d1b90a42cf19a7b55ec9ea6dc51974a1740b67e71de9f80009c2d91c6f3c686aa616c';
////        $fid = '62b1956e4f03f57a0f74';
////        $bid = '62b1956d0c600d70c8f7';
////
//          $video = $this->client->call(Client::METHOD_POST, '/video/buckets/' . $bid . '/files/' .  $fid, [
//            'content-type' => 'application/json',
//            'x-appwrite-project' => $pid,
//            'x-appwrite-key' => $key,
//        ], [
//            'read' => ['role:all'],
//            'write' => ['role:all']
//        ]);
//        var_dump($video);


        return [
            'videoId' => $videoId,
            'profileId' => $profileId,
        ];
    }

    /**
     * @depends testCreateBucketFile
     */
    public function testRenditions(): void
    {

        $pid = '62b1956c92e0e39ef577';
        $key = 'f9b8dd3800b93a3dd513cf7cbcbd436cd61850b2c101662210e8ee2f052f796b3d4c7a08149634ce90da6037f6164d7faa36b32b91b568524e6720014e149b83f7a970c28de1a14a97a69010be325d142d51ca51f0f1b29783a7c1f4689d1b90a42cf19a7b55ec9ea6dc51974a1740b67e71de9f80009c2d91c6f3c686aa616c';
        $fid = '62b1956e4f03f57a0f74';
        $bid = '62b1956d0c600d70c8f7';


        $renditions = $this->client->call(Client::METHOD_GET, '/video/buckets/' . $bid . '/files/' .  $fid . '/renditions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $pid,
            'x-appwrite-key' =>  $key,
        ]);
         var_dump($renditions['body']);
    }


    public function testPlaylist(): void
    {

        $pid = '62b1956c92e0e39ef577';
        $key = 'f9b8dd3800b93a3dd513cf7cbcbd436cd61850b2c101662210e8ee2f052f796b3d4c7a08149634ce90da6037f6164d7faa36b32b91b568524e6720014e149b83f7a970c28de1a14a97a69010be325d142d51ca51f0f1b29783a7c1f4689d1b90a42cf19a7b55ec9ea6dc51974a1740b67e71de9f80009c2d91c6f3c686aa616c';
        $fid = '62b1956e4f03f57a0f74';
        $bid = '62b1956d0c600d70c8f7';
        $stream = 'hls';

        $renditions = $this->client->call(Client::METHOD_GET, '/video/buckets/' . $bid . '/files/' . $stream . '/' .  $fid, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $pid,
            'x-appwrite-key' =>  $key,
        ]);
        var_dump($renditions['body']);
    }
}
