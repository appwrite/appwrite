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
        $this->assertContains($response['body']['status'], ['waiting', 'started']);
        $this->assertGreaterThanOrEqual(1, $response['body']['chunksTotal']);

        return $response['body']['$id'];
    }

    #[Depends('testCreateVideo')]
    public function testVideoReachesReady(string $videoId): string
    {
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

        $uploaded = [];
        $deadline = \time() + 120;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
            $body = $response['body'];
            $uploaded[] = (int) ($body['chunksUploaded'] ?? 0);

            if (!\in_array($body['status'] ?? '', ['waiting', 'started'], true)) {
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

        $this->waitUntilTmpSourceExists($videoId);
        $ready = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $ready['status']);
        $this->assertFileExists($this->tmpSourcePath($videoId));

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        $this->waitUntilTmpSourceGone($videoId);
        $this->assertFileDoesNotExist($this->tmpSourcePath($videoId));
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
    #[Depends('testVideoReachesReady')]
    public function testUpdateVideo(string $videoId): string
    {
        $response = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($videoId, $response['body']['$id']);
        $this->assertEquals($this->getVideoFile()['$id'], $response['body']['fileId']);
        $this->assertContains($response['body']['status'], ['waiting', 'started']);

        $body = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $body['status']);
        $this->assertGreaterThan(0, $body['duration']);

        $response = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getSubtitleFile()['$id'],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('video_not_valid', $response['body']['type']);

        return $videoId;
    }

    /**
     * The sprite timeline is produced asynchronously by the videos worker after
     * createVideo enqueues a Timeline job.
     */
    #[Depends('testVideoReachesReady')]
    public function testTimelineAvailable(string $videoId): void
    {
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
    #[Depends('testCreateVideo')]
    public function testCreateRendition(string $videoId): array
    {
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
     * Creating a rendition while the source is still downloading (`waiting` /
     * `started`) must leave the row `waiting` and still reach `ready`. This
     * hangs forever if ready-then-scan / insert-then-read is inverted.
     */
    public function testCreateRenditionWhileSourceDownloading(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $this->assertContains($create['body']['status'], ['waiting', 'started']);
        $videoId = $create['body']['$id'];

        $video = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
        $this->assertEquals(200, $video['headers']['status-code']);
        $this->assertContains($video['body']['status'], ['waiting', 'started']);
        $this->assertNotEquals('ready', $video['body']['status']);

        $profile = $this->seededProfile('360p');
        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $this->assertEquals('waiting', $rendition['body']['status']);

        $body = $this->waitForRenditionTerminalState($videoId, $rendition['body']['$id']);
        $this->assertEquals('ready', $body['status'], 'Rendition queued while source was still downloading did not finish');
    }

    /**
     * After timeline (and any first encode) finish, tryRelease drops the tmp
     * source. A later rendition still sees video.status = ready, so HTTP
     * enqueues Encode; assertSource re-queues Download. The row must not hang.
     */
    public function testCreateRenditionWhenSourceMissing(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];

        $ready = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $ready['status']);

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        $profile = $this->seededProfile('360p');

        // First encode finishes and tryRelease deletes the tmp source because
        // nothing else is in-flight.
        $first = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $first['headers']['status-code']);
        $firstBody = $this->waitForRenditionTerminalState($videoId, $first['body']['$id']);
        $this->assertEquals('ready', $firstBody['status']);

        $video = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
        $this->assertEquals('ready', $video['body']['status']);

        $second = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'dash',
        ]);
        $this->assertEquals(202, $second['headers']['status-code']);
        $this->assertEquals('waiting', $second['body']['status']);

        $body = $this->waitForRenditionTerminalState($videoId, $second['body']['$id']);
        $this->assertEquals('ready', $body['status'], 'Rendition queued after tmp source was released did not finish');
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

        $cmafMpd = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/outputs/cmaf/master.mpd', $this->headers());
        $this->assertEquals(200, $cmafMpd['headers']['status-code']);
        $this->assertStringContainsString('<SegmentList', $cmafMpd['body']);
        $this->assertStringContainsString('<SegmentURL', $cmafMpd['body']);
        $this->assertStringContainsString('<Initialization', $cmafMpd['body']);

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

        $this->waitForTimeline($videoId);
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
     * PUT wipes every derived artifact and restarts the pipeline. Lists are empty
     * immediately; packaged files disappear once the deletes worker runs.
     */
    public function testUpdateSourceResetsVideo(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFileWithSubtitles()['$id'],
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];
        $this->waitForVideoReady($videoId);

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $previousPreviewIds = $this->timelinePreviewIds((string) $timeline['body']);
        $this->assertNotEmpty($previousPreviewIds);

        $embedded = $this->waitForEmbeddedSubtitle($videoId);
        $this->assertNotNull($embedded);
        $subtitleId = $embedded['$id'];
        $vttPath = $this->subtitleStoragePath($videoId, $subtitleId);
        $this->waitUntilPathExists($vttPath);

        $profile = $this->seededProfile('360p');
        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $renditionId = $rendition['body']['$id'];
        $ready = $this->waitForRenditionTerminalState($videoId, $renditionId);
        $this->assertEquals('ready', $ready['status']);
        $renditionDir = $this->renditionStoragePath($videoId, $ready['name'], $renditionId);
        $this->waitUntilPathExists($renditionDir);
        $this->assertDirectoryHasFiles($renditionDir);

        $beforeRenditions = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions', $this->headers());
        $this->assertGreaterThanOrEqual(1, $beforeRenditions['body']['total']);
        $beforeSubtitles = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertGreaterThanOrEqual(1, $beforeSubtitles['body']['total']);

        $update = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals($this->getVideoFile()['$id'], $update['body']['fileId']);

        $renditions = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions', $this->headers());
        $this->assertEquals(200, $renditions['headers']['status-code']);
        $this->assertEquals(0, $renditions['body']['total']);

        $subtitles = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', $this->headers());
        $this->assertEquals(200, $subtitles['headers']['status-code']);
        $this->assertEquals(0, $subtitles['body']['total']);

        $goneRendition = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions/' . $renditionId, $this->headers());
        $this->assertEquals(404, $goneRendition['headers']['status-code']);

        $this->waitUntilPathGone($renditionDir);
        $this->waitUntilPathGone($vttPath);

        $this->waitForVideoReady($videoId);
        $regenerated = $this->waitForTimelineRegenerated($videoId, $previousPreviewIds);
        $this->assertEquals(200, $regenerated['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $regenerated['body']);
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
