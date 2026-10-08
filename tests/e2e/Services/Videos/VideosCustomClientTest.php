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
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
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
            'name' => 'session-readable-' . ID::unique(),
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(201, $readable['headers']['status-code']);
        $readableId = $readable['body']['$id'];

        $private = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'session-private-' . ID::unique(),
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
            'permissions' => [],
        ]);
        $this->assertEquals(201, $created['headers']['status-code']);
        $videoId = $created['body']['$id'];

        foreach ([
            '/videos/' . $videoId,
            '/videos/' . $videoId . '/captions',
            '/videos/' . $videoId . '/renditions',
            '/videos/' . $videoId . '/timeline',
            '/videos/' . $videoId . '/outputs/hls/master.m3u8',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->sessionHeaders());
            $this->assertEquals(404, $response['headers']['status-code'], $path . ' leaked access');
            $this->assertEquals('video_not_found', $response['body']['type'], $path);
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
     * Guests with videos.play can stream a video that grants read("any").
     * Guests still lack videos.read, so manage metadata stays closed.
     */
    public function testGuestCanPlayPublicVideo(): array
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

    /**
     * Guest can play but cannot create renditions or otherwise manage the video.
     */
    #[Depends('testGuestCanPlayPublicVideo')]
    public function testGuestCannotManagePlayableVideo(array $play): void
    {
        $videoId = $play['videoId'];

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

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', $this->anonymousHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
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

    /**
     * A guest-playable video grants read("any"). Sessions with videos.read can
     * therefore get, list, play, and create child resources, but cannot update
     * or delete without those permissions on the document.
     */
    #[Depends('testGuestCanPlayPublicVideo')]
    public function testSessionCannotManagePlayableVideoWithoutDocumentAcl(array $play): void
    {
        $videoId = $play['videoId'];

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

        $timeline = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', $this->sessionHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
    }

    /**
     * An API key publishes a video with read("any"), encodes it, and a guest
     * can play the result. Restricting that same video to one user then hides
     * the encode from the guest; the API key can still read it.
     */
    public function testGuestLosesEncodedPlaybackWhenPermissionsRestricted(): void
    {
        $userId = $this->getUser()['$id'];

        $created = $this->client->call(Client::METHOD_POST, '/videos', $this->serverHeaders(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
            'name' => 'guest-then-private-' . ID::unique(),
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

        if (\preg_match('#renditions/' . \preg_quote($renditionId, '#') . '/streams/(\d+)/playlist\.m3u8#', (string) $master['body'], $matches) !== 1) {
            $this->fail('HLS master playlist did not reference a stream playlist');
        }

        $variantPath = '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/streams/' . $matches[1] . '/playlist.m3u8';
        $variant = $this->client->call(Client::METHOD_GET, $variantPath, $this->anonymousHeaders());
        $this->assertEquals(200, $variant['headers']['status-code']);

        if (\preg_match('#/segments/([a-zA-Z0-9]+)(?:\?|$)#', (string) $variant['body'], $segmentMatch) !== 1) {
            $this->fail('HLS variant playlist did not reference a segment');
        }

        $segmentPath = '/videos/' . $videoId . '/outputs/hls/renditions/' . $renditionId . '/segments/' . $segmentMatch[1];
        $segment = $this->client->call(Client::METHOD_GET, $segmentPath, $this->anonymousHeaders());
        $this->assertEquals(200, $segment['headers']['status-code']);
        $this->assertNotEmpty($segment['body']);

        $timelinePath = '/videos/' . $videoId . '/timeline';
        $timeline = $this->client->call(Client::METHOD_GET, $timelinePath, $this->anonymousHeaders());
        $this->assertEquals(200, $timeline['headers']['status-code']);
        $this->assertStringContainsString('WEBVTT', (string) $timeline['body']);

        $restricted = $this->client->call(Client::METHOD_PUT, '/videos/' . $videoId, $this->serverHeaders(), [
            'name' => $created['body']['name'],
            'permissions' => [
                Permission::read(Role::user($userId)),
            ],
        ]);
        $this->assertEquals(200, $restricted['headers']['status-code']);
        $this->assertNotContains((string) Permission::read(Role::any()), $restricted['body']['$permissions']);
        $this->assertContains((string) Permission::read(Role::user($userId)), $restricted['body']['$permissions']);

        foreach ([$masterPath, $variantPath, $segmentPath, $timelinePath] as $path) {
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
            'name' => 'owned-' . ID::unique(),
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
            'name' => 'read-only-' . ID::unique(),
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
}
