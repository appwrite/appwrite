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

    public function testTranscoding(): array
    {


//        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
//            'content-type' => 'application/json',
//            'x-appwrite-project' => $this->getProject()['$id'],
//            'x-appwrite-key' => $this->getProject()['apiKey'],
//        ], [
//            'bucketId' => 'unique()',
//            'name' => 'Test Bucket 2',
//            'permission' => 'file',
//            'read' => ['role:all'],
//            'write' => ['role:all']
//        ]);
//
//        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
//        $totalSize = \filesize($source);
//        $chunkSize = 5 * 1024 * 1024;
//        $handle = @fopen($source, "rb");
//        $fileId = 'unique()';
//        $mimeType = mime_content_type($source);
//        $counter = 0;
//        $size = filesize($source);
//        $headers = [
//            'content-type' => 'multipart/form-data',
//            'x-appwrite-project' => $this->getProject()['$id']
//        ];
//        $id = '';
//
//        while (!feof($handle)) {
//            $curlFile = new \CURLFile('data:' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'in1.mp4');
//            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size) . '/' . $size;
//
//            if (!empty($id)) {
//                $headers['x-appwrite-id'] = $id;
//            }
//
//            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
//                'fileId' => $fileId,
//                'file' => $curlFile,
//                'read' => ['role:all'],
//                'write' => ['role:all'],
//            ]);
//            $counter++;
//            $id = $file['body']['$id'];
//        }
//        @fclose($handle);
//
//          $pid = $this->getProject()['$id'];
//          $key = $this->getProject()['apiKey'];
//          $fid = $id;
//          $bid = $bucket['body']['$id'];
//
//          var_dump($pid);
//          var_dump($key);
//          var_dump($fid);
//          var_dump($bid);


        $pid = '62aaf3408decb0f5a0b3';
        $key = '6e8bf2fd07e5206a9b90efbc3dfbf0794a8e35810838e6b906f0c651a510924b8cf0c535b3a4e84b0330344153a4d8d5e413a0d9314f6955a4b2693633ab120d4f674dd668820d4c195d3006bd814003de18dc2161d7ce639a03cd37fd6fa14151445eddb5c9a294ddf16276ec97d56ecb7275eddab3517254bc7201688d8f47';
        $fid = '62aaf3423b91f5660b04';
        $bid = '62aaf340e9c06064668d';

        $transcoding = $this->client->call(Client::METHOD_POST, '/video/buckets/' . $bid . '/files/' .  $fid, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $pid,
            'x-appwrite-key' => $key,
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        return [
            'projectId' => $pid,
            'apiKey' => $key,
            'bucketId' => $bid,
            'fileId' => $fid,
        ];
    }


    public function testRenditions(): void
    {

        $pid = '62aaf3408decb0f5a0b3';
        $key = '6e8bf2fd07e5206a9b90efbc3dfbf0794a8e35810838e6b906f0c651a510924b8cf0c535b3a4e84b0330344153a4d8d5e413a0d9314f6955a4b2693633ab120d4f674dd668820d4c195d3006bd814003de18dc2161d7ce639a03cd37fd6fa14151445eddb5c9a294ddf16276ec97d56ecb7275eddab3517254bc7201688d8f47';
        $fid = '62aaf3423b91f5660b04';
        $bid = '62aaf340e9c06064668d';


        $renditions = $this->client->call(Client::METHOD_GET, '/video/buckets/' . $bid . '/files/' .  $fid . '/renditions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $pid,
            'x-appwrite-key' =>  $key,
        ]);
         var_dump($renditions['body']);
    }


    public function testPlaylist(): void
    {

        $pid = '62aaf3408decb0f5a0b3';
        $key = '6e8bf2fd07e5206a9b90efbc3dfbf0794a8e35810838e6b906f0c651a510924b8cf0c535b3a4e84b0330344153a4d8d5e413a0d9314f6955a4b2693633ab120d4f674dd668820d4c195d3006bd814003de18dc2161d7ce639a03cd37fd6fa14151445eddb5c9a294ddf16276ec97d56ecb7275eddab3517254bc7201688d8f47';
        $fid = '62aaf3423b91f5660b04';
        $bid = '62aaf340e9c06064668d';
        $stream = 'dash';

        $renditions = $this->client->call(Client::METHOD_GET, '/video/buckets/' . $bid . '/files/' . $stream . '/' .  $fid, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $pid,
            'x-appwrite-key' =>  $key,
        ]);
        var_dump($renditions['body']);
    }
}
