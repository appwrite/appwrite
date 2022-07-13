<?php

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\VideoCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class VideoCustomServerTest extends Scope
{
    use ProjectCustom;
    use VideoCustom;
    use SideServer;

    public function testTranscodeWithSubs(): array
    {

        $response = $this->client->call(Client::METHOD_POST, '/videos', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getVideo()['$id']
        ]);

        $videoId = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name'   => 'hebrew',
            'code'   => 'heb',
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name'   => 'english',
            'code'   => 'eng',
            'default'   => true,
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' =>  $this->getProject()['apiKey'],
        ]);


        $profileId = $response['body']['profiles'][0]['$id'];
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/rendition', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'profileId' => $profileId,
        ]);


        return [
                'videoId' => $videoId,
                'stream' => ''
                ];
    }


    public function testTranscodingRendition(): array
    {

        $response = $this->client->call(Client::METHOD_POST, '/video', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getVideo()['$id']
        ]);

        $videoId = $response['body']['$id'];
        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);


        foreach ($response['body']['profiles'] as $profile) {

            $profileId = $profile['$id'];
            $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/rendition', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'profileId' => $profileId,
            ]);
          }

        return [
            'videoId' => $videoId,
        ];
    }

    /**
     * @depends testTranscodeWithSubs
     */
    public function testGetRendition(array $data): array
    {

        sleep(30);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $data['videoId'] . '/hls/renditions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertNotEmpty($response['body']['renditions']);

        $videoId = $response['body']['renditions'][0]['videoId'];
        $profileId = $response['body']['renditions'][0]['profileId'];
        $profileName = $response['body']['renditions'][0]['name'];
        $stream = $response['body']['renditions'][0]['stream'];

        var_dump($response['body']);

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

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $data['videoId'] . '/' . $data['stream'] . '/master/' . $data['videoId'] . '.m3u8', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        var_dump($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $data['videoId'] . '/' . $data['stream'] . '/' . $data['profileName'] . '/' . $data['videoId'] . '_360p.m3u8', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        var_dump($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $data['videoId'] . '/' . $data['stream'] . '/' . $data['profileName'] . '/' .  $data['videoId'] . '_360p_0000.ts', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        var_dump($response['body']);
    }

//    /**
//     * @depends testTranscodingRendition
//     */
//    public function testDashStreamRender($data): void
//    {
//        sleep(20);
//
//        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $data['videoId'] . '/mpeg-dash/master/' . $data['videoId'] . '.mpd', [
//            'content-type' => 'application/json',
//            'x-appwrite-project' => $this->getProject()['$id'],
//            'x-appwrite-key' => $this->getProject()['apiKey'],
//        ]);
//
//        var_dump($response['body']);
//    }
}
