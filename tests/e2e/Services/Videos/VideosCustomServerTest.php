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
        $this->assertEquals('pending', $response['body']['status']);
        $this->assertNotEmpty($response['body']['name']);
        $this->assertGreaterThanOrEqual(1, $response['body']['chunksTotal']);

        return $response['body']['$id'];
    }

    #[Depends('testCreateVideo')]
    public function testVideoReachesReady(string $videoId): string
    {
        $source = $this->createSource($videoId);
        $this->assertEquals(202, $source['headers']['status-code']);

        $body = $this->waitForVideoReady($videoId);

        $this->assertEquals('ready', $body['status'], 'Video source did not become ready');
        $this->assertEquals($body['chunksTotal'], $body['chunksUploaded']);
        $this->assertGreaterThan(0, $body['duration']);
        $this->assertGreaterThan(0, $body['width']);

        return $videoId;
    }

    /**
     * large-file.mp4 is bigger than one 5 MB upload chunk, so the worker should
     * publish intermediate chunksUploaded values before flipping to ready.
     */
    public function testDownloadReportsChunkProgress(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];
        $this->assertGreaterThanOrEqual(2, $create['body']['chunksTotal']);
        $this->assertEquals('pending', $create['body']['status']);

        $source = $this->createSource($videoId);
        $this->assertEquals(202, $source['headers']['status-code']);

        $uploaded = [];
        $deadline = \time() + 120;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
            $body = $response['body'];
            $uploaded[] = (int) ($body['chunksUploaded'] ?? 0);

            if (!\in_array($body['status'] ?? '', ['pending', 'downloading'], true)) {
                break;
            }

            \usleep(50000);
        }

        $this->assertEquals('ready', $body['status'] ?? '');
        $this->assertSame($body['chunksTotal'], $body['chunksUploaded']);

        for ($i = 1, $n = \count($uploaded); $i < $n; $i++) {
            $this->assertGreaterThanOrEqual($uploaded[$i - 1], $uploaded[$i]);
        }

        $mid = \array_filter(
            $uploaded,
            fn (int $value): bool => $value > 0 && $value < (int) $body['chunksTotal']
        );
        $this->assertNotEmpty($mid, 'Never observed a mid-download chunksUploaded value');
    }

    /**
     * After sprites are up and no encode is in-flight, tryRelease must unlink
     * the tmp source. The HTTP container shares appwrite-videos-tmp so we can
     * stat the file.
     */
    public function testTmpSourceReleasedAfterIdle(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        $this->createSource($videoId);
        $this->waitUntilTmpSourceExists($videoId);
        $ready = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $ready['status']);
        $this->assertFileExists($this->tmpSourcePath($videoId));

        $queued = $this->createTimeline($videoId);
        $this->assertEquals(202, $queued['headers']['status-code']);

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        $this->waitUntilTmpSourceGone($videoId);
        $this->assertFileDoesNotExist($this->tmpSourcePath($videoId));

        $removed = $this->waitForVideoStatus($videoId, 'removed');
        $this->assertEquals('removed', $removed['status']);
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
     * PUT only updates the display name; the source file is immutable.
     */
    #[Depends('testVideoReachesReady')]
    public function testUpdateVideo(string $videoId): string
    {
        $response = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'name' => 'Renamed demo',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($videoId, $response['body']['$id']);
        $this->assertEquals('Renamed demo', $response['body']['name']);
        $this->assertEquals('ready', $response['body']['status']);

        $missing = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), []);
        $this->assertEquals(400, $missing['headers']['status-code']);

        return $videoId;
    }

    /**
     * The sprite timeline is produced after createTimeline is called against a
     * ready video.
     */
    #[Depends('testVideoReachesReady')]
    public function testTimelineAvailable(string $videoId): void
    {
        $queued = $this->createTimeline($videoId);
        $this->assertEquals(202, $queued['headers']['status-code']);

        $response = $this->waitForTimeline($videoId);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', $response['body']);
        $this->assertMatchesRegularExpression('/previews\/[a-zA-Z0-9]+#xywh=\d+,\d+,\d+,\d+/', $response['body']);
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

        // The name is rendered into quote- and line-delimited manifests, so
        // structural characters are rejected by the param allowlist.
        foreach (["evil\",URI=\"http://x/pwn.m3u8", "line1\nline2", 'a<b>'] as $name) {
            $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', $this->headers(), [
                'bucketId' => $this->getVideoBucket()['$id'],
                'fileId' => $this->getSubtitleFile()['$id'],
                'name' => $name,
                'code' => 'eng',
            ]);
            $this->assertEquals(400, $response['headers']['status-code'], 'Subtitle name accepted a structural character');
            $this->assertEquals('general_argument_invalid', $response['body']['type']);
        }
    }

    #[Depends('testCreateSubtitle')]
    public function testListSubtitles(array $subtitle): array
    {
        $body = $this->waitForSubtitleTerminalState($subtitle['videoId'], $subtitle['subtitleId']);
        $this->assertEquals('ready', $body['status'], 'Subtitle did not become ready');

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

        $response = $this->client->call(Client::METHOD_PATCH, '/videos/' . $subtitle['videoId'] . '/subtitles/' . $subtitle['subtitleId'], $this->headers(), [
            'name' => 'Hebrew',
            'code' => 'heb',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Hebrew', $response['body']['name']);
        $this->assertEquals('heb', $response['body']['code']);

        return $subtitle;
    }

    #[Depends('testUpdateSubtitle')]
    public function testDeleteSubtitle(array $subtitle): void
    {
        $videoId = $subtitle['videoId'];
        $subtitleId = $subtitle['subtitleId'];
        $vttPath = $this->subtitleStoragePath($videoId, $subtitleId);
        $this->waitUntilPathExists($vttPath);

        $response = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId . '/subtitles/' . $subtitleId, $this->headers());
        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/videos/' . $videoId . '/subtitles/' . $subtitleId, $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
            'name' => 'Gone',
            'code' => 'eng',
        ]);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('video_subtitle_not_found', $response['body']['type']);

        $this->waitUntilPathGone($vttPath);

        $source = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files/' . $this->getSubtitleFile()['$id'], $this->headers());
        $this->assertEquals(200, $source['headers']['status-code']);
    }

    // -------------------------------------------------------------- renditions

    /**
     * Requesting a rendition returns 202 with the queued document, so the caller
     * has an id to poll. The pre-merge endpoint returned a bare 204.
     */
    #[Depends('testVideoReachesReady')]
    public function testCreateRendition(string $videoId): array
    {
        $this->ensureSourceReady($videoId);

        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profile = null;
        foreach ($profiles['body']['profiles'] as $candidate) {
            if (($candidate['name'] ?? '') === '360p') {
                $profile = $candidate;
                break;
            }
        }
        $this->assertNotNull($profile, 'Seeded 360p profile missing');

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

    /**
     * Renditions may only be created against a fully processed source: while the
     * download is still `waiting`/`started` the endpoint rejects with 400
     * `video_not_ready`, and once the video reaches `ready` the same request is
     * accepted and encodes to completion.
     */
    public function testCreateRenditionRequiresReadyVideo(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $this->assertEquals('pending', $create['body']['status']);
        $videoId = $create['body']['$id'];

        $video = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
        $this->assertEquals(200, $video['headers']['status-code']);
        $this->assertEquals('pending', $video['body']['status']);

        $profile = $this->seededProfile('360p');
        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(400, $rendition['headers']['status-code']);
        $this->assertEquals('video_not_ready', $rendition['body']['type']);

        $this->createSource($videoId);
        $ready = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $ready['status']);

        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $this->assertEquals('waiting', $rendition['body']['status']);

        $body = $this->waitForRenditionTerminalState($videoId, $rendition['body']['$id']);
        $this->assertEquals('ready', $body['status'], 'Rendition queued after the video became ready did not finish');
    }

    /**
     * After the last in-flight rendition finishes, tryRelease drops the tmp
     * source and status becomes `removed`. A later rendition fails until the
     * client calls createSource again.
     */
    public function testCreateRenditionWhenSourceMissing(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];

        $profile = $this->seededProfile('360p');

        $first = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $first['headers']['status-code']);
        $firstBody = $this->waitForRenditionTerminalState($videoId, $first['body']['$id']);
        $this->assertEquals('ready', $firstBody['status']);

        $removed = $this->waitForVideoStatus($videoId, 'removed');
        $this->assertEquals('removed', $removed['status']);

        $second = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'dash',
        ]);
        $this->assertEquals(400, $second['headers']['status-code']);
        $this->assertEquals('video_source_removed', $second['body']['type']);

        $this->createSource($videoId);
        $restored = $this->waitForVideoStatus($videoId, 'ready');
        $this->assertEquals('ready', $restored['status']);

        $retry = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'dash',
        ]);
        $this->assertEquals(202, $retry['headers']['status-code']);
        $this->assertEquals('waiting', $retry['body']['status']);

        $body = $this->waitForRenditionTerminalState($videoId, $retry['body']['$id']);
        $this->assertEquals('ready', $body['status'], 'Rendition queued after createSource did not finish');
    }

    #[Depends('testVideoReachesReady')]
    public function testCreateRenditionValidation(string $videoId): void
    {
        $this->ensureSourceReady($videoId);

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
     * to `ready` once packaging and upload finish.
     */
    #[Depends('testCreateRendition')]
    public function testRenditionReachesTerminalState(array $rendition): array
    {
        $body = $this->waitForRenditionTerminalState($rendition['videoId'], $rendition['renditionId']);

        $this->assertEquals('ready', $body['status'], 'Rendition did not become ready; is appwrite-worker-videos running with ffmpeg?');
        $this->assertNotEmpty($body['startedAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $body['startedAt']);
        $this->assertNotEmpty($body['endedAt']);
        $this->assertEquals('100', $body['progress']);

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

    /**
     * Deleting a rendition drops its row immediately and the deletes worker
     * removes its packaged files. A sibling rendition keeps playing.
     */
    public function testDeleteRendition(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];
        $this->createSource($videoId);
        $this->waitForVideoReady($videoId);

        $firstProfile = $this->seededProfile('360p');
        $secondProfile = $this->seededProfile('480p');

        $first = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $firstProfile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $first['headers']['status-code']);
        $firstId = $first['body']['$id'];

        $second = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $secondProfile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $second['headers']['status-code']);
        $secondId = $second['body']['$id'];

        $firstReady = $this->waitForRenditionTerminalState($videoId, $firstId);
        $secondReady = $this->waitForRenditionTerminalState($videoId, $secondId);
        $this->assertEquals('ready', $firstReady['status']);
        $this->assertEquals('ready', $secondReady['status']);

        $firstDir = $this->renditionStoragePath($videoId, $firstReady['name'], $firstId);
        $secondDir = $this->renditionStoragePath($videoId, $secondReady['name'], $secondId);
        $this->waitUntilPathExists($firstDir);
        $this->waitUntilPathExists($secondDir);
        $this->assertDirectoryHasFiles($firstDir);

        $delete = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId . '/renditions/' . $firstId, $this->headers());
        $this->assertEquals(204, $delete['headers']['status-code']);

        $missing = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions/' . $firstId, $this->headers());
        $this->assertEquals(404, $missing['headers']['status-code']);
        $this->assertEquals('video_rendition_not_found', $missing['body']['type']);

        $playlist = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $firstId . '/streams/0/playlist.m3u8',
            $this->headers()
        );
        $this->assertEquals(404, $playlist['headers']['status-code']);

        $master = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/master.m3u8', $this->headers());
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringNotContainsString($firstId, $master['body']);
        $this->assertStringContainsString($secondId, $master['body']);

        $this->waitUntilPathGone($firstDir);
        $this->assertDirectoryExists($secondDir);
    }

    // ---------------------------------------------------------------- playback

    /**
     * Playback surfaces exist and are routed, but resolve to 404 until a
     * rendition reaches `ready`. Uses a fresh video with no encode jobs so a
     * sibling test that encodes the shared video cannot race this assertion.
     * Also covers the two extension-bearing master manifest paths kept for
     * players that sniff the URI extension.
     */
    public function testPlaybackUnavailableWithoutReadyRendition(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        foreach ([
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
            '/videos/' . $videoId . '/outputs/dash/master.mpd',
            '/videos/' . $videoId . '/outputs/cmaf/master.m3u8',
            '/videos/' . $videoId . '/outputs/cmaf/master.mpd',
        ] as $path) {
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
     * Asserts real encoder output: HLS master/variant playlists, DASH MPD
     * structure, segment bytes, and the WebVTT sprite timeline.
     */
    #[Depends('testRenditionReachesTerminalState')]
    public function testEncodedPlaybackOutput(array $rendition): void
    {
        $videoId = $rendition['videoId'];
        $renditionId = $rendition['renditionId'];

        $master = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/master.m3u8', $this->headers());
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXTM3U', $master['body']);
        $this->assertStringContainsString('#EXT-X-STREAM-INF', $master['body']);
        $this->assertMatchesRegularExpression('#/videos/' . \preg_quote($videoId, '#') . '/outputs/hls/renditions/' . \preg_quote($renditionId, '#') . '/streams/\d+/playlist\.m3u8#', $master['body']);

        if (\preg_match('#renditions/' . \preg_quote($renditionId, '#') . '/streams/(\d+)/playlist\.m3u8#', $master['body'], $matches) !== 1) {
            $this->fail('HLS master playlist did not reference a stream playlist');
        }
        $streamId = $matches[1];

        $variant = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/streams/' . $streamId . '/playlist.m3u8',
            $this->headers()
        );
        $this->assertEquals(200, $variant['headers']['status-code']);
        $this->assertStringContainsString('#EXT-X-TARGETDURATION', $variant['body']);
        $this->assertStringContainsString('#EXTINF', $variant['body']);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $variant['body']);

        if (\preg_match('#/segments/([a-zA-Z0-9]+)(?:\?|$)#', $variant['body'], $segmentMatch) !== 1) {
            $this->fail('HLS variant playlist did not reference a segment');
        }
        $segmentId = $segmentMatch[1];

        $segment = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentId,
            $this->headers()
        );
        $this->assertEquals(200, $segment['headers']['status-code']);
        $this->assertNotEmpty($segment['body']);
        $this->assertStringContainsString('video/mp2t', $segment['headers']['content-type'] ?? '');

        // Ranged fetch of a TS segment: a mid-file slice returns exactly the
        // requested bytes with a matching Content-Range.
        $tsSize = \strlen($segment['body']);
        $this->assertGreaterThan(200, $tsSize);

        $ranged = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentId,
            \array_merge($this->headers(), ['range' => 'bytes=100-199'])
        );
        $this->assertEquals(206, $ranged['headers']['status-code']);
        $this->assertEquals('bytes 100-199/' . $tsSize, $ranged['headers']['content-range'] ?? '');
        $this->assertSame(100, \strlen($ranged['body']));
        $this->assertSame(\substr($segment['body'], 100, 100), $ranged['body']);

        // DASH ladder for the same video.
        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profile = null;
        foreach ($profiles['body']['profiles'] as $candidate) {
            if (($candidate['name'] ?? '') === '360p') {
                $profile = $candidate;
                break;
            }
        }
        $this->assertNotNull($profile, 'Seeded 360p profile missing');

        $this->ensureSourceReady($videoId);

        $dashCreate = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'dash',
        ]);
        $this->assertEquals(202, $dashCreate['headers']['status-code']);
        $dashRenditionId = $dashCreate['body']['$id'];
        $dashBody = $this->waitForRenditionTerminalState($videoId, $dashRenditionId);
        $this->assertEquals('ready', $dashBody['status']);

        $mpd = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/dash/master.mpd', $this->headers());
        $this->assertEquals(200, $mpd['headers']['status-code']);
        $this->assertStringContainsString('<MPD', $mpd['body']);
        $this->assertStringContainsString('<AdaptationSet', $mpd['body']);
        $this->assertStringContainsString('<SegmentList', $mpd['body']);
        $this->assertStringContainsString('<Initialization', $mpd['body']);
        $this->assertStringContainsString('<SegmentURL', $mpd['body']);

        // CMAF: one encode, dual HLS + DASH masters over shared fMP4 segments.
        $this->ensureSourceReady($videoId);
        $cmafCreate = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'cmaf',
        ]);
        $this->assertEquals(202, $cmafCreate['headers']['status-code']);
        $cmafRenditionId = $cmafCreate['body']['$id'];
        $cmafBody = $this->waitForRenditionTerminalState($videoId, $cmafRenditionId);
        $this->assertEquals('ready', $cmafBody['status']);

        $cmafList = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'output' => 'cmaf',
        ]);
        $this->assertEquals(200, $cmafList['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $cmafList['body']['total']);
        $this->assertContains($cmafRenditionId, \array_column($cmafList['body']['renditions'], '$id'));

        $cmafMaster = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/cmaf/master.m3u8', $this->headers());
        $this->assertEquals(200, $cmafMaster['headers']['status-code']);
        $this->assertStringContainsString('#EXT-X-STREAM-INF', $cmafMaster['body']);
        $this->assertMatchesRegularExpression(
            '#/videos/' . \preg_quote($videoId, '#') . '/outputs/cmaf/renditions/' . \preg_quote($cmafRenditionId, '#') . '/streams/\d+/playlist\.m3u8#',
            $cmafMaster['body']
        );

        if (\preg_match('#renditions/' . \preg_quote($cmafRenditionId, '#') . '/streams/(\d+)/playlist\.m3u8#', $cmafMaster['body'], $cmafStreamMatch) !== 1) {
            $this->fail('CMAF HLS master did not reference a stream playlist');
        }
        $cmafStreamId = $cmafStreamMatch[1];

        $cmafVariant = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/cmaf/renditions/' . $cmafRenditionId . '/streams/' . $cmafStreamId . '/playlist.m3u8',
            $this->headers()
        );
        $this->assertEquals(200, $cmafVariant['headers']['status-code']);
        $this->assertStringContainsString('#EXTINF', $cmafVariant['body']);
        $this->assertStringContainsString('#EXT-X-MAP:URI=', $cmafVariant['body']);
        $this->assertMatchesRegularExpression(
            '#/videos/' . \preg_quote($videoId, '#') . '/outputs/cmaf/renditions/' . \preg_quote($cmafRenditionId, '#') . '/segments/#',
            $cmafVariant['body']
        );

        if (\preg_match('#/segments/([a-zA-Z0-9]+)(?:\?|$)#', $cmafVariant['body'], $cmafSegmentMatch) !== 1) {
            $this->fail('CMAF stream playlist did not reference a segment');
        }
        $cmafSegmentId = $cmafSegmentMatch[1];

        $cmafSegment = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/cmaf/renditions/' . $cmafRenditionId . '/segments/' . $cmafSegmentId,
            $this->headers()
        );
        $this->assertEquals(200, $cmafSegment['headers']['status-code']);
        $this->assertNotEmpty($cmafSegment['body']);
        $this->assertStringContainsString('video/iso.segment', $cmafSegment['headers']['content-type'] ?? '');

        // Byte-range playback: iOS/Safari probe segments with single-byte
        // ranges, so bytes=0-0 and the file's final byte must both return 206.
        $segmentSize = \strlen($cmafSegment['body']);
        $this->assertGreaterThan(1, $segmentSize);
        $segmentPath = '/videos/' . $videoId . '/outputs/cmaf/renditions/' . $cmafRenditionId . '/segments/' . $cmafSegmentId;

        $partial = $this->client->call(Client::METHOD_GET, $segmentPath, \array_merge($this->headers(), [
            'range' => 'bytes=0-0',
        ]));
        $this->assertEquals(206, $partial['headers']['status-code']);
        $this->assertEquals('bytes 0-0/' . $segmentSize, $partial['headers']['content-range'] ?? '');
        $this->assertSame(1, \strlen($partial['body']));

        $last = $segmentSize - 1;
        $partial = $this->client->call(Client::METHOD_GET, $segmentPath, \array_merge($this->headers(), [
            'range' => 'bytes=' . $last . '-' . $last,
        ]));
        $this->assertEquals(206, $partial['headers']['status-code']);
        $this->assertEquals('bytes ' . $last . '-' . $last . '/' . $segmentSize, $partial['headers']['content-range'] ?? '');

        // An open-ended range is clamped to the end of the segment.
        $partial = $this->client->call(Client::METHOD_GET, $segmentPath, \array_merge($this->headers(), [
            'range' => 'bytes=0-',
        ]));
        $this->assertEquals(206, $partial['headers']['status-code']);
        $this->assertEquals('bytes 0-' . $last . '/' . $segmentSize, $partial['headers']['content-range'] ?? '');
        $this->assertSame($segmentSize, \strlen($partial['body']));

        // A range starting past the end is not satisfiable.
        $invalid = $this->client->call(Client::METHOD_GET, $segmentPath, \array_merge($this->headers(), [
            'range' => 'bytes=' . $segmentSize . '-' . $segmentSize,
        ]));
        $this->assertEquals(416, $invalid['headers']['status-code']);
        $this->assertEquals('storage_invalid_range', $invalid['body']['type']);

        // Only the bytes unit is supported.
        $invalid = $this->client->call(Client::METHOD_GET, $segmentPath, \array_merge($this->headers(), [
            'range' => 'items=0-1',
        ]));
        $this->assertEquals(416, $invalid['headers']['status-code']);
        $this->assertEquals('storage_invalid_range', $invalid['body']['type']);

        $cmafMpd = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/cmaf/master.mpd', $this->headers());
        $this->assertEquals(200, $cmafMpd['headers']['status-code']);
        $this->assertStringContainsString('<SegmentList', $cmafMpd['body']);
        $this->assertStringContainsString('<SegmentURL', $cmafMpd['body']);
        $this->assertStringContainsString('<Initialization', $cmafMpd['body']);

        $this->createTimeline($videoId);
        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', $timeline['body']);

        if (\preg_match('~previews/([a-zA-Z0-9]+)#xywh=~', $timeline['body'], $previewMatch) === 1) {
            $preview = $this->client->call(
                Client::METHOD_GET,
                '/videos/' . $videoId . '/previews/' . $previewMatch[1],
                $this->headers()
            );
            $this->assertEquals(200, $preview['headers']['status-code']);
            $this->assertNotEmpty($preview['body']);
        }
    }

    // ---------------------------------------------------- embedded subtitles

    /**
     * Timeline extract registers soft text tracks from the source as ready
     * `videos_subtitles` rows (empty fileId) and advertises them on the HLS master.
     */
    public function testExtractEmbeddedSubtitles(): array
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFileWithSubtitles()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        $this->createSource($videoId);
        $this->waitForVideoReady($videoId);
        $this->createTimeline($videoId);
        $this->waitForTimeline($videoId);
        $this->ensureSourceReady($videoId);
        $embedded = $this->waitForEmbeddedSubtitle($videoId);
        $this->assertNotNull($embedded, 'Expected an auto-extracted subtitle after timeline');
        $this->assertEquals('ready', $embedded['status']);
        $this->assertEquals('eng', $embedded['code']);
        $this->assertTrue(($embedded['fileId'] ?? '') === '' || ($embedded['fileId'] ?? null) === null);

        // GET list: confirm the extracted track is registered on the video.
        $list = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $ids = \array_column($list['body']['subtitles'], '$id');
        $this->assertContains($embedded['$id'], $ids);

        $registered = null;
        foreach ($list['body']['subtitles'] as $subtitle) {
            if ($subtitle['$id'] === $embedded['$id']) {
                $registered = $subtitle;
                break;
            }
        }
        $this->assertNotNull($registered);
        $this->assertEquals('ready', $registered['status']);
        $this->assertEquals('eng', $registered['code']);
        $this->assertTrue(($registered['fileId'] ?? '') === '' || ($registered['fileId'] ?? null) === null);

        $vtt = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/dash/subtitles/' . $embedded['$id'] . '/manifest',
            $this->headers()
        );
        $this->assertEquals(200, $vtt['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', $vtt['body']);
        $this->assertStringContainsString('EMBEDDED CUE', $vtt['body']);

        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profile = null;
        foreach ($profiles['body']['profiles'] as $candidate) {
            if (($candidate['name'] ?? '') === '360p') {
                $profile = $candidate;
                break;
            }
        }
        $this->assertNotNull($profile, 'Seeded 360p profile missing');

        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $body = $this->waitForRenditionTerminalState($videoId, $rendition['body']['$id']);
        $this->assertEquals('ready', $body['status'], 'Short fixture encode should succeed');

        $master = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/master.m3u8', $this->headers());
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXT-X-MEDIA:TYPE=SUBTITLES', $master['body']);
        $this->assertStringContainsString('/subtitles/' . $embedded['$id'] . '/manifest', $master['body']);

        return [
            'videoId' => $videoId,
            'subtitleId' => $embedded['$id'],
            'renditionId' => $rendition['body']['$id'],
        ];
    }

    /**
     * An extracted track tagged `und` can be retagged (name + ISO 639-2 code)
     * without replacing the file.
     */
    public function testExtractUndeterminedSubtitleThenUpdateLanguage(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFileWithUndeterminedSubtitles()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        $this->createSource($videoId);
        $ready = $this->waitForVideoReady($videoId, 180);
        $this->assertContains(
            $ready['status'] ?? '',
            ['ready', 'removed'],
            'Source should finish download/extract before listing subtitles, last status: ' . \json_encode($ready)
        );

        $embedded = null;
        $lastList = [];
        $deadline = \time() + 60;
        while (\time() < $deadline) {
            $list = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
            $lastList = $list['body'] ?? [];
            foreach ($lastList['subtitles'] ?? [] as $subtitle) {
                $fileId = $subtitle['fileId'] ?? '';
                if ($fileId === null || $fileId === '') {
                    $embedded = $subtitle;
                    if (($subtitle['status'] ?? '') === 'ready') {
                        break 2;
                    }
                }
            }
            \usleep(500000);
        }
        $this->assertNotNull($embedded, 'Expected an auto-extracted subtitle, last list: ' . \json_encode($lastList));
        $this->assertEquals('ready', $embedded['status']);
        $this->assertEquals('und', $embedded['code']);
        $this->assertTrue(($embedded['fileId'] ?? '') === '' || ($embedded['fileId'] ?? null) === null);

        $response = $this->client->call(Client::METHOD_PATCH, '/videos/' . $videoId . '/subtitles/' . $embedded['$id'], $this->headers(), [
            'name' => 'Hebrew',
            'code' => 'heb',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Hebrew', $response['body']['name']);
        $this->assertEquals('heb', $response['body']['code']);
        $this->assertEquals($embedded['$id'], $response['body']['$id']);
        $this->assertEquals('ready', $response['body']['status']);

        $list = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertEquals(200, $list['headers']['status-code']);
        $byId = \array_column($list['body']['subtitles'], null, '$id');
        $this->assertArrayHasKey($embedded['$id'], $byId);
        $this->assertEquals('heb', $byId[$embedded['$id']]['code']);
        $this->assertEquals('Hebrew', $byId[$embedded['$id']]['name']);
        $this->assertTrue(($byId[$embedded['$id']]['fileId'] ?? '') === '' || ($byId[$embedded['$id']]['fileId'] ?? null) === null);

        $vtt = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/dash/subtitles/' . $embedded['$id'] . '/manifest',
            $this->headers()
        );
        $this->assertEquals(200, $vtt['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', $vtt['body']);
        $this->assertStringContainsString('EMBEDDED CUE', $vtt['body']);
    }

    /**
     * A source with two soft text tracks registers both languages after timeline.
     */
    public function testExtractTwoEmbeddedSubtitles(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFileWithTwoSubtitles()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        $this->createSource($videoId);
        $this->waitForVideoReady($videoId);
        $this->createTimeline($videoId);
        $this->waitForTimeline($videoId);
        $embedded = $this->waitForEmbeddedSubtitles($videoId, 2);
        $this->assertCount(2, $embedded, 'Expected eng and fra auto-extracted subtitles');

        $byCode = [];
        foreach ($embedded as $subtitle) {
            $byCode[$subtitle['code']] = $subtitle;
            $this->assertEquals('ready', $subtitle['status']);
            $this->assertTrue(($subtitle['fileId'] ?? '') === '' || ($subtitle['fileId'] ?? null) === null);
        }

        $this->assertArrayHasKey('eng', $byCode);
        $this->assertArrayHasKey('fra', $byCode);
        $this->assertTrue($byCode['eng']['default'] || $byCode['fra']['default']);
        $this->assertFalse($byCode['eng']['default'] && $byCode['fra']['default'], 'Only one default track');

        $list = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);
        $this->assertEqualsCanonicalizing(
            ['eng', 'fra'],
            \array_values(\array_unique(\array_column($list['body']['subtitles'], 'code')))
        );

        $engVtt = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/dash/subtitles/' . $byCode['eng']['$id'] . '/manifest',
            $this->headers()
        );
        $this->assertEquals(200, $engVtt['headers']['status-code']);
        $this->assertStringContainsString('EMBEDDED CUE EN', $engVtt['body']);

        $fraVtt = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/dash/subtitles/' . $byCode['fra']['$id'] . '/manifest',
            $this->headers()
        );
        $this->assertEquals(200, $fraVtt['headers']['status-code']);
        $this->assertStringContainsString('EMBEDDED CUE FR', $fraVtt['body']);
    }

    /**
     * An uploaded track for the same language replaces the auto-extracted row
     * and is what the master advertises.
     */
    #[Depends('testExtractEmbeddedSubtitles')]
    public function testUploadOverridesExtractedSubtitle(array $extracted): array
    {
        $videoId = $extracted['videoId'];
        $embeddedId = $extracted['subtitleId'];

        $create = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/subtitles', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getOverrideSubtitleFile()['$id'],
            'name' => 'English upload',
            'code' => 'eng',
            'default' => true,
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $uploadId = $create['body']['$id'];

        $ready = $this->waitForSubtitleTerminalState($videoId, $uploadId);
        $this->assertEquals('ready', $ready['status']);
        $this->assertEquals($this->getOverrideSubtitleFile()['$id'], $ready['fileId']);

        // GET list: uploaded track is registered and replaced the embedded eng row.
        $list = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertContains($uploadId, \array_column($list['body']['subtitles'], '$id'));
        $this->assertNotContains($embeddedId, \array_column($list['body']['subtitles'], '$id'));

        $eng = \array_values(\array_filter(
            $list['body']['subtitles'] ?? [],
            fn (array $subtitle): bool => ($subtitle['code'] ?? '') === 'eng'
        ));
        $this->assertCount(1, $eng, 'Upload should replace the embedded eng track');
        $this->assertEquals($uploadId, $eng[0]['$id']);
        $this->assertEquals('ready', $eng[0]['status']);
        $this->assertEquals($this->getOverrideSubtitleFile()['$id'], $eng[0]['fileId']);
        $this->assertEquals('English upload', $eng[0]['name']);
        $this->assertNotEquals($embeddedId, $eng[0]['$id']);

        $vtt = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/dash/subtitles/' . $uploadId . '/manifest',
            $this->headers()
        );
        $this->assertEquals(200, $vtt['headers']['status-code']);
        $this->assertStringContainsString('OVERRIDE CUE', $vtt['body']);
        $this->assertStringNotContainsString('EMBEDDED CUE', $vtt['body']);

        $master = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/hls/master.m3u8', $this->headers());
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('/subtitles/' . $uploadId . '/manifest', $master['body']);
        $this->assertStringNotContainsString('/subtitles/' . $embeddedId . '/manifest', $master['body']);

        return $extracted;
    }

    /**
     * PUT only renames the video; derived artifacts stay in place.
     */
    public function testUpdateNameLeavesDerivedArtifacts(): void
    {
        $ready = $this->createReadyVideo($this->getVideoFileWithSubtitles(), 'Original name');
        $videoId = $ready['$id'];

        $this->createTimeline($videoId);
        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        $embedded = $this->waitForEmbeddedSubtitle($videoId);
        $this->assertNotNull($embedded);
        $subtitleId = $embedded['$id'];

        $this->ensureSourceReady($videoId);

        $profile = $this->seededProfile('360p');
        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $renditionId = $rendition['body']['$id'];
        $encoded = $this->waitForRenditionTerminalState($videoId, $renditionId);
        $this->assertEquals('ready', $encoded['status']);

        $update = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'name' => 'Still the same source',
        ]);
        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('Still the same source', $update['body']['name']);
        $this->assertEquals($this->getVideoFileWithSubtitles()['$id'], $update['body']['fileId']);

        $renditions = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions', $this->headers());
        $this->assertEquals(200, $renditions['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $renditions['body']['total']);
        $this->assertContains($renditionId, \array_column($renditions['body']['renditions'], '$id'));

        $subtitles = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertEquals(200, $subtitles['headers']['status-code']);
        $this->assertContains($subtitleId, \array_column($subtitles['body']['subtitles'], '$id'));
    }

    /**
     * createSource rejects explicitly instead of no-opping: 409 while a
     * download is in flight, 409 once the working copy is ready and on disk.
     */
    public function testCreateSourceConflicts(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        $first = $this->createSource($videoId);
        $this->assertEquals(202, $first['headers']['status-code']);

        // The `downloading` window (chunk copy + probe) lasts seconds; keep
        // retrying until one call lands inside it rather than polling status,
        // which can step over the transient state.
        $seen = [];
        $deadline = \time() + 60;
        $inProgress = null;

        while (\time() < $deadline) {
            $again = $this->createSource($videoId);
            $seen[] = $again['headers']['status-code'];

            if ($again['headers']['status-code'] === 409) {
                $inProgress = $again;
                break;
            }

            \usleep(25000);
        }

        $this->assertNotNull($inProgress, 'Never hit the downloading window; observed: ' . \implode(',', $seen));
        $this->assertEquals('video_source_in_progress', $inProgress['body']['type']);

        $ready = $this->waitForVideoStatus($videoId, 'ready');
        $this->assertEquals('ready', $ready['status']);

        $again = $this->createSource($videoId);
        $this->assertEquals(409, $again['headers']['status-code']);
        $this->assertEquals('video_source_already_exists', $again['body']['type']);
    }

    /**
     * Disk is the truth: when the row says ready but the tmp working copy is
     * gone (crash, manual cleanup), createSource corrects the status to
     * `removed` and re-downloads in the same call instead of refusing.
     */
    public function testCreateSourceHealsMissingWorkingCopy(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];

        $path = $this->tmpSourcePath($videoId);
        $this->assertFileExists($path);
        \unlink($path);

        $again = $this->createSource($videoId);
        $this->assertEquals(202, $again['headers']['status-code']);

        $healed = $this->waitForVideoStatus($videoId, 'ready');
        $this->assertEquals('ready', $healed['status']);
        $this->assertFileExists($path);
    }

    /**
     * Timeline create rejects audio-only sources that have no video track.
     */
    public function testCreateTimelineRejectsAudioOnly(): void
    {
        $ready = $this->createReadyVideo($this->getAudioOnlyFile());
        $videoId = $ready['$id'];
        $this->assertEquals(0, (int) $ready['width']);
        $this->assertEquals(0, (int) $ready['height']);

        $timeline = $this->createTimeline($videoId);
        $this->assertEquals(400, $timeline['headers']['status-code']);
        $this->assertEquals('video_track_not_found', $timeline['body']['type']);
    }

    /**
     * Gated endpoints against every source status, plus not-found and invalid
     * create inputs.
     */
    public function testSourceStatusErrorMatrix(): void
    {
        $unknown = 'doesnotexist';
        foreach (['/source', '/timeline', '/renditions'] as $suffix) {
            $response = $this->client->call(
                Client::METHOD_POST,
                '/videos/' . $unknown . $suffix,
                $this->headers(),
                $suffix === '/renditions' ? ['profileId' => 'x', 'output' => 'hls'] : []
            );
            $this->assertEquals(404, $response['headers']['status-code'], $suffix);
            $this->assertEquals('video_not_found', $response['body']['type'], $suffix);
        }

        $missingBucket = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => 'doesnotexist',
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(404, $missingBucket['headers']['status-code']);
        $this->assertEquals('storage_bucket_not_found', $missingBucket['body']['type']);

        $missingFile = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => 'doesnotexist',
        ]);
        $this->assertEquals(404, $missingFile['headers']['status-code']);
        $this->assertEquals('storage_file_not_found', $missingFile['body']['type']);

        $profile = $this->seededProfile('360p');

        $pending = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $pending['headers']['status-code']);
        $pendingId = $pending['body']['$id'];
        $this->assertEquals('pending', $pending['body']['status']);

        $this->assertGatedEndpointsFail($pendingId, 'video_not_ready', $profile['$id']);

        $this->createSource($pendingId);
        $downloading = $this->client->call(Client::METHOD_GET, '/videos/' . $pendingId, $this->headers());
        if (($downloading['body']['status'] ?? '') === 'downloading') {
            $this->assertGatedEndpointsFail($pendingId, 'video_not_ready', $profile['$id']);
        }
        $this->waitForVideoReady($pendingId);

        $invalid = $this->getInvalidVideoFile();
        $errorCreate = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $invalid['$id'],
        ]);
        $this->assertEquals(201, $errorCreate['headers']['status-code']);
        $errorId = $errorCreate['body']['$id'];
        $this->createSource($errorId);
        $errored = $this->waitForVideoStatus($errorId, 'error');
        $this->assertEquals('error', $errored['status'] ?? null, \json_encode($errored));
        $this->assertGatedEndpointsFail($errorId, 'video_not_ready', $profile['$id']);
    }

    private function assertGatedEndpointsFail(string $videoId, string $type, string $profileId): void
    {
        $timeline = $this->createTimeline($videoId);
        $this->assertEquals(400, $timeline['headers']['status-code']);
        $this->assertEquals($type, $timeline['body']['type']);

        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profileId,
            'output' => 'hls',
        ]);
        $this->assertEquals(400, $rendition['headers']['status-code']);
        $this->assertEquals($type, $rendition['body']['type']);
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

    /**
     * @return array<string, mixed>
     */
    private function seededProfile(string $name): array
    {
        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        foreach ($profiles['body']['profiles'] ?? [] as $candidate) {
            if (($candidate['name'] ?? '') === $name) {
                return $candidate;
            }
        }

        $this->fail('Seeded ' . $name . ' profile missing');
    }

    private function assertDirectoryHasFiles(string $path): void
    {
        $this->assertDirectoryExists($path);
        $entries = \array_values(\array_diff(\scandir($path) ?: [], ['.', '..']));
        $this->assertNotEmpty($entries, 'Expected packaged files in ' . $path);
    }
}
