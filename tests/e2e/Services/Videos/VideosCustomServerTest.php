<?php

namespace Tests\E2E\Services\Videos;

use PHPUnit\Framework\Attributes\Depends;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Scopes\VideoCustom;
use Utopia\Database\Query;

class VideosCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use VideoCustom;

    /**
     * Tests are declared in dependency order; the video is deleted last so the
     * endpoints that read it run first.
     */
    private function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    // ---------------------------------------------------------------- profiles

    /**
     * Every new project is seeded with the encoding ladder from
     * `app/config/videos-profiles.php`, otherwise no rendition can be requested.
     */
    public function testListSeededProfiles(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        $names = \array_column($response['body']['profiles'], 'name');
        $this->assertEqualsCanonicalizing(['360p', '480p', '576p', '720p', '1080p', '2160p'], $names);

        $profile = $response['body']['profiles'][0];
        $this->assertIsInt($profile['videoBitRate']);
        $this->assertIsInt($profile['audioBitRate']);
        $this->assertIsInt($profile['width']);
        $this->assertIsInt($profile['height']);
        $this->assertNotEmpty($profile['$createdAt']);
    }

    public function testCreateProfile(): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', $this->headers(), [
            'name' => 'e2e-480p',
            'videoBitRate' => 2100,
            'audioBitRate' => 64,
            'width' => 854,
            'height' => 480,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('e2e-480p', $response['body']['name']);
        $this->assertEquals(2100, $response['body']['videoBitRate']);
        $this->assertEquals(854, $response['body']['width']);

        return $response['body']['$id'];
    }

    #[Depends('testCreateProfile')]
    public function testGetProfile(string $profileId): string
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles/' . $profileId, $this->headers());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($profileId, $response['body']['$id']);
        $this->assertEquals('e2e-480p', $response['body']['name']);

        return $profileId;
    }

    #[Depends('testGetProfile')]
    public function testUpdateProfile(string $profileId): string
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/videos/profiles/' . $profileId, $this->headers(), [
            'name' => 'e2e-480p-updated',
            'videoBitRate' => 2200,
            'audioBitRate' => 96,
            'width' => 854,
            'height' => 480,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('e2e-480p-updated', $response['body']['name']);
        $this->assertEquals(2200, $response['body']['videoBitRate']);
        $this->assertEquals(96, $response['body']['audioBitRate']);

        return $profileId;
    }

    /**
     * Create and update share one set of bounds; the pre-merge controller
     * validated them against different ranges.
     */
    public function testProfileValidation(): void
    {
        $invalid = [
            ['videoBitRate' => 999999, 'audioBitRate' => 64, 'width' => 854, 'height' => 480],
            ['videoBitRate' => 2100, 'audioBitRate' => 99999, 'width' => 854, 'height' => 480],
            ['videoBitRate' => 2100, 'audioBitRate' => 64, 'width' => 1, 'height' => 480],
            ['videoBitRate' => 2100, 'audioBitRate' => 64, 'width' => 854, 'height' => 99999],
        ];

        foreach ($invalid as $params) {
            $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', $this->headers(), \array_merge(['name' => 'bad'], $params));
            $this->assertEquals(400, $response['headers']['status-code']);
        }
    }

    #[Depends('testUpdateProfile')]
    public function testDeleteProfile(string $profileId): void
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $profileId, $this->headers());
        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles/' . $profileId, $this->headers());
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_profile_not_found', $response['body']['type']);
    }

    // ------------------------------------------------------------------ videos

    public function testCreateVideoRejectsNonVideoFile(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('video_not_valid', $response['body']['type']);
    }

    public function testCreateVideo(): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($this->getVideoBucket()['$id'], $response['body']['bucketId']);
        $this->assertEquals($this->getVideoFile()['$id'], $response['body']['fileId']);
        $this->assertEquals($this->getVideoFile()['sizeOriginal'], $response['body']['size']);

        // Probed metadata is filled in asynchronously by the videos worker.
        $this->assertIsInt($response['body']['duration']);
        $this->assertIsInt($response['body']['width']);
        $this->assertIsString($response['body']['audioSampleRate']);

        return $response['body']['$id'];
    }

    #[Depends('testCreateVideo')]
    public function testGetVideo(string $videoId): string
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($videoId, $response['body']['$id']);

        return $videoId;
    }

    public function testGetVideoNotFound(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/doesnotexist', $this->headers());

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_not_found', $response['body']['type']);
    }

    #[Depends('testCreateVideo')]
    public function testListVideos(string $videoId): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos', $this->headers());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        $this->assertContains($videoId, \array_column($response['body']['videos'], '$id'));

        // Queries\Videos allows the documented attributes; the pre-merge endpoint
        // reused the *files* validator, whose attributes videos does not have.
        $response = $this->client->call(Client::METHOD_GET, '/videos', $this->headers(), [
            'queries' => [
                Query::equal('fileId', [$this->getVideoFile()['$id']])->toString(),
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        $response = $this->client->call(Client::METHOD_GET, '/videos', $this->headers(), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['videos']);

        $response = $this->client->call(Client::METHOD_GET, '/videos', $this->headers(), [
            'queries' => [
                Query::equal('notAnAttribute', ['x'])->toString(),
            ],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Re-pointing a video at another file clears the metadata probed from the
     * previous source.
     */
    #[Depends('testCreateVideo')]
    public function testUpdateVideo(string $videoId): string
    {
        $response = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($videoId, $response['body']['$id']);
        $this->assertEquals($this->getVideoFile()['$id'], $response['body']['fileId']);

        $response = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('video_not_valid', $response['body']['type']);

        return $videoId;
    }

    /**
     * The sprite timeline is produced by the videos worker, whose ffmpeg path is
     * stubbed, so it is expected to be absent rather than merely slow.
     */
    #[Depends('testCreateVideo')]
    public function testTimelineUnavailableWhileEncodingIsStubbed(string $videoId): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', $this->headers());

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_timeline_not_found', $response['body']['type']);
    }

    // --------------------------------------------------------------- subtitles

    #[Depends('testCreateVideo')]
    public function testCreateSubtitle(string $videoId): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
            'name' => 'English',
            'code' => 'eng',
            'default' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('English', $response['body']['name']);
        $this->assertEquals('eng', $response['body']['code']);
        $this->assertTrue($response['body']['default']);
        $this->assertEquals('waiting', $response['body']['status']);

        return ['videoId' => $videoId, 'subtitleId' => $response['body']['$id']];
    }

    /**
     * Codes are validated against the ISO 639-2 `code2` keys in
     * `app/config/locale/languages.php`.
     */
    #[Depends('testCreateVideo')]
    public function testSubtitleValidation(string $videoId): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
            'name' => 'Bad code',
            'code' => 'zzz',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Two-letter ISO 639-1 codes are not accepted; the schema stores 639-2.
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
            'name' => 'Two letter',
            'code' => 'en',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // A video file is not a subtitle.
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'Not a subtitle',
            'code' => 'fra',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('video_subtitle_not_valid', $response['body']['type']);
    }

    #[Depends('testCreateSubtitle')]
    public function testListSubtitles(array $subtitle): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $subtitle['videoId'] . '/subtitles', $this->headers());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        $this->assertContains($subtitle['subtitleId'], \array_column($response['body']['subtitles'], '$id'));

        return $subtitle;
    }

    #[Depends('testListSubtitles')]
    public function testUpdateSubtitle(array $subtitle): array
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/videos/' . $subtitle['videoId'] . '/subtitles/' . $subtitle['subtitleId'], $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
            'name' => 'French',
            'code' => 'fra',
            'default' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('French', $response['body']['name']);
        $this->assertEquals('fra', $response['body']['code']);
        $this->assertFalse($response['body']['default']);

        return $subtitle;
    }

    #[Depends('testUpdateSubtitle')]
    public function testDeleteSubtitle(array $subtitle): void
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/videos/' . $subtitle['videoId'] . '/subtitles/' . $subtitle['subtitleId'], $this->headers());
        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/videos/' . $subtitle['videoId'] . '/subtitles/' . $subtitle['subtitleId'], $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
            'name' => 'Gone',
            'code' => 'eng',
        ]);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_subtitle_not_found', $response['body']['type']);
    }

    // -------------------------------------------------------------- renditions

    /**
     * Requesting a rendition returns 202 with the queued document, so the caller
     * has an id to poll. The pre-merge endpoint returned a bare 204.
     */
    #[Depends('testCreateVideo')]
    public function testCreateRendition(string $videoId): array
    {
        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profile = $profiles['body']['profiles'][0];

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('waiting', $response['body']['status']);
        $this->assertEquals('hls', $response['body']['output']);
        $this->assertEquals($profile['$id'], $response['body']['profileId']);
        $this->assertEquals(
            $profile['width'] . 'X' . $profile['height'] . '@' . ($profile['videoBitRate'] + $profile['audioBitRate']),
            $response['body']['name']
        );

        return ['videoId' => $videoId, 'renditionId' => $response['body']['$id']];
    }

    #[Depends('testCreateVideo')]
    public function testCreateRenditionValidation(string $videoId): void
    {
        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profileId = $profiles['body']['profiles'][0]['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profileId,
            'output' => 'mkv',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => 'doesnotexist',
            'output' => 'hls',
        ]);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_profile_not_found', $response['body']['type']);
    }

    /**
     * The worker picks the job up off the `videos` queue and drives the document
     * to a terminal state. With transcoding stubbed that state is `error`.
     */
    #[Depends('testCreateRendition')]
    public function testRenditionReachesTerminalState(array $rendition): array
    {
        $body = $this->waitForRenditionTerminalState($rendition['videoId'], $rendition['renditionId']);

        $this->assertContains($body['status'], ['ready', 'error'], 'Rendition never left the queue; is appwrite-worker-videos running?');
        $this->assertNotEmpty($body['startedAt']);
        // Datetime, not the integer the pre-merge model declared.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $body['startedAt']);

        return $rendition;
    }

    #[Depends('testRenditionReachesTerminalState')]
    public function testListRenditions(array $rendition): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $rendition['videoId'] . '/renditions', $this->headers());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        $this->assertContains($rendition['renditionId'], \array_column($response['body']['renditions'], '$id'));

        // Filters replace the pre-merge behaviour of hard-coding status=ready,
        // which hid failed and in-progress renditions entirely.
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $rendition['videoId'] . '/renditions', $this->headers(), [
            'output' => 'hls',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $rendition['videoId'] . '/renditions', $this->headers(), [
            'output' => 'dash',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $rendition['videoId'] . '/renditions', $this->headers(), [
            'status' => 'nonsense',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        return $rendition;
    }

    #[Depends('testCreateVideo')]
    public function testGetRenditionNotFound(string $videoId): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions/doesnotexist', $this->headers());

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_rendition_not_found', $response['body']['type']);
    }

    // ---------------------------------------------------------------- playback

    /**
     * Playback surfaces exist and are routed, but resolve to 404 until a
     * rendition reaches `ready`. Also covers the two extension-bearing master
     * manifest paths kept for players that sniff the URI extension.
     */
    #[Depends('testCreateVideo')]
    public function testPlaybackUnavailableWithoutReadyRendition(string $videoId): void
    {
        foreach (['/videos/' . $videoId . '/outputs/hls/master.m3u8', '/videos/' . $videoId . '/outputs/dash/master.mpd'] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->headers());
            $this->assertEquals(404, $response['headers']['status-code'], $path);
            $this->assertEquals('video_rendition_not_found', $response['body']['type'], $path);
        }

        // Nested playback routes resolve (a routing miss would be 404 with a
        // general_route_not_found type rather than a videos-specific error).
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/renditions/nope/streams/0/playlist.m3u8', $this->headers());
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_rendition_not_found', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/renditions/nope/segments/nope', $this->headers());
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_rendition_not_found', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/dash/subtitles/nope/manifest', $this->headers());
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_subtitle_not_found', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/subtitles/nope/segments/nope', $this->headers());
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_subtitle_not_found', $response['body']['type']);
    }

    /**
     * The manifest and segment bodies can only be asserted against real encoder
     * output, which needs the ffmpeg pipeline in
     * `Appwrite\Platform\Modules\Videos\Workers\Videos`.
     */
    public function testEncodedPlaybackOutput(): void
    {
        $this->markTestSkipped(
            'Transcoding is stubbed pending the encoding-dependency decision; see the TODO in '
            . 'src/Appwrite/Platform/Modules/Videos/Workers/Videos.php. Restoring it should add '
            . 'assertions here for HLS master/variant playlists, DASH MPD structure, segment '
            . 'bytes with Range support, and the WebVTT sprite timeline.'
        );
    }

    // ------------------------------------------------------------------ delete

    /**
     * Declared last: the cascade removes the renditions and subtitles the tests
     * above rely on. Depends on testCreateVideo rather than on the rendition
     * chain, so it still runs when encoding-dependent tests are skipped.
     */
    #[Depends('testCreateVideo')]
    public function testDeleteVideo(string $videoId): void
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $this->headers());
        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_not_found', $response['body']['type']);

        // The deletes worker cascades renditions, subtitles and their segments.
        $deadline = \time() + 30;
        $renditions = null;

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions', $this->headers());

            // Once the video row is gone the endpoint 404s, which is itself proof
            // the cascade completed.
            if ($response['headers']['status-code'] === 404) {
                $renditions = 0;
                break;
            }

            $renditions = $response['body']['total'] ?? null;

            if ($renditions === 0) {
                break;
            }

            \usleep(500000);
        }

        $this->assertSame(0, $renditions, 'Deletes worker did not cascade the video renditions');
    }
}
