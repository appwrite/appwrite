<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\VideoCustom;
use Tests\E2E\Services\Realtime\RealtimeBase;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
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
     * A video with no permissions, sourced from a bucket that grants nothing.
     */
    private function createRestrictedVideo(): string
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
            'permissions' => [],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);

        return $created['body']['$id'];
    }

    /**
     * A video granting read("any"), with its thumbnail timeline generated.
     */
    private function createPublicVideo(): string
    {
        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $this->assertContains((string) Permission::read(Role::any()), $created['body']['$permissions']);
        $videoId = $created['body']['$id'];

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        return $videoId;
    }

    /**
     * Uploaded caption, polled until the worker marks it ready.
     *
     * Created with the API key: a video that only grants read("any") cannot
     * be updated by the suite session.
     */
    private function createReadyCaption(string $videoId): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/captions', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getCaptionFile()['$id'],
            'name' => 'English',
            'code' => 'eng',
            'default' => true,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $captionId = $response['body']['$id'];

        $body = $this->waitForCaptionTerminalState($videoId, $captionId);
        $this->assertEquals('ready', $body['status'], 'Caption did not become ready');

        return $captionId;
    }

    private function timeTravelRendition(string $projectId, string $renditionId, string $status): void
    {
        $command = (new Command('docker'))
            ->argument('exec')
            ->argument('appwrite')
            ->argument('task-time-travel')
            ->argument('--projectId=' . $projectId)
            ->argument('--resourceType=videos_rendition')
            ->argument('--resourceId=' . $renditionId)
            ->argument('--status=' . $status);

        $stdout = '';
        $stderr = '';
        $code = Console::execute($command, '', $stdout, $stderr);
        $this->assertSame(0, $code, "Time-travel command failed with code $code: $stderr ($stdout)");
    }

    /**
     * The token sitting between two fixed markers in a playlist or timeline.
     */
    private function playlistToken(string $body, string $before, string $after, string $failure): string
    {
        $at = \strpos($body, $before);
        $this->assertNotFalse($at, $failure);

        $start = $at + \strlen($before);
        $end = \strpos($body, $after, $start);
        $this->assertNotFalse($end, $failure);

        $token = \substr($body, $start, $end - $start);
        $this->assertNotSame('', $token, $failure);

        return $token;
    }

    /**
     * DASH master, both CMAF masters, caption manifests, and the first HLS
     * caption segment. These are the play URLs a guest can fetch without
     * videos.read.
     *
     * @return list<string>
     */
    private function dashCmafCaptionPlayPaths(string $videoId, string $captionId): array
    {
        $paths = [
            '/videos/' . $videoId . '/outputs/dash/master.mpd',
            '/videos/' . $videoId . '/outputs/cmaf/master.m3u8',
            '/videos/' . $videoId . '/outputs/cmaf/master.mpd',
        ];

        foreach (['hls', 'dash', 'cmaf'] as $output) {
            $paths[] = '/videos/' . $videoId . '/outputs/' . $output . '/captions/' . $captionId . '/manifest';
        }

        $paths[] = $this->hlsCaptionSegmentPath($videoId, $captionId);

        return $paths;
    }

    /**
     * First segment path published by the HLS caption playlist.
     */
    private function hlsCaptionSegmentPath(string $videoId, string $captionId): string
    {
        $manifest = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/captions/' . $captionId . '/manifest',
            $this->serverHeaders()
        );
        $this->assertEquals(200, $manifest['headers']['status-code']);

        $segmentId = $this->playlistToken(
            (string) $manifest['body'],
            '/segments/',
            '?',
            'HLS caption playlist did not reference a segment'
        );

        return '/videos/' . $videoId . '/outputs/hls/captions/' . $captionId . '/segments/' . $segmentId;
    }

    /**
     * Marker that distinguishes a real playlist, MPD, or WebVTT body.
     */
    private function playbackNeedle(string $path): string
    {
        if (\str_ends_with($path, '.mpd')) {
            return '<MPD';
        }

        if (\str_ends_with($path, '.m3u8')) {
            return '#EXTM3U';
        }

        if (\str_contains($path, '/outputs/hls/captions/') && \str_ends_with($path, '/manifest')) {
            return '#EXTM3U';
        }

        return 'WEBVTT';
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
            '/videos/doesnotexist/timelines',
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
     * Manage get authorizes on the video document's `$permissions`.
     */
    public function testSessionCanReadVideo(): string
    {
        $userId = $this->getUser()['$id'];
        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'permissions' => [
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
        ]);

        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertContains((string) Permission::read(Role::user($userId)), $created['body']['$permissions']);

        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($videoId, $response['body']['$id']);
        $this->assertContains((string) Permission::read(Role::user($userId)), $response['body']['$permissions']);

        return $videoId;
    }

    /**
     * Session list is filtered by video-document read ACL; API keys see the full catalog.
     */
    public function testSessionListsOnlyReadableVideos(): void
    {
        $userId = $this->getUser()['$id'];

        $readable = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'session-readable-' . Id::unique(),
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(201, $readable['headers']['status-code']);
        $readableId = $readable['body']['$id'];

        $private = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'session-private-' . Id::unique(),
            'permissions' => [],
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);
        $privateId = $private['body']['$id'];

        $sessionList = $this->client->call(Client::METHOD_GET, '/videos', $this->sessionHeaders());
        $this->assertEquals(200, $sessionList['headers']['status-code']);
        $sessionIds = \array_column($sessionList['body']['videos'], '$id');
        $this->assertContains($readableId, $sessionIds);
        $this->assertNotContains($privateId, $sessionIds);

        $serverList = $this->client->call(Client::METHOD_GET, '/videos', $this->serverHeaders());
        $this->assertEquals(200, $serverList['headers']['status-code']);
        $serverIds = \array_column($serverList['body']['videos'], '$id');
        $this->assertContains($readableId, $serverIds);
        $this->assertContains($privateId, $serverIds);
    }

    /**
     * Empty video permissions hide manage and play routes from the session.
     * The source bucket ACL no longer gates playback.
     */
    public function testSessionCannotReadVideoInRestrictedBucket(): void
    {
        $videoId = $this->createRestrictedVideo();

        foreach ([
            '/videos/' . $videoId,
            '/videos/' . $videoId . '/captions',
            '/videos/' . $videoId . '/renditions',
            '/videos/' . $videoId . '/timelines',
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->sessionHeaders());
            $this->assertEquals(404, $response['headers']['status-code'], $path . ' leaked access');
            $this->assertEquals('video_not_found', $response['body']['type'], $path);
        }
    }

    public function testGuestCannotPlayPrivateVideo(): void
    {
        $videoId = $this->createRestrictedVideo();

        foreach ([
            '/videos/' . $videoId . '/timelines',
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
            $this->assertEquals(404, $response['headers']['status-code'], $path);
            $this->assertEquals('video_not_found', $response['body']['type'], $path);
        }
    }

    /**
     * Session read on the video unlocks play even when the source bucket
     * grants the caller nothing. Timeline is used because the HLS master
     * requires a ready rendition.
     */
    public function testSessionCanPlayVideoFromPrivateBucket(): void
    {
        $userId = $this->getUser()['$id'];

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->serverHeaders(), [
            'bucketId' => 'unique()',
            'name' => 'Private source for playable video',
            'fileSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        $file = $this->uploadVideoTo($bucket['body']['$id'], [], [
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $bucket['body']['$id'],
            'fileId' => $file['$id'],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertContains((string) Permission::read(Role::user($userId)), $created['body']['$permissions']);

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $timeline['body']);
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
     * Realtime events inherit the video document's read roles. A video that
     * grants read("any") must deliver timeline, rendition and caption events
     * to a session subscribed to the `videos` channel.
     */
    public function testRealtimeEventsDeliveredForReadableVideo(): void
    {
        $client = $this->getWebsocket(['videos'], $this->websocketHeaders());
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? '');

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        $timeline = $this->receiveUntilEvent(
            $client,
            fn (array $message) => ($message['type'] ?? '') === 'event'
                && \in_array(
                    'videos.' . $videoId . '.timelines.update',
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
     * A video with an empty ACL on a public source bucket must not leak
     * processing events: realtime roles are stamped from the video document
     * only, so a plain session sees no frames for it.
     */
    public function testRealtimeEventsWithheldForPrivateVideo(): void
    {
        $client = $this->getWebsocket(['videos'], $this->websocketHeaders());
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? '');

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'permissions' => [],
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
                        'videos.' . $videoId . '.timelines.update',
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
     * Guests with videos.play can stream a video that grants read("any").
     * Guests still lack videos.read, so manage metadata stays closed.
     */
    public function testGuestCanPlayPublicVideo(): void
    {
        $videoId = $this->createPublicVideo();
        $renditionId = $this->createReadyRendition($videoId, 'hls');

        $master = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXTM3U', (string) $master['body']);

        $streamId = $this->playlistToken(
            (string) $master['body'],
            'renditions/' . $renditionId . '/streams/',
            '/playlist.m3u8',
            'HLS master playlist did not reference a stream playlist'
        );

        $variant = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/streams/' . $streamId . '/playlist.m3u8',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $variant['headers']['status-code']);

        $segmentId = $this->playlistToken(
            (string) $variant['body'],
            '/segments/',
            '?',
            'HLS variant playlist did not reference a segment'
        );

        $segment = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentId,
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $segment['headers']['status-code']);
        $this->assertNotEmpty($segment['body']);

        $guestTimeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->anonymousHeaders());
        $this->assertEquals(200, $guestTimeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $guestTimeline['body']);

        $projectId = $this->getProject()['$id'];
        $this->assertStringNotContainsString('sprites/', (string) $guestTimeline['body']);
        $this->assertMatchesRegularExpression(
            '/thumbnails\/[a-zA-Z0-9]+\?project=' . \preg_quote($projectId, '/') . '#xywh=/',
            (string) $guestTimeline['body']
        );
        $this->assertSame(
            1,
            \preg_match('~thumbnails/([a-zA-Z0-9]+)\?project=~', (string) $guestTimeline['body'], $thumbnailMatch)
        );
        // No X-Appwrite-Project: mirrors a player resolving the VTT cue URL.
        $thumbnail = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/thumbnails/' . $thumbnailMatch[1] . '?project=' . $projectId,
            [
                'content-type' => 'application/json',
            ]
        );
        $this->assertEquals(200, $thumbnail['headers']['status-code']);
        $this->assertNotEmpty($thumbnail['body']);
    }

    /**
     * A session creates a member-owned video. Guests cannot play it until an
     * API key publishes read("any") — sessions cannot assign Role::any()
     * themselves. After that, a guest with videos.play can stream it.
     */
    public function testGuestCanPlayMemberCreatedVideo(): void
    {
        $userId = $this->getUser()['$id'];

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'member-public-' . Id::unique(),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertContains((string) Permission::read(Role::user($userId)), $created['body']['$permissions']);
        $this->assertNotContains((string) Permission::read(Role::any()), $created['body']['$permissions']);

        $denied = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/timelines',
            $this->anonymousHeaders()
        );
        $this->assertEquals(404, $denied['headers']['status-code']);

        $published = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->serverHeaders(), [
            'name' => $created['body']['name'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $published['headers']['status-code']);
        $this->assertContains((string) Permission::read(Role::any()), $published['body']['$permissions']);

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
        $this->assertEquals('ready', $body['status'], 'Member-created guest-play rendition did not finish');

        $master = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXTM3U', (string) $master['body']);

        $streamId = $this->playlistToken(
            (string) $master['body'],
            'renditions/' . $renditionId . '/streams/',
            '/playlist.m3u8',
            'HLS master playlist did not reference a stream playlist'
        );

        $variant = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/streams/' . $streamId . '/playlist.m3u8',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $variant['headers']['status-code']);

        $segmentId = $this->playlistToken(
            (string) $variant['body'],
            '/segments/',
            '?',
            'HLS variant playlist did not reference a segment'
        );

        $segment = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentId,
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $segment['headers']['status-code']);
        $this->assertNotEmpty($segment['body']);

        $guestTimeline = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/timelines',
            $this->anonymousHeaders()
        );
        $this->assertEquals(200, $guestTimeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $guestTimeline['body']);

        $owner = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(200, $owner['headers']['status-code']);
        $this->assertEquals($videoId, $owner['body']['$id']);
    }

    public function testGuestCannotReadPublicVideoMetadata(): void
    {
        $videoId = $this->createPublicVideo();

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

    /**
     * Guest can play but cannot create renditions or otherwise manage the video.
     */
    public function testGuestCannotManagePlayableVideo(): void
    {
        $videoId = $this->createPublicVideo();

        $profiles = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->serverHeaders());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $profileId = $profiles['body']['profiles'][0]['$id'] ?? '';
        $this->assertNotEmpty($profileId);

        $rendition = $this->client->call(
            Client::METHOD_POST,
            '/videos/' . $videoId . '/renditions',
            $this->anonymousHeaders(),
            [
                'profileId' => $profileId,
                'output' => 'hls',
            ]
        );
        $this->assertEquals(401, $rendition['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $rendition['body']['type']);

        $caption = $this->client->call(
            Client::METHOD_POST,
            '/videos/' . $videoId . '/captions',
            $this->anonymousHeaders(),
            [
                'bucketId' => $this->getVideoBucket()['$id'],
                'fileId' => $this->getCaptionFile()['$id'],
                'name' => 'Guest',
                'code' => 'eng',
            ]
        );
        $this->assertEquals(401, $caption['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->anonymousHeaders(), [
            'name' => 'hijacked',
        ]);
        $this->assertEquals(401, $update['headers']['status-code']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $this->anonymousHeaders());
        $this->assertEquals(401, $delete['headers']['status-code']);

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->anonymousHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
    }

    public function testSessionCanStillPlayPublicVideo(): void
    {
        $videoId = $this->createPublicVideo();
        $renditionId = $this->createReadyRendition($videoId, 'hls');

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->sessionHeaders());
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

    /**
     * A guest-playable video grants read("any"). Sessions with videos.read can
     * get, list, and play, but cannot update or delete the video or mutate
     * renditions and captions without update permission on the document.
     */
    public function testSessionCannotManagePlayableVideoWithoutDocumentAcl(): void
    {
        $videoId = $this->createPublicVideo();
        $renditionId = $this->createRendition($videoId, 'hls');

        $get = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(200, $get['headers']['status-code']);

        $list = $this->client->call(Client::METHOD_GET, '/videos', $this->sessionHeaders());
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertContains($videoId, \array_column($list['body']['videos'], '$id'));

        $update = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->sessionHeaders(), [
            'name' => 'nope',
        ]);
        $this->assertEquals(401, $update['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $update['body']['type']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(401, $delete['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $delete['body']['type']);

        $profiles = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->serverHeaders());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $profileId = $profiles['body']['profiles'][0]['$id'] ?? '';
        $this->assertNotEmpty($profileId);

        $createRendition = $this->client->call(
            Client::METHOD_POST,
            '/videos/' . $videoId . '/renditions',
            $this->sessionHeaders(),
            [
                'profileId' => $profileId,
                'output' => 'hls',
            ]
        );
        $this->assertEquals(401, $createRendition['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $createRendition['body']['type']);

        $deleteRendition = $this->client->call(
            Client::METHOD_DELETE,
            '/videos/' . $videoId . '/renditions/' . $renditionId,
            $this->sessionHeaders()
        );
        $this->assertEquals(401, $deleteRendition['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $deleteRendition['body']['type']);

        $createCaption = $this->client->call(
            Client::METHOD_POST,
            '/videos/' . $videoId . '/captions',
            $this->sessionHeaders(),
            [
                'bucketId' => $this->getVideoBucket()['$id'],
                'fileId' => $this->getCaptionFile()['$id'],
                'name' => 'Hijack',
                'code' => 'eng',
            ]
        );
        $this->assertEquals(401, $createCaption['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $createCaption['body']['type']);

        $updateCaption = $this->client->call(
            Client::METHOD_PATCH,
            '/videos/' . $videoId . '/captions/doesnotexist',
            $this->sessionHeaders(),
            [
                'name' => 'Hijack',
            ]
        );
        $this->assertEquals(401, $updateCaption['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $updateCaption['body']['type']);

        $deleteCaption = $this->client->call(
            Client::METHOD_DELETE,
            '/videos/' . $videoId . '/captions/doesnotexist',
            $this->sessionHeaders()
        );
        $this->assertEquals(401, $deleteCaption['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $deleteCaption['body']['type']);

        $stillThere = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/renditions/' . $renditionId,
            $this->serverHeaders()
        );
        $this->assertEquals(200, $stillThere['headers']['status-code']);
        $this->assertEquals($renditionId, $stillThere['body']['$id']);

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->sessionHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
    }

    /**
     * Document ACL is checked before the in-progress gate. A session that can
     * read the video but not delete it must get 401 while a rendition is live.
     * The API-key delete on the same row must still be 409, so a revert of that
     * order cannot hide behind either check on its own.
     */
    public function testSessionDeleteRejectedWhileRenditionInProgress(): void
    {
        $videoId = $this->createPublicVideo();
        $renditionId = $this->createRendition($videoId, 'hls');

        // `uploading` is inside the in-progress gate, and it is not `pending`
        // or `started`, so a worker that has not claimed drops the job and one
        // that already claimed halts instead of settling the row to ready.
        $this->timeTravelRendition($this->getProject()['$id'], $renditionId, 'uploading');

        $delete = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(401, $delete['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $delete['body']['type']);

        $conflict = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $this->serverHeaders());
        $this->assertEquals(409, $conflict['headers']['status-code']);
        $this->assertEquals('video_rendition_in_progress', $conflict['body']['type']);

        $stillThere = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->serverHeaders());
        $this->assertEquals(200, $stillThere['headers']['status-code']);
        $this->assertEquals($videoId, $stillThere['body']['$id']);
    }

    /**
     * An API key publishes a video with read("any"), encodes HLS, DASH and
     * CMAF plus a caption, and a guest can play the result. Restricting that
     * same video to one user then hides every play URL from the guest; the
     * API key can still read the HLS master.
     */
    public function testGuestLosesEncodedPlaybackWhenPermissionsRestricted(): void
    {
        $userId = $this->getUser()['$id'];

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'guest-then-private-' . Id::unique(),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $this->assertContains((string) Permission::read(Role::any()), $created['body']['$permissions']);
        $videoId = $created['body']['$id'];

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
        $this->assertEquals('ready', $body['status'], 'Public rendition did not finish');

        $masterPath = '/videos/' . $videoId . '/outputs/hls/master.m3u8';
        $master = $this->client->call(Client::METHOD_GET, $masterPath, $this->anonymousHeaders());
        $this->assertEquals(200, $master['headers']['status-code']);
        $this->assertStringContainsString('#EXTM3U', (string) $master['body']);

        $streamId = $this->playlistToken(
            (string) $master['body'],
            'renditions/' . $renditionId . '/streams/',
            '/playlist.m3u8',
            'HLS master playlist did not reference a stream playlist'
        );

        $variantPath = '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/streams/' . $streamId . '/playlist.m3u8';
        $variant = $this->client->call(Client::METHOD_GET, $variantPath, $this->anonymousHeaders());
        $this->assertEquals(200, $variant['headers']['status-code']);

        $segmentId = $this->playlistToken(
            (string) $variant['body'],
            '/segments/',
            '?',
            'HLS variant playlist did not reference a segment'
        );

        $segmentPath = '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentId;
        $segment = $this->client->call(Client::METHOD_GET, $segmentPath, $this->anonymousHeaders());
        $this->assertEquals(200, $segment['headers']['status-code']);
        $this->assertNotEmpty($segment['body']);

        $timelinePath = '/videos/' . $videoId . '/timelines';
        $timeline = $this->client->call(Client::METHOD_GET, $timelinePath, $this->anonymousHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $timeline['body']);

        $this->createReadyRendition($videoId, 'dash');
        $this->createReadyRendition($videoId, 'cmaf');
        $captionId = $this->createReadyCaption($videoId);
        $encodedPaths = $this->dashCmafCaptionPlayPaths($videoId, $captionId);

        foreach ($encodedPaths as $path) {
            $playable = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
            $this->assertEquals(200, $playable['headers']['status-code'], $path);
            $this->assertStringContainsString($this->playbackNeedle($path), (string) $playable['body'], $path);
        }

        $restricted = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->serverHeaders(), [
            'name' => $created['body']['name'],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $restricted['headers']['status-code']);
        $this->assertNotContains((string) Permission::read(Role::any()), $restricted['body']['$permissions']);
        $this->assertContains((string) Permission::read(Role::user($userId)), $restricted['body']['$permissions']);

        foreach ([
            $masterPath,
            $variantPath,
            $segmentPath,
            $timelinePath,
            ...$encodedPaths,
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
            $this->assertEquals(404, $response['headers']['status-code'], $path . ' stayed available to guests');
            $this->assertEquals('video_not_found', $response['body']['type'], $path);
        }

        $stillThere = $this->client->call(Client::METHOD_GET, $masterPath, $this->serverHeaders());
        $this->assertEquals(200, $stillThere['headers']['status-code']);
        $this->assertStringContainsString($renditionId, (string) $stillThere['body']);
    }

    public function testSessionCreateUpdateDeleteWithPermissions(): void
    {
        $userId = $this->getUser()['$id'];

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'owned-' . Id::unique(),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertContains((string) Permission::read(Role::user($userId)), $created['body']['$permissions']);
        $this->assertContains((string) Permission::update(Role::user($userId)), $created['body']['$permissions']);
        $this->assertContains((string) Permission::delete(Role::user($userId)), $created['body']['$permissions']);

        $updated = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->sessionHeaders(), [
            'name' => 'renamed-owned',
            'permissions' => [
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals('renamed-owned', $updated['body']['name']);

        $profiles = $this->client->call(Client::METHOD_GET, '/project/profiles', $this->serverHeaders());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $profileId = $profiles['body']['profiles'][0]['$id'] ?? '';
        $this->assertNotEmpty($profileId);

        $rendition = $this->client->call(
            Client::METHOD_POST,
            '/videos/' . $videoId . '/renditions',
            $this->sessionHeaders(),
            [
                'profileId' => $profileId,
                'output' => 'hls',
            ]
        );
        $this->assertEquals(202, $rendition['headers']['status-code']);
        $renditionId = $rendition['body']['$id'];

        $deleteRendition = $this->client->call(
            Client::METHOD_DELETE,
            '/videos/' . $videoId . '/renditions/' . $renditionId,
            $this->sessionHeaders()
        );
        $this->assertEquals(204, $deleteRendition['headers']['status-code']);

        $caption = $this->client->call(
            Client::METHOD_POST,
            '/videos/' . $videoId . '/captions',
            $this->sessionHeaders(),
            [
                'bucketId' => $this->getVideoBucket()['$id'],
                'fileId' => $this->getCaptionFile()['$id'],
                'name' => 'Owned',
                'code' => 'eng',
            ]
        );
        $this->assertEquals(201, $caption['headers']['status-code']);
        $captionId = $caption['body']['$id'];

        $updateCaption = $this->client->call(
            Client::METHOD_PATCH,
            '/videos/' . $videoId . '/captions/' . $captionId,
            $this->sessionHeaders(),
            [
                'name' => 'Owned Renamed',
            ]
        );
        $this->assertEquals(200, $updateCaption['headers']['status-code']);
        $this->assertEquals('Owned Renamed', $updateCaption['body']['name']);

        $deleteCaption = $this->client->call(
            Client::METHOD_DELETE,
            '/videos/' . $videoId . '/captions/' . $captionId,
            $this->sessionHeaders()
        );
        $this->assertEquals(204, $deleteCaption['headers']['status-code']);

        $deniedRole = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->sessionHeaders(), [
            'name' => 'bad-role',
            'permissions' => [
                Permission::read(Role::user('someoneelse')),
            ],
        ]);
        $this->assertEquals(401, $deniedRole['headers']['status-code']);

        $readOnly = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'read-only-' . Id::unique(),
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(201, $readOnly['headers']['status-code']);
        $readOnlyId = $readOnly['body']['$id'];

        $cannotUpdate = $this->client->call(Client::METHOD_PUT, '/videos/' . $readOnlyId, $this->sessionHeaders(), [
            'name' => 'should-fail',
        ]);
        $this->assertContains($cannotUpdate['headers']['status-code'], [401, 404]);

        $cannotDelete = $this->client->call(Client::METHOD_DELETE, '/videos/' . $readOnlyId, $this->sessionHeaders());
        $this->assertContains($cannotDelete['headers']['status-code'], [401, 404]);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(204, $deleted['headers']['status-code']);
    }

    /**
     * A second session has no role on the suite user's video. Missing document
     * read is video_not_found for get, update, and delete. The owner can still
     * read it afterwards.
     */
    public function testOtherUserCannotManageVideo(): void
    {
        $ownerId = $this->getUser()['$id'];
        $otherId = Id::unique();
        $other = $this->createUser($otherId, $otherId . '@localhost.test');
        $this->assertEquals($otherId, $other['$id']);
        $this->assertNotEmpty($other['session']);

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'owner-only-' . Id::unique(),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertContains((string) Permission::read(Role::user($ownerId)), $created['body']['$permissions']);

        $headers = $this->getUserHeaders($otherId);

        $get = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $headers);
        $this->assertEquals(404, $get['headers']['status-code']);
        $this->assertEquals('video_not_found', $get['body']['type']);

        $update = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $headers, [
            'name' => 'taken-over',
        ]);
        $this->assertEquals(404, $update['headers']['status-code']);
        $this->assertEquals('video_not_found', $update['body']['type']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/videos/' . $videoId, $headers);
        $this->assertEquals(404, $delete['headers']['status-code']);
        $this->assertEquals('video_not_found', $delete['body']['type']);

        $owner = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(200, $owner['headers']['status-code']);
        $this->assertEquals($videoId, $owner['body']['$id']);
    }

    /**
     * read(team:T) lets a confirmed member load the video. API-key memberships
     * are confirmed immediately. The suite session is not on the team.
     */
    public function testTeamRoleGrantsRead(): void
    {
        $teamId = Id::unique();
        $team = $this->createTeam($teamId, 'Readers');
        $this->assertEquals($teamId, $team['$id']);

        $memberId = Id::unique();
        $member = $this->createUser($memberId, $memberId . '@localhost.test');
        $this->assertEquals($memberId, $member['$id']);
        $this->assertNotEmpty($member['session']);

        $membership = $this->addToTeam($memberId, $teamId);
        $this->assertEquals($memberId, $membership['user']);
        $this->assertNotEmpty($membership['membership']);

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'team-readable-' . Id::unique(),
            'permissions' => [
                Permission::read(Role::team($teamId)),
            ],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertContains((string) Permission::read(Role::team($teamId)), $created['body']['$permissions']);

        $memberGet = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->getUserHeaders($memberId));
        $this->assertEquals(200, $memberGet['headers']['status-code']);
        $this->assertEquals($videoId, $memberGet['body']['$id']);

        $outsider = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(404, $outsider['headers']['status-code']);
        $this->assertEquals('video_not_found', $outsider['body']['type']);
    }

    /**
     * Creating a video checks the source bucket before the file. A private
     * bucket rejects the session even when that file id was never uploaded.
     * With file security and an empty bucket ACL, an unreadable file is
     * storage_file_not_found until the file itself grants the session read.
     */
    public function testCreateVideoFromUnreadableBucket(): void
    {
        $private = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->serverHeaders(), [
            'bucketId' => 'unique()',
            'name' => 'Private videos bucket for create',
            'fileSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertEquals(201, $private['headers']['status-code']);

        $denied = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $private['body']['$id'],
            'fileId' => Id::unique(),
        ]);
        $this->assertEquals(401, $denied['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $denied['body']['type']);

        $secured = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->serverHeaders(), [
            'bucketId' => 'unique()',
            'name' => 'File-security videos bucket',
            'fileSecurity' => true,
            'permissions' => [],
        ]);
        $this->assertEquals(201, $secured['headers']['status-code']);
        $bucketId = $secured['body']['$id'];

        $file = $this->uploadVideoTo($bucketId, [], [
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $missing = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $bucketId,
            'fileId' => $file['$id'],
        ]);
        $this->assertEquals(404, $missing['headers']['status-code']);
        $this->assertEquals('storage_file_not_found', $missing['body']['type']);

        $userId = $this->getUser()['$id'];
        $granted = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $file['$id'], $this->serverHeaders(), [
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $granted['headers']['status-code']);
        $this->assertContains((string) Permission::read(Role::user($userId)), $granted['body']['$permissions']);

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $bucketId,
            'fileId' => $file['$id'],
            'name' => 'from-granted-file-' . Id::unique(),
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $this->assertNotEmpty($created['body']['$id']);
        $this->assertEquals($file['$id'], $created['body']['fileId']);
        $this->assertContains((string) Permission::read(Role::user($userId)), $created['body']['$permissions']);
    }

    /**
     * A session may only grant roles it holds. Another user's role is rejected
     * on create and on update. Role::any() is held by every request, so it is
     * not foreign — same rule as storage file abuse permissions.
     */
    public function testCreateRejectsForeignRole(): void
    {
        $ownerId = $this->getUser()['$id'];
        $otherId = Id::unique();
        $other = $this->createUser($otherId, $otherId . '@localhost.test');
        $this->assertEquals($otherId, $other['$id']);

        $foreign = Permission::read(Role::user($otherId));

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'foreign-create-' . Id::unique(),
            'permissions' => [
                $foreign,
            ],
        ]);
        $this->assertEquals(401, $created['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $created['body']['type']);

        $owned = $this->client->call(Client::METHOD_POST, '/videos', $this->sessionHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'foreign-update-' . Id::unique(),
        ]);
        $this->assertEquals(201, $owned['headers']['status-code']);
        $videoId = $owned['body']['$id'];

        $updated = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->sessionHeaders(), [
            'name' => 'foreign-update-rejected',
            'permissions' => [
                $foreign,
            ],
        ]);
        $this->assertEquals(401, $updated['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $updated['body']['type']);

        $still = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->sessionHeaders());
        $this->assertEquals(200, $still['headers']['status-code']);
        $this->assertContains((string) Permission::read(Role::user($ownerId)), $still['body']['$permissions']);
        $this->assertNotContains((string) Permission::read(Role::user($otherId)), $still['body']['$permissions']);
    }

    /**
     * Omitting permissions on update keeps the current ACL. An empty array
     * would clear read("any") and hide the guest timeline.
     */
    public function testUpdateKeepsPermissions(): void
    {
        $userId = $this->getUser()['$id'];
        $permissions = [
            (string) Permission::read(Role::any()),
            (string) Permission::update(Role::user($userId)),
        ];

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'keeps-permissions-' . Id::unique(),
            'permissions' => $permissions,
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];
        $this->assertEqualsCanonicalizing($permissions, $created['body']['$permissions']);

        $timeline = $this->waitForTimeline($videoId);
        $this->assertEquals(200, $timeline['headers']['status-code']);

        $guest = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->anonymousHeaders());
        $this->assertEquals(200, $guest['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $guest['body']);

        $updated = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->sessionHeaders(), [
            'name' => 'renamed-keeps-permissions',
        ]);
        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals('renamed-keeps-permissions', $updated['body']['name']);
        $this->assertEqualsCanonicalizing($created['body']['$permissions'], $updated['body']['$permissions']);

        $guestAfter = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->anonymousHeaders());
        $this->assertEquals(200, $guestAfter['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $guestAfter['body']);
    }

    /**
     * Thumbnail responses are cached. After read("any") is replaced with one
     * user, the cache hook re-checks the video and the guest gets
     * video_not_found instead of a hit.
     */
    public function testThumbnailCacheRespectsPermissions(): void
    {
        $userId = $this->getUser()['$id'];
        $videoId = $this->createPublicVideo();

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timelines', $this->anonymousHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $previewIds = $this->timelinePreviewIds((string) $timeline['body']);
        $this->assertNotEmpty($previewIds);
        $path = '/videos/' . $videoId . '/thumbnails/' . $previewIds[0];

        $thumbnail = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
        $this->assertEquals(200, $thumbnail['headers']['status-code']);
        $this->assertEquals('miss', $thumbnail['headers']['x-appwrite-cache']);
        $this->assertNotEmpty($thumbnail['body']);

        $cached = [];
        $this->assertEventually(function () use (&$cached, $path) {
            $cached = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
            $cachedAgain = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());

            $this->assertSame('hit', $cached['headers']['x-appwrite-cache']);
            $this->assertSame('hit', $cachedAgain['headers']['x-appwrite-cache']);
            $this->assertSame($cached['body'], $cachedAgain['body']);
        });

        $this->assertEquals(200, $cached['headers']['status-code']);
        $this->assertNotEmpty($cached['body']);

        $video = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->serverHeaders());
        $this->assertEquals(200, $video['headers']['status-code']);

        $restricted = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->serverHeaders(), [
            'name' => $video['body']['name'],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $restricted['headers']['status-code']);
        $this->assertNotContains((string) Permission::read(Role::any()), $restricted['body']['$permissions']);
        $this->assertContains((string) Permission::read(Role::user($userId)), $restricted['body']['$permissions']);

        $denied = $this->client->call(Client::METHOD_GET, $path, $this->anonymousHeaders());
        $this->assertEquals(404, $denied['headers']['status-code']);
        $this->assertEquals('video_not_found', $denied['body']['type']);
    }

    /**
     * A video that grants read("any") is playable by the session and by a
     * guest, including DASH, both CMAF masters, and caption manifests.
     */
    public function testGuestCanPlayCaptionsAndDash(): void
    {
        $videoId = $this->createPublicVideo();
        $this->createReadyRendition($videoId, 'dash');
        $this->createReadyRendition($videoId, 'cmaf');
        $captionId = $this->createReadyCaption($videoId);
        $paths = $this->dashCmafCaptionPlayPaths($videoId, $captionId);

        foreach ([$this->sessionHeaders(), $this->anonymousHeaders()] as $headers) {
            foreach ($paths as $path) {
                $response = $this->client->call(Client::METHOD_GET, $path, $headers);
                $this->assertEquals(200, $response['headers']['status-code'], $path);
                $this->assertStringContainsString($this->playbackNeedle($path), (string) $response['body'], $path);
            }
        }
    }
}
