<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Videos;

use PHPUnit\Framework\Attributes\Depends;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\VideoCustom;
use Tests\E2E\Services\Realtime\RealtimeBase;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

/**
 * Client-side access control for the Videos API.
 *
 * Server-side behaviour lives in VideosCustomServerTest; this suite only covers
 * what changes when the caller is a session (or nobody at all).
 */
final class VideosCustomClientTest extends Scope
{
    use ProjectCustom;
    // Only the websocket helpers are wanted here; the trait's generic
    // connection tests belong to the Realtime suite, so demote them below
    // public and PHPUnit will not run them in this class.
    use RealtimeBase {
        testConnection as protected;
        testConnectionSuccessMissingChannels as protected;
        testConnectionFailureUnknownProject as protected;
        testConnectionRegionCheck as protected;
    }
    use SideClient;
    use VideoCustom;
    use VideosPermissionsScope;

    private function websocketHeaders(): array
    {
        $user = $this->getUser();

        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . ($user['session'] ?? ''),
        ];
    }

    private function sessionHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    private function serverHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    private function anonymousHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
    }

    /**
     * Guests hold `videos.play` only. Metadata routes still require
     * `videos.read` / `videos.write` and must fail at the scope guard.
     * Play URLs (timeline, manifests) are not in this list — they pass
     * the scope check and then 404 on an unknown id.
     */
    public function testGuestsCannotReachVideos(): void
    {
        $paths = [
            '/videos',
            '/videos/codecs',
            '/videos/someVideoId',
            '/videos/someVideoId/captions',
            '/videos/someVideoId/renditions',
        ];

        foreach ($paths as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());

            $this->assertEquals(401, $response['headers']['status-code'], $path . ' is reachable by guests');
            $this->assertEquals('general_unauthorized_scope', $response['body']['type'], $path);
        }
    }

    public function testGuestPlayUnknownVideoNotFound(): void
    {
        foreach ([
            '/videos/doesnotexist/timeline',
            '/videos/doesnotexist/outputs/hls/master.m3u8',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());

            $this->assertEquals(404, $response['headers']['status-code'], $path);
            $this->assertEquals('video_not_found', $response['body']['type'], $path);
        }
    }

    public function testGuestsCannotWriteVideos(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos', $this->anonymousHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/project/profiles', $this->anonymousHeaders(), [
            'name' => 'guest',
            'videoBitRate' => 1000,
            'audioBitRate' => 64,
            'width' => 640,
            'height' => 360,
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);
    }

    /**
     * A session holds videos.read, and the fixture bucket is readable by anyone,
     * so the source-file check in Base::assertFileAccess() passes.
     */
    public function testSessionCanReadVideo(): string
    {
        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);

        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($videoId, $response['body']['$id']);

        return $videoId;
    }

    /**
     * The list endpoint reads with authorization skipped — a cross-bucket
     * listing cannot express per-file access checks — so it is gated to
     * admin/API-key callers. A session holding videos.read must not be able
     * to enumerate every video in the project regardless of file permissions.
     */
    public function testSessionCannotListVideos(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos', $this->sessionHeaders());

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/videos', $this->serverHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     * Video access is derived from the source bucket/file, not from the video
     * document — video rows are project-internal and carry no permissions of
     * their own. A video whose bucket grants the caller nothing must therefore
     * be unreadable, even though the row itself is perfectly readable.
     */
    public function testSessionCannotReadVideoInRestrictedBucket(): string
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->serverHeaders(), [
            'bucketId' => 'unique()',
            'name' => 'Private videos bucket',
            'fileSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        // An API key bypasses the bucket ACL, so the video is created fine.
        // The session used by this suite cannot write there, hence the override.
        $file = $this->uploadVideoTo($bucket['body']['$id'], [], [
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $bucket['body']['$id'],
            'fileId' => $file['$id'],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        // The session is not granted anything by that bucket. Play routes
        // also fail at file ACL, not missing scope.
        foreach ([
            '/videos/' . $videoId,
            '/videos/' . $videoId . '/captions',
            '/videos/' . $videoId . '/renditions',
            '/videos/' . $videoId . '/timeline',
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->sessionHeaders());
            $this->assertEquals(401, $response['headers']['status-code'], $path . ' leaked a video from a private bucket');
            $this->assertEquals('user_unauthorized', $response['body']['type'], $path);
        }

        return $videoId;
    }

    #[Depends('testSessionCannotReadVideoInRestrictedBucket')]
    public function testGuestCannotPlayPrivateVideo(string $videoId): void
    {
        foreach ([
            '/videos/' . $videoId . '/timeline',
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
            $this->assertEquals(401, $response['headers']['status-code'], $path);
            $this->assertEquals('user_unauthorized', $response['body']['type'], $path);
        }
    }

    /**
     * Profiles are project configuration, managed under the project API.
     * A session holding `videos.write` does not receive `project.profiles.*`.
     */
    public function testSessionCannotManageProfiles(): void
    {
        $payload = [
            'name' => 'from-session',
            'videoBitRate' => 1000,
            'audioBitRate' => 64,
            'width' => 640,
            'height' => 360,
        ];

        $create = $this->client->call(Client::METHOD_POST, '/project/profiles', $this->sessionHeaders(), $payload);
        $this->assertEquals(401, $create['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $create['body']['type']);

        $seeded = $this->client->call(Client::METHOD_POST, '/project/profiles', $this->serverHeaders(), $payload);
        $this->assertEquals(201, $seeded['headers']['status-code']);
        $profileId = $seeded['body']['$id'];

        $update = $this->client->call(Client::METHOD_PATCH, '/project/profiles/' . $profileId, $this->sessionHeaders(), $payload);
        $this->assertEquals(401, $update['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $update['body']['type']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/project/profiles/' . $profileId, $this->sessionHeaders());
        $this->assertEquals(401, $delete['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $delete['body']['type']);

        $cleanup = $this->client->call(Client::METHOD_DELETE, '/project/profiles/' . $profileId, $this->serverHeaders());
        $this->assertEquals(204, $cleanup['headers']['status-code']);
    }

    public function testSessionCannotReadProfiles(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->sessionHeaders());

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $response['body']['type']);
    }

    public function testSessionCanReadCodecs(): void
    {
        $guest = $this->client->call(Client::METHOD_GET, '/videos/codecs', $this->anonymousHeaders());
        $this->assertEquals(401, $guest['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/codecs', $this->sessionHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertCount(1, $response['body']['codecs']);
        $this->assertEquals('h264', $response['body']['codecs'][0]['$id']);
        $this->assertEqualsCanonicalizing(['hls', 'dash', 'cmaf'], $response['body']['codecs'][0]['outputs']);
    }

    /**
     * Realtime rendition events inherit the source bucket/file read roles.
     * The fixture bucket grants read("any"), so a session subscribed to the
     * `videos` channel must receive rendition processing events.
     */
    public function testRealtimeEventsDeliveredForReadableSource(): void
    {
        $client = $this->getWebsocket(['videos'], $this->websocketHeaders());
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? '');

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        $timeline = $this->receiveUntilEvent(
            $client,
            fn (array $message) => ($message['type'] ?? '') === 'event'
                && \in_array(
                    'videos.' . $videoId . '.timeline.update',
                    $message['data']['events'] ?? [],
                    true
                )
        );
        $this->assertSame($videoId, $timeline['data']['payload']['$id'] ?? '');

        $profiles = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->serverHeaders());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $profileId = $profiles['body']['profiles'][0]['$id'] ?? '';
        $this->assertNotEmpty($profileId);

        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->serverHeaders(), [
            'profileId' => $profileId,
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $renditionId = $rendition['body']['$id'];

        $event = $this->receiveUntilEvent(
            $client,
            fn (array $message) => ($message['type'] ?? '') === 'event'
                && ($message['data']['payload']['$id'] ?? '') === $renditionId
        );
        $this->assertContains(
            'videos.' . $videoId . '.renditions.' . $renditionId . '.update',
            $event['data']['events'] ?? []
        );

        $caption = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/captions', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getCaptionFile()['$id'],
            'name' => 'English',
            'code' => 'eng',
        ]);
        $this->assertEquals(201, $caption['headers']['status-code']);
        $captionId = $caption['body']['$id'];

        $captionEvent = $this->receiveUntilEvent(
            $client,
            fn (array $message) => ($message['type'] ?? '') === 'event'
                && ($message['data']['payload']['$id'] ?? '') === $captionId
                && \in_array(
                    $message['data']['payload']['status'] ?? '',
                    ['started', 'ready'],
                    true
                )
        );
        $this->assertContains(
            'videos.' . $videoId . '.captions.' . $captionId . '.update',
            $captionEvent['data']['events'] ?? []
        );

        $client->close();
    }

    /**
     * A video backed by a bucket that grants the caller nothing must not leak
     * processing events: realtime roles are stamped from the source bucket and
     * file, so a plain session sees no frames for it.
     */
    public function testRealtimeEventsWithheldForPrivateSource(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->serverHeaders(), [
            'bucketId' => 'unique()',
            'name' => 'Private realtime bucket',
            'fileSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        $file = $this->uploadVideoTo($bucket['body']['$id'], [], [
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $client = $this->getWebsocket(['videos'], $this->websocketHeaders());
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? '');

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $bucket['body']['$id'],
            'fileId' => $file['$id'],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        $profiles = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->serverHeaders());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $profileId = $profiles['body']['profiles'][0]['$id'] ?? '';
        $this->assertNotEmpty($profileId);

        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->serverHeaders(), [
            'profileId' => $profileId,
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $renditionId = $rendition['body']['$id'];

        $caption = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/captions', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getCaptionFile()['$id'],
            'name' => 'English',
            'code' => 'eng',
        ]);
        $this->assertEquals(201, $caption['headers']['status-code']);
        $captionId = $caption['body']['$id'];

        // Once REST reports jobs finished, every worker event has been published;
        // drain the socket briefly and assert none reference this video.
        $body = $this->waitForRenditionTerminalState($videoId, $renditionId);
        $this->assertEquals('ready', $body['status']);

        $captionDeadline = \time() + 120;
        $captionBody = [];
        while (\time() < $captionDeadline) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/videos/' . $videoId . '/captions',
                $this->serverHeaders()
            );
            foreach ($response['body']['captions'] ?? [] as $row) {
                if (($row['$id'] ?? '') === $captionId) {
                    $captionBody = $row;
                    break;
                }
            }
            if (!\in_array($captionBody['status'] ?? '', ['pending', 'started', ''], true)) {
                break;
            }
            \usleep(500000);
        }
        $this->assertContains($captionBody['status'] ?? '', ['ready', 'error']);

        $deadline = \time() + 6;
        try {
            while (\time() < $deadline) {
                $frame = \json_decode($client->receive(), true);
                if (!\is_array($frame)) {
                    continue;
                }

                $this->assertNotEquals(
                    $videoId,
                    $frame['data']['payload']['$id'] ?? '',
                    'Private video leaked a realtime event to a session without read access'
                );
                $this->assertNotEquals(
                    $renditionId,
                    $frame['data']['payload']['$id'] ?? '',
                    'Private rendition leaked a realtime event to a session without read access'
                );
                $this->assertNotEquals(
                    $captionId,
                    $frame['data']['payload']['$id'] ?? '',
                    'Private caption leaked a realtime event to a session without read access'
                );
                $this->assertNotContains('videos.' . $videoId, $frame['data']['channels'] ?? []);
                foreach ($frame['data']['events'] ?? [] as $eventName) {
                    $this->assertStringNotContainsString(
                        'videos.' . $videoId . '.timeline.update',
                        (string) $eventName
                    );
                    $this->assertStringNotContainsString(
                        'videos.' . $videoId . '.captions.' . $captionId,
                        (string) $eventName
                    );
                }
            }
        } catch (TimeoutException | ConnectionException) {
            // Silence: nothing further queued for this subscriber.
        }

        $client->close();
    }

    /**
     * Guests with videos.play can stream a video whose source file is public.
     */
    public function testGuestCanPlayPublicVideo(): array
    {
        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        $profiles = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->serverHeaders());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $profile = null;
        foreach ($profiles['body']['profiles'] as $candidate) {
            if (($candidate['name'] ?? '') === '360p') {
                $profile = $candidate;
                break;
            }
        }
        $this->assertNotNull($profile, 'Seeded 360p profile missing');

        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->serverHeaders(), [
            'profileId' => $profile['$id'],
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $renditionId = $rendition['body']['$id'];

        $body = $this->waitForRenditionTerminalState($videoId, $renditionId);
        $this->assertEquals('ready', $body['status'], 'Guest-play rendition did not finish');

        $master = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXTM3U', (string) $master['body']);

        if (\preg_match('#renditions/' . \preg_quote($renditionId, '#') . '/streams/(\d+)/playlist\.m3u8#', (string) $master['body'], $matches) !== 1) {
            $this->fail('HLS master playlist did not reference a stream playlist');
        }
        $streamId = $matches[1];

        $variant = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/streams/' . $streamId . '/playlist.m3u8',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $variant['headers']['status-code']);

        if (\preg_match('#/segments/([a-zA-Z0-9]+)(?:\?|$)#', (string) $variant['body'], $segmentMatch) !== 1) {
            $this->fail('HLS variant playlist did not reference a segment');
        }

        $segment = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentMatch[1],
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $segment['headers']['status-code']);
        $this->assertNotEmpty($segment['body']);

        $guestTimeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', $this->anonymousHeaders());
        $this->assertEquals(200, $guestTimeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $guestTimeline['body']);

        if (\preg_match('~previews/([a-zA-Z0-9]+)#xywh=~', (string) $guestTimeline['body'], $previewMatch) === 1) {
            $preview = $this->client->call(
                Client::METHOD_GET,
                '/videos/' . $videoId . '/previews/' . $previewMatch[1],
                $this->anonymousHeaders()
            );
            $this->assertEquals(200, $preview['headers']['status-code']);
            $this->assertNotEmpty($preview['body']);
        } else {
            $this->fail('Timeline VTT did not reference a preview image');
        }

        return ['videoId' => $videoId, 'renditionId' => $renditionId];
    }

    #[Depends('testGuestCanPlayPublicVideo')]
    public function testGuestCannotReadPublicVideoMetadata(array $play): void
    {
        $videoId = $play['videoId'];

        foreach ([
            '/videos',
            '/videos/' . $videoId,
            '/videos/' . $videoId . '/captions',
            '/videos/' . $videoId . '/renditions',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
            $this->assertEquals(401, $response['headers']['status-code'], $path);
            $this->assertEquals('general_unauthorized_scope', $response['body']['type'], $path);
        }
    }

    #[Depends('testGuestCanPlayPublicVideo')]
    public function testSessionCanStillPlayPublicVideo(array $play): void
    {
        $videoId = $play['videoId'];
        $renditionId = $play['renditionId'];

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', $this->sessionHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $timeline['body']);

        $master = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
            $this->sessionHeaders()
        );
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXTM3U', (string) $master['body']);
        $this->assertStringContainsString($renditionId, (string) $master['body']);
    }
}
