<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Videos;

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

    /**
     * Regression test: `videos.read` used to be granted to the guests role,
     * which let an unauthenticated caller past the scope guard and into
     * listVideos — and that endpoint reads with authorization skipped, because
     * video documents are project-internal and carry no permissions of their
     * own. The result was anonymous enumeration of every video in a project.
     *
     * Guests must now be rejected before any handler runs.
     */
    public function testGuestsCannotReachVideos(): void
    {
        $anonymous = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        $paths = [
            '/videos',
            '/videos/profiles',
            '/videos/someVideoId',
            '/videos/someVideoId/timeline',
            '/videos/someVideoId/subtitles',
            '/videos/someVideoId/renditions',
            '/videos/someVideoId/outputs/hls/master.m3u8',
        ];

        foreach ($paths as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $anonymous);

            $this->assertEquals(401, $response['headers']['status-code'], $path . ' is reachable by guests');
            $this->assertEquals('general_unauthorized_scope', $response['body']['type'], $path);
        }
    }

    public function testGuestsCannotWriteVideos(): void
    {
        $anonymous = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/videos', $anonymous, [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', $anonymous, [
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
    public function testSessionCannotReadVideoInRestrictedBucket(): void
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

        // The session is not granted anything by that bucket.
        foreach ([
            '/videos/' . $videoId,
            '/videos/' . $videoId . '/subtitles',
            '/videos/' . $videoId . '/renditions',
            '/videos/' . $videoId . '/timeline',
        ] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $this->sessionHeaders());
            $this->assertEquals(401, $response['headers']['status-code'], $path . ' leaked a video from a private bucket');
        }
    }

    /**
     * Profiles are project configuration (like storage buckets), not user
     * content. Create/update/delete require a privileged caller (console) or
     * API key — matching the SDK auth list — even though `videos.write` is
     * granted to the users role. Sessions may still read the ladder.
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

        $create = $this->client->call(Client::METHOD_POST, '/videos/profiles', $this->sessionHeaders(), $payload);
        $this->assertEquals(401, $create['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $create['body']['type']);

        $seeded = $this->client->call(Client::METHOD_POST, '/videos/profiles', $this->serverHeaders(), $payload);
        $this->assertEquals(201, $seeded['headers']['status-code']);
        $profileId = $seeded['body']['$id'];

        $update = $this->client->call(Client::METHOD_PATCH, '/videos/profiles/' . $profileId, $this->sessionHeaders(), $payload);
        $this->assertEquals(401, $update['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $update['body']['type']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $profileId, $this->sessionHeaders());
        $this->assertEquals(401, $delete['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $delete['body']['type']);

        $cleanup = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $profileId, $this->serverHeaders());
        $this->assertEquals(204, $cleanup['headers']['status-code']);
    }

    /**
     * A session may read profiles, since a player needs to know the ladder.
     */
    public function testSessionCanReadProfiles(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->sessionHeaders());

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
    }

    /**
     * Realtime video and rendition events inherit the source bucket/file read
     * roles. The fixture bucket grants read("any"), so a session subscribed to
     * the `videos` channel must receive processing events. Rendition rows carry
     * no ACL of their own and were previously published with no roles at all,
     * which realtime silently dropped — this covers both event families.
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

        $this->createSource($videoId, $this->serverHeaders());

        $event = $this->receiveUntilEvent(
            $client,
            fn (array $message) => ($message['type'] ?? '') === 'event'
                && ($message['data']['payload']['$id'] ?? '') === $videoId
        );
        $this->assertContains('videos.' . $videoId . '.update', $event['data']['events'] ?? []);
        $this->assertContains('videos.' . $videoId, $event['data']['channels'] ?? []);

        // Renditions require a ready video; buffered video-update frames during
        // the wait are harmless — the matcher below filters on the rendition id.
        $ready = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $ready['status']);

        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->serverHeaders());
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

        $this->createSource($videoId, $this->serverHeaders());

        // Once REST reports the video ready, every download-lifecycle event has
        // been published; drain the socket briefly and assert none reference it.
        // Frames for other tests' public videos may legitimately interleave.
        $ready = $this->waitForVideoReady($videoId);
        $this->assertEquals('ready', $ready['status']);

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
                $this->assertNotContains('videos.' . $videoId, $frame['data']['channels'] ?? []);
            }
        } catch (TimeoutException | ConnectionException) {
            // Silence: nothing further queued for this subscriber.
        }

        $client->close();
    }
}
