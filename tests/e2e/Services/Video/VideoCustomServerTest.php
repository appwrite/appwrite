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
        $source = __DIR__ . "/../../../resources/disk-a/very-big-file-2.mov";
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


        $this->assertNotEmpty($profileId);

        return [
            'videoId' => $videoId,
            'profileId' => $profileId,
        ];
    }

    /**
     * @depends testTranscodingRendition
     */
    public function testGetRendition(array $data): array
    {

        sleep(30);

        $response = $this->client->call(Client::METHOD_GET, '/video/' . $data['videoId'] . '/hls/renditions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);



        $this->assertNotEmpty($response['body']['renditions']);

        $videoId = $response['body']['renditions'][0]['videoId'];
        $profileId = $response['body']['renditions'][0]['profileId'];
        $profileName = $response['body']['renditions'][0]['name'];
        $stream = $response['body']['renditions'][0]['stream'];


        return [
            'videoId' => $videoId,
            'profileId' => $profileId,
            'profileName' => $profileName,
            'stream' => $stream
        ];
    }

    /**
     * @depends testGetRendition
     */
    public function testHlsStreamRender($data): void
    {
        sleep(20);

        $response = $this->client->call(Client::METHOD_GET, '/video/' . $data['videoId'] . '/' . $data['stream'] . '/master/' . $data['videoId'] . '.m3u8', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/video/' . $data['videoId'] . '/' . $data['stream'] . '/' . $data['profileName'] . '/' . $data['videoId'] . '_360p.m3u8', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        var_dump($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/video/' . $data['videoId'] . '/hls/' . $data['profileName'] . '/' .  $data['videoId'] . '_360p_0000.ts', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        var_dump($response['body']);
    }

    /**
     * @depends testTranscodingRendition
     */
    public function testDashStreamRender($data): void
    {
        sleep(20);

        $response = $this->client->call(Client::METHOD_GET, '/video/' . $data['videoId'] . '/mpeg-dash/master/' . $data['videoId'] . '.mpd', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

        var_dump($response['body']);
    }
}
