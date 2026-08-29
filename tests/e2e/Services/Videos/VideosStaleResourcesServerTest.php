<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Scopes\VideoCustom;
use Utopia\Console;
use Utopia\Database\DateTime;

final class VideosStaleResourcesServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use VideoCustom;

    private function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    private function triggerCleanStale(): void
    {
        $stdout = '';
        $stderr = '';
        // bin/ is baked into the image; invoke cli.php directly so mounted src
        // (CleanStaleVideosResources) is used without rebuilding.
        $code = Console::execute(
            'docker exec appwrite php /usr/src/code/app/cli.php clean-stale-videos-resources --type=trigger',
            '',
            $stdout,
            $stderr
        );
        $this->assertSame(0, $code, "clean-stale-videos-resources failed: $stderr ($stdout)");
    }

    /**
     * Seed a video/rendition row via the development-only time-travel CLI.
     *
     * @param array<string, scalar> $fields
     */
    private function seedResource(string $resourceType, string $resourceId, array $fields): void
    {
        $args = [
            'docker exec appwrite php /usr/src/code/app/cli.php time-travel',
            '--projectId=' . \escapeshellarg($this->getProject()['$id']),
            '--resourceType=' . \escapeshellarg($resourceType),
            '--resourceId=' . \escapeshellarg($resourceId),
        ];

        foreach ($fields as $key => $value) {
            $args[] = '--' . $key . '=' . \escapeshellarg((string) $value);
        }

        $stdout = '';
        $stderr = '';
        $code = Console::execute(\implode(' ', $args), '', $stdout, $stderr);
        $this->assertSame(0, $code, "time-travel failed: $stderr ($stdout)");
        $this->assertStringContainsString('Time-travel successful', $stdout . $stderr);
    }

    private function hourAgo(): string
    {
        return DateTime::format((new \DateTime())->modify('-1 hour'));
    }

    private function createPendingVideo(): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/videos', $this->headers(), [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $this->getVideoFile()['$id'],
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        return $response['body']['$id'];
    }

    private function touchTmpSource(string $videoId): string
    {
        $path = $this->tmpSourcePath($videoId);
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            $this->assertTrue(\mkdir($dir, 0755, true) || \is_dir($dir));
        }
        $this->assertNotFalse(\file_put_contents($path, 'stale-source'));

        return $path;
    }

    private function getVideo(string $videoId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, $this->headers());
        $this->assertEquals(200, $response['headers']['status-code']);

        return $response['body'];
    }

    private function getRendition(string $videoId, string $renditionId): array
    {
        $response = $this->client->call(
            Client::METHOD_GET,
            '/videos/' . $videoId . '/renditions/' . $renditionId,
            $this->headers()
        );
        $this->assertEquals(200, $response['headers']['status-code']);

        return $response['body'];
    }

    private function seedStuckDownload(
        string $videoId,
        int $chunksUploaded,
        int $chunksTotal,
        ?string $updatedAt = null
    ): void {
        $fields = [
            'status' => 'downloading',
            'chunksUploaded' => $chunksUploaded,
            'chunksTotal' => $chunksTotal,
        ];
        if ($updatedAt !== null) {
            $fields['updatedAt'] = $updatedAt;
        }
        $this->seedResource('video', $videoId, $fields);
    }

    private function createWaitingRendition(string $videoId): array
    {
        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $this->assertEquals(200, $profiles['headers']['status-code']);
        $this->assertNotEmpty($profiles['body']['profiles']);
        $profileId = $profiles['body']['profiles'][0]['$id'];

        // createReadyVideo ensures source is ready for assertSourceReady
        $rendition = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profileId,
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $rendition['headers']['status-code']);

        return $rendition['body'];
    }

    public function testCleanStaleAbortsStuckDownload(): void
    {
        $videoId = $this->createPendingVideo();
        $tmp = $this->touchTmpSource($videoId);
        $this->seedStuckDownload($videoId, 2, 10, $this->hourAgo());

        $this->triggerCleanStale();

        $body = $this->getVideo($videoId);
        $this->assertEquals('aborted', $body['status']);
        \clearstatcache(true, $tmp);
        $this->assertFileDoesNotExist($tmp);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files/' . $this->getVideoFile()['$id'], $this->headers());
        $this->assertEquals(200, $file['headers']['status-code']);
    }

    public function testCleanStaleAbortsDownloadWithAllChunks(): void
    {
        $videoId = $this->createPendingVideo();
        $this->seedStuckDownload($videoId, 10, 10, $this->hourAgo());

        $this->triggerCleanStale();

        $body = $this->getVideo($videoId);
        $this->assertEquals('aborted', $body['status']);
    }

    public function testCleanStaleSkipsFreshDownload(): void
    {
        $videoId = $this->createPendingVideo();
        // Seed downloading without backdating — $updatedAt stays recent.
        $this->seedStuckDownload($videoId, 2, 10);

        $this->triggerCleanStale();

        $body = $this->getVideo($videoId);
        $this->assertEquals('downloading', $body['status']);
    }

    private function seedStuckEncode(string $videoId, string $renditionId, string $progress): void
    {
        // Race the encode worker: park as `error` so an in-flight pack halts
        // (worker sets a local halted flag and stops writing), then stamp the
        // stale `started` snapshot the sweeper looks for. Retry until the GET
        // view matches — a late claim/progress write can otherwise freshen
        // `$updatedAt` or overwrite progress before the sweep runs.
        $staleBefore = (new \DateTime())->modify('-20 minutes');
        $deadline = \time() + 45;

        while (\time() < $deadline) {
            $this->seedResource('videos_rendition', $renditionId, [
                'status' => 'error',
                'progress' => $progress,
            ]);

            for ($i = 0; $i < 30; $i++) {
                if (($this->getRendition($videoId, $renditionId)['status'] ?? '') === 'error') {
                    break;
                }
                \usleep(100000);
            }

            // Give the worker's ~500ms status poll a chance to observe `error`
            // and set its halted flag before we re-enter `started`.
            \usleep(600000);

            $updatedAt = $this->hourAgo();
            $this->seedResource('videos_rendition', $renditionId, [
                'status' => 'started',
                'progress' => $progress,
                'updatedAt' => $updatedAt,
            ]);
            // Stamp again so a write that raced the previous update cannot leave
            // a fresh `$updatedAt` for the sweeper cutoff.
            $this->seedResource('videos_rendition', $renditionId, [
                'status' => 'started',
                'progress' => $progress,
                'updatedAt' => $updatedAt,
            ]);

            \usleep(150000);
            $body = $this->getRendition($videoId, $renditionId);
            if (
                ($body['status'] ?? '') === 'started'
                && (string) ($body['progress'] ?? '') === $progress
                && !empty($body['$updatedAt'])
                && new \DateTime($body['$updatedAt']) < $staleBefore
            ) {
                return;
            }
        }

        $body = $this->getRendition($videoId, $renditionId);
        $this->fail(
            'Could not seed stuck encode snapshot; last status='
            . ($body['status'] ?? '')
            . ' progress=' . ($body['progress'] ?? '')
            . ' updatedAt=' . ($body['$updatedAt'] ?? '')
        );
    }

    public function testCleanStaleAbortsStuckEncode(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createWaitingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStuckEncode($videoId, $renditionId, '50');
        $this->triggerCleanStale();

        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('aborted', $body['status']);
        $this->assertNotEmpty($body['endedAt']);
    }

    public function testCleanStaleAbortsEncodeAt100(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createWaitingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStuckEncode($videoId, $renditionId, '100');
        $this->triggerCleanStale();

        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('aborted', $body['status']);
        $this->assertNotEmpty($body['endedAt']);
    }

    public function testCleanStaleAbortsUploading(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createWaitingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedResource('videos_rendition', $renditionId, [
            'status' => 'uploading',
            'progress' => '100',
            'updatedAt' => $this->hourAgo(),
        ]);
        $this->seedResource('videos_rendition', $renditionId, [
            'updatedAt' => $this->hourAgo(),
        ]);

        $this->triggerCleanStale();

        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('aborted', $body['status']);
        $this->assertNotEmpty($body['endedAt']);
    }

    public function testCleanStaleAbortsEnded(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createWaitingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedResource('videos_rendition', $renditionId, [
            'status' => 'ended',
            'progress' => '99',
            'updatedAt' => $this->hourAgo(),
        ]);
        $this->seedResource('videos_rendition', $renditionId, [
            'updatedAt' => $this->hourAgo(),
        ]);

        $this->triggerCleanStale();

        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('aborted', $body['status']);
        $this->assertNotEmpty($body['endedAt']);
    }

    public function testCleanStaleIgnoresWaiting(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createWaitingRendition($videoId);
        $renditionId = $rendition['$id'];

        // Force waiting + old updatedAt so a misconfigured status array would abort it.
        $this->seedResource('videos_rendition', $renditionId, [
            'status' => 'waiting',
            'progress' => '0',
            'updatedAt' => $this->hourAgo(),
        ]);
        $this->seedResource('videos_rendition', $renditionId, [
            'updatedAt' => $this->hourAgo(),
        ]);

        $this->triggerCleanStale();

        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('waiting', $body['status']);
    }

    public function testReDownloadAfterAborted(): void
    {
        $videoId = $this->createPendingVideo();
        $this->seedStuckDownload($videoId, 2, 10, $this->hourAgo());
        $this->triggerCleanStale();
        $this->assertEquals('aborted', $this->getVideo($videoId)['status']);

        $source = $this->createSource($videoId, $this->headers());
        $this->assertEquals(202, $source['headers']['status-code']);

        // waitForVideoReady treats any non-pending status as done, including
        // aborted — wait explicitly for ready after a re-download.
        $ready = $this->waitForVideoStatus($videoId, 'ready');
        $this->assertEquals('ready', $ready['status']);
    }

    public function testReEncodeAfterAborted(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createWaitingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStuckEncode($videoId, $renditionId, '40');
        $this->triggerCleanStale();
        $this->assertEquals('aborted', $this->getRendition($videoId, $renditionId)['status']);

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/videos/' . $videoId . '/renditions/' . $renditionId,
            $this->headers()
        );
        $this->assertEquals(204, $delete['headers']['status-code']);

        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profileId = $profiles['body']['profiles'][0]['$id'];

        $retry = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profileId,
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $retry['headers']['status-code']);
        $this->assertEquals('waiting', $retry['body']['status']);
        $this->assertNotSame($renditionId, $retry['body']['$id']);
    }
}
