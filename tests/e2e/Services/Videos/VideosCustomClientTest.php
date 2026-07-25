<?php

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\VideoCustom;

/**
 * Client-side access control for the Videos API.
 *
 * Server-side behaviour lives in VideosCustomServerTest; this suite only covers
 * what changes when the caller is a session (or nobody at all).
 */
class VideosCustomClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use VideoCustom;
    use VideosPermissionsScope;

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
     * Documents current behaviour: a session can manage project-wide encoding
     * profiles, because `videos.write` is granted to the users role and the
     * profile routes are gated on that single scope.
     *
     * Note the mismatch — the profile endpoints declare
     * `auth: [AuthType::ADMIN, AuthType::KEY]`, so the generated client SDKs do
     * not expose them, yet the HTTP endpoints accept a session. Profiles are
     * project configuration rather than user content, so the storage analogy
     * (`buckets.write` is admin-only while `files.write` is not) argues for
     * splitting the scope. Left as-is deliberately: tightening it is a product
     * decision, not a test fix.
     */
    public function testSessionCanManageProfiles(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos/profiles', $this->sessionHeaders(), [
            'name' => 'from-session',
            'videoBitRate' => 1000,
            'audioBitRate' => 64,
            'width' => 640,
            'height' => 360,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/videos/profiles/' . $response['body']['$id'], $this->sessionHeaders());
        $this->assertEquals(204, $response['headers']['status-code']);
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
}
