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

    /**
     * Plant a marker under jobs/{renditionId}/ so the sweeper's releaseTmpJob
     * can be asserted without racing a live ffmpeg pack.
     */
    private function touchTmpJob(string $videoId, string $renditionId): string
    {
        $dir = $this->tmpJobPath($videoId, $renditionId) . '/out';
        if (!\is_dir($dir)) {
            $this->assertTrue(\mkdir($dir, 0755, true) || \is_dir($dir));
        }
        $marker = $dir . '/stale-job-marker';
        $this->assertNotFalse(\file_put_contents($marker, 'stale-job'));

        return $marker;
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

    private function createPendingRendition(string $videoId): array
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

    /**
     * Move a pending rendition into a stale encode status without racing a live
     * ffmpeg. createRendition enqueues Encode — park `error` first so an
     * in-flight pack stops DB writes, wait until its job workspace is gone
     * (worker `finally`), then apply the stale snapshot the sweeper matches.
     */
    private function seedStaleEncodeStatus(
        string $videoId,
        string $renditionId,
        string $status,
        string $progress
    ): void {
        $staleBefore = (new \DateTime())->modify('-20 minutes');
        $deadline = \time() + 90;

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

            // Let the worker's ~500ms status poll observe `error` and halt.
            \usleep(600000);

            // Wait for the encode coroutine to finish and clean its workspace
            // (or never create one if it never claimed).
            $jobDir = $this->tmpJobPath($videoId, $renditionId);
            $jobDeadline = \time() + 60;
            while (\time() < $jobDeadline) {
                \clearstatcache(true, $jobDir);
                if (!\is_dir($jobDir)) {
                    break;
                }
                \usleep(200000);
            }

            $updatedAt = $this->hourAgo();
            $this->seedResource('videos_rendition', $renditionId, [
                'status' => $status,
                'progress' => $progress,
                'updatedAt' => $updatedAt,
            ]);
            // Stamp again so a write that raced the previous update cannot leave
            // a fresh `$updatedAt` for the sweeper cutoff.
            $this->seedResource('videos_rendition', $renditionId, [
                'status' => $status,
                'progress' => $progress,
                'updatedAt' => $updatedAt,
            ]);

            \usleep(150000);
            $body = $this->getRendition($videoId, $renditionId);
            if (
                ($body['status'] ?? '') === $status
                && (string) ($body['progress'] ?? '') === $progress
                && !empty($body['$updatedAt'])
                && new \DateTime($body['$updatedAt']) < $staleBefore
            ) {
                return;
            }
        }

        $body = $this->getRendition($videoId, $renditionId);
        $this->fail(
            'Could not seed stale encode snapshot; last status='
            . ($body['status'] ?? '')
            . ' progress=' . ($body['progress'] ?? '')
            . ' updatedAt=' . ($body['$updatedAt'] ?? '')
        );
    }

    private function seedStuckEncode(string $videoId, string $renditionId, string $progress): void
    {
        $this->seedStaleEncodeStatus($videoId, $renditionId, 'started', $progress);
    }

    private function assertRenditionAbortedAndJobGone(
        string $videoId,
        string $renditionId,
        string $jobMarker
    ): void {
        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('aborted', $body['status']);
        $this->assertNotEmpty($body['endedAt']);
        \clearstatcache(true, $jobMarker);
        $this->assertFileDoesNotExist($jobMarker);
        \clearstatcache(true, $this->tmpJobPath($videoId, $renditionId));
        $this->assertDirectoryDoesNotExist($this->tmpJobPath($videoId, $renditionId));
    }

    public function testCleanStaleAbortsStuckEncode(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createPendingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStuckEncode($videoId, $renditionId, '50');
        $marker = $this->touchTmpJob($videoId, $renditionId);
        $this->triggerCleanStale();

        $this->assertRenditionAbortedAndJobGone($videoId, $renditionId, $marker);
    }

    public function testCleanStaleAbortsEncodeAt100(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createPendingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStuckEncode($videoId, $renditionId, '100');
        $marker = $this->touchTmpJob($videoId, $renditionId);
        $this->triggerCleanStale();

        $this->assertRenditionAbortedAndJobGone($videoId, $renditionId, $marker);
    }

    public function testCleanStaleAbortsUploading(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createPendingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStaleEncodeStatus($videoId, $renditionId, 'uploading', '100');
        $marker = $this->touchTmpJob($videoId, $renditionId);
        $this->triggerCleanStale();

        $this->assertRenditionAbortedAndJobGone($videoId, $renditionId, $marker);
    }

    public function testCleanStaleAbortsEnded(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createPendingRendition($videoId);
        $renditionId = $rendition['$id'];

        $this->seedStaleEncodeStatus($videoId, $renditionId, 'ended', '99');
        $marker = $this->touchTmpJob($videoId, $renditionId);
        $this->triggerCleanStale();

        $this->assertRenditionAbortedAndJobGone($videoId, $renditionId, $marker);
    }

    public function testCleanStaleIgnoresPending(): void
    {
        $ready = $this->createReadyVideo();
        $videoId = $ready['$id'];
        $rendition = $this->createPendingRendition($videoId);
        $renditionId = $rendition['$id'];

        // Force pending + old updatedAt so a misconfigured status array would abort it.
        $this->seedResource('videos_rendition', $renditionId, [
            'status' => 'pending',
            'progress' => '0',
            'updatedAt' => $this->hourAgo(),
        ]);
        $this->seedResource('videos_rendition', $renditionId, [
            'updatedAt' => $this->hourAgo(),
        ]);

        $this->triggerCleanStale();

        $body = $this->getRendition($videoId, $renditionId);
        $this->assertEquals('pending', $body['status']);
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
        $rendition = $this->createPendingRendition($videoId);
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

        // Halting an in-flight encode lets the worker finish and tryRelease may
        // drop the tmp source — recreate it before queuing another rendition.
        if (($this->getVideo($videoId)['status'] ?? '') !== 'ready') {
            $source = $this->createSource($videoId, $this->headers());
            $this->assertEquals(202, $source['headers']['status-code']);
            $this->waitForVideoStatus($videoId, 'ready');
        }

        $profiles = $this->client->call(Client::METHOD_GET, '/videos/profiles', $this->headers());
        $profileId = $profiles['body']['profiles'][0]['$id'];

        $retry = $this->client->call(Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $this->headers(), [
            'profileId' => $profileId,
            'output' => 'hls',
        ]);
        $this->assertEquals(202, $retry['headers']['status-code']);
        $this->assertEquals('pending', $retry['body']['status']);
        $this->assertNotSame($renditionId, $retry['body']['$id']);
    }
}
