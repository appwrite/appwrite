<?php

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\VideoCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class VideoCustomServerTest extends Scope
{
    use ProjectCustom;
    use VideoCustom;
    use SideServer;

    public function testCreateProfile(): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'My test profile',
            'videoBitrate' => 570,
            'audioBitrate' => 120,
            'width' => 600,
            'height' => 400,
            'protocol' => 'hls',
        ]);

        $profileId = $response['body']['$id'];
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        return $profileId;
    }

    /**
     * @depends testCreateProfile
     */
    public function testUpdateProfile(string $profileId)
    {

        $response = $this->client->call(Client::METHOD_PATCH, '/videos/profiles/' . $profileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'My updated test profile',
            'videoBitrate' => 590,
            'audioBitrate' => 120,
            'width' => 300,
            'height' => 400,
            'protocol' => 'dash',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
    }

    /**
     * @depends testCreateProfile
     */
    public function testGetProfile(string $profileId)
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles/' . $profileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $profileId = $response['body']['$id'];

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('My updated test profile', $response['body']['name']);
        $this->assertEquals(300, $response['body']['width']);
        $this->assertEquals(400, $response['body']['height']);
    }

    /**
     * @depends testCreateProfile
     */
    public function testDeleteProfile(string $profileId)
    {

        $response = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $profileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles/' . $profileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Video profile not found.', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals(3, $response['body']['total']);
    }

    public function testDeleteAllProfiles()
    {

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals(3, $response['body']['total']);

        $profiles = $response['body']['profiles'];
        foreach ($profiles as $profile) {
            $response = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $profile['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals(204, $response['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles/' . $profile['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Video profile not found.', $response['body']['message']);
    }

    /**
     * @depends testDeleteAllProfiles
     */
    public function testCreateVideo(): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getVideo()['$id']
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        return $response['body']['$id'];
    }

    /**
     * @depends testCreateVideo
     */
    public function testCreateSubtitles($videoId)
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'English',
            'code' => 'Eng',
            'default' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'Italian',
            'code' => 'It',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'Hebrew',
            'code' => 'Heb',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
    }

    /**
     * @depends testCreateVideo
     */
    public function testGetSubtitles($videoId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals(3, $response['body']['total']);
        $this->assertNotEmpty($response['body']['subtitles']);
        $this->assertNotEmpty($response['body']['subtitles'][0]['$id']);
        $this->assertEquals('English', $response['body']['subtitles'][0]['name']);
        $this->assertEquals('Eng', $response['body']['subtitles'][0]['code']);
        $this->assertEquals(true, $response['body']['subtitles'][0]['default']);

        return $response['body']['subtitles'];
    }

    /**
     * @depends testGetSubtitles
     */
    public function testUpdateSubtitle($subtitles): array
    {
        $subtitleId = $subtitles[1]['$id'];
        $videoId = $subtitles[1]['videoId'];

        $response = $this->client->call(Client::METHOD_PATCH, '/videos/' . $videoId . '/subtitles/' . $subtitleId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'Polish',
            'code' => 'Pol',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals(3, $response['body']['total']);
        $this->assertNotEmpty($response['body']['subtitles']);
        $this->assertNotEmpty($response['body']['subtitles'][1]['$id']);
        $this->assertEquals('Pol', $response['body']['subtitles'][1]['code']);
        $this->assertEquals('Polish', $response['body']['subtitles'][1]['name']);

        return $response['body']['subtitles'];
    }

    /**
     * @depends testGetSubtitles
     */
    public function testDeleteSubtitle($subtitles)
    {
        $videoId = $subtitles[0]['videoId'];

        foreach ($subtitles as $subtitle) {
            $response = $this->client->call(Client::METHOD_DELETE, '/videos/' . $subtitle['videoId'] . '/subtitles/' . $subtitle['$id'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(204, $response['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateVideo
     */
    public function testTranscodeWithSubs($videoId): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'English',
            'code' => 'Eng',
            'default' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getSubtitle()['$id'],
            'name' => 'Italian',
            'code' => 'It',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        /**
         * Try to transcode with wrong profileId
         */
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/rendition', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'profileId' => $videoId,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Video profile not found', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Profile A',
            'videoBitrate' => 770,
            'audioBitrate' => 64,
            'width' => 600,
            'height' => 400,
            'protocol' => 'hls',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Profile B',
            'videoBitrate' => 570,
            'audioBitrate' => 64,
            'width' => 300,
            'height' => 200,
            'protocol' => 'dash',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        $profiles = $response['body']['profiles'];

        /**
         * Try to transcode with wrong videoId
         */
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $response['body']['profiles'][0]['$id'] . '/rendition', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'profileId' => $response['body']['profiles'][0]['$id'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Video not found', $response['body']['message']);

        foreach ($profiles as $profile) {
            $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/rendition', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'profileId' => $profile['$id'],
            ]);

            $this->assertEquals(204, $response['headers']['status-code']);
        }

        return $videoId;
    }

    /**
     * @depends testTranscodeWithSubs
     */
    public function testGetRenditions(string $videoId): string
    {

        sleep(50);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(2, $response['body']['total']);
        $this->assertNotEmpty($response['body']['renditions']);

        $renditionId = $response['body']['renditions'][0]['$id'];

        foreach ($response['body']['renditions'] as $rendition) {
            $this->assertEquals('ready', $rendition['status']);
            $this->assertEquals('99', $rendition['progress']);
            $this->assertNotEmpty($rendition['videoBitrate']);
            $this->assertNotEmpty($rendition['videoCodec']);
        }

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/rendition/' . $renditionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);


        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($renditionId, $response['body']['$id']);

        return $videoId;
    }

    /**
     * @depends testGetRenditions
     */
    public function testProtocolWithSubs($videoId): string
    {

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/protocols/hls', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(4, count($match[0]));
        $subtitleUri = $match[0][1];
        $renditionUri = $match[0][3];

        $response = $this->client->call(Client::METHOD_GET, $renditionUri, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(12, count($match[0]));

        $segmentUri = $match[0][0];
        $response = $this->client->call(Client::METHOD_GET, $segmentUri, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, strlen($response['body']));

        $response = $this->client->call(Client::METHOD_GET, $subtitleUri, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $segmentUri = $match[0][0];

        $response = $this->client->call(Client::METHOD_GET, $segmentUri, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1508, strlen($response['body']));

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/protocols/dash', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        $xml = simplexml_load_string($response['body']);

        $this->assertEquals("PT1M32.7S", $xml->attributes()->mediaPresentationDuration);
        $this->assertEquals("PT8.0S", $xml->attributes()->maxSegmentDuration);
        $this->assertEquals("PT20.5S", $xml->attributes()->minBufferTime);
        $subsCount = 0;
        $isVideo = false;
        $isAudio = false;
        $subs[] = ['id' => '2', 'lang' => 'Eng',];
        $subs[] = ['id' => '3', 'lang' => 'It',];
        foreach ($xml->Period->AdaptationSet as $adaptation) {
            if ((string)$adaptation['contentType'] === 'video') {
                $isVideo = true;
                $this->assertEquals("24/1", $adaptation['frameRate']);
                $this->assertEquals("300", $adaptation['maxWidth']);
                $this->assertEquals("30:17", $adaptation['par']);
                $this->assertEquals("und", $adaptation['lang']);
                foreach ($adaptation->Representation as $representation) {
                    $this->assertEquals("video/mp4", $representation['mimeType']);
                    $this->assertEquals("avc1.640014", $representation['codecs']);
                    $this->assertEquals("300", $representation['width']);
                    $this->assertEquals("200", $representation['height']);
                    $this->assertEquals("20:17", $representation['sar']);
                    $this->assertEquals(10, $representation->SegmentList->SegmentURL->count());
                    $videoSegmentBaseUrl = (string)$representation->BaseURL;
                    $videoSegmentInitialization = (string)$representation->SegmentList->Initialization['sourceURL'];
                    $videoSegmentId = (string)$representation->SegmentList->SegmentURL['media'];

                    $response = $this->client->call(Client::METHOD_GET, $videoSegmentBaseUrl . $videoSegmentInitialization, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));

                    $response = $this->client->call(Client::METHOD_GET, $videoSegmentBaseUrl . $videoSegmentId, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));
                }
            } elseif ((string)$adaptation['contentType'] === 'audio') {
                $isAudio = true;
                foreach ($adaptation->Representation as $representation) {
                    $this->assertEquals("audio/mp4", $representation['mimeType']);
                    $this->assertEquals("mp4a.40.2", $representation['codecs']);
                    $this->assertEquals(12, $representation->SegmentList->SegmentURL->count());
                    $audioSegmentBaseUrl = (string)$representation->BaseURL;
                    $audioSegmentInitialization = (string)$representation->SegmentList->Initialization['sourceURL'];
                    $audioSegmentId = (string)$representation->SegmentList->SegmentURL['media'];

                    $response = $this->client->call(Client::METHOD_GET, $audioSegmentBaseUrl . $audioSegmentInitialization, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));

                    $response = $this->client->call(Client::METHOD_GET, $audioSegmentBaseUrl . $audioSegmentId, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));
                }
            } elseif ((string)$adaptation['mimeType'] === 'text/vtt') {
                $this->assertEquals($subs[$subsCount]['id'], $adaptation['id']);
                $this->assertEquals($subs[$subsCount]['lang'], $adaptation['lang']);
                $subsCount++;
            }
        }

        $this->assertEquals($subsCount, 2);
        $this->assertTrue($isVideo);
        $this->assertTrue($isAudio);

        return $videoId;
    }


    /**
     * @depends testProtocolWithSubs
     */
    public function testDeleteVideo($videoId): string
    {

        $response = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        return $videoId;
    }

    /**
     * @depends testDeleteVideo
     */
    public function testProtocolWithSubsAgain($videoId): string
    {

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/protocols/hls', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $videoId;
    }

    public function testCreateVideoAgain(): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $this->getBucket()['$id'],
            'fileId' => $this->getVideo()['$id']
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        return $response['body']['$id'];
    }

    /**
     * @depends testCreateVideoAgain
     */
    public function testTranscodeWithoutSubs($videoId)
    {

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        $this->assertNotEmpty($response['body']['profiles']);
        $profiles = $response['body']['profiles'];
        $this->assertEquals(2, count($response['body']['profiles']));

        foreach ($profiles as $profile) {
            $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/rendition', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'profileId' => $profile['$id'],
            ]);

            $this->assertEquals(204, $response['headers']['status-code']);
        }

        return $videoId;
    }

    /**
     * @depends testTranscodeWithoutSubs
     */
    public function testProtocolWithoutSubs($videoId)
    {

        sleep(50);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/protocols/hls', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(2, count($match[0]));

        $renditionUri = $match[0][0];
        $response = $this->client->call(Client::METHOD_GET, $renditionUri, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        preg_match_all('#\b/videos[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response['body'], $match);
        $this->assertEquals(12, count($match[0]));

        $segmentUri = $match[0][0];
        $response = $this->client->call(Client::METHOD_GET, $segmentUri, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, strlen($response['body']));

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/protocols/dash', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        $xml = simplexml_load_string($response['body']);

        $this->assertEquals("PT1M32.7S", $xml->attributes()->mediaPresentationDuration);
        $this->assertEquals("PT8.0S", $xml->attributes()->maxSegmentDuration);
        $this->assertEquals("PT20.5S", $xml->attributes()->minBufferTime);

        $isVideo = false;
        foreach ($xml->Period->AdaptationSet as $adaptation) {
            if ((string)$adaptation['contentType'] === 'video') {
                $isVideo = true;
                $this->assertEquals("24/1", $adaptation['frameRate']);
                $this->assertEquals("300", $adaptation['maxWidth']);
                $this->assertEquals("30:17", $adaptation['par']);
                $this->assertEquals("und", $adaptation['lang']);
                foreach ($adaptation->Representation as $representation) {
                    $this->assertEquals("video/mp4", $representation['mimeType']);
                    $this->assertEquals("avc1.640014", $representation['codecs']);
                    $this->assertEquals("300", $representation['width']);
                    $this->assertEquals("200", $representation['height']);
                    $this->assertEquals("20:17", $representation['sar']);
                    $this->assertEquals(10, $representation->SegmentList->SegmentURL->count());
                    $videoSegmentBaseUrl = (string)$representation->BaseURL;
                    $videoSegmentInitialization = (string)$representation->SegmentList->Initialization['sourceURL'];
                    $videoSegmentId = (string)$representation->SegmentList->SegmentURL['media'];

                    $response = $this->client->call(Client::METHOD_GET, $videoSegmentBaseUrl . $videoSegmentInitialization, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));

                    $response = $this->client->call(Client::METHOD_GET, $videoSegmentBaseUrl . $videoSegmentId, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));
                }
            } elseif ((string)$adaptation['contentType'] === 'audio') {
                $isAudio = true;
                foreach ($adaptation->Representation as $representation) {
                    $this->assertEquals("audio/mp4", $representation['mimeType']);
                    $this->assertEquals("mp4a.40.2", $representation['codecs']);
                    $this->assertEquals(12, $representation->SegmentList->SegmentURL->count());
                    $audioSegmentBaseUrl = (string)$representation->BaseURL;
                    $audioSegmentInitialization = (string)$representation->SegmentList->Initialization['sourceURL'];
                    $audioSegmentId = (string)$representation->SegmentList->SegmentURL['media'];

                    $response = $this->client->call(Client::METHOD_GET, $audioSegmentBaseUrl . $audioSegmentInitialization, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));

                    $response = $this->client->call(Client::METHOD_GET, $audioSegmentBaseUrl . $audioSegmentId, [
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                    ]);

                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertGreaterThan(0, strlen($response['body']));
                }
            }
        }

        $this->assertTrue($isVideo);
        $this->assertTrue($isAudio);
    }


    public function testGetVideos()
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'queries' => [ 'limit(1)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertGreaterThan(0, count($response['body']['videos']));
    }
}
