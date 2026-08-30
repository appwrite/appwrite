<?php

namespace Tests\Unit\Platform\Modules\Videos;

use Appwrite\Platform\Modules\Videos\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class BaseTest extends TestCase
{
    public function testChunkCountNeverBelowOne(): void
    {
        $this->assertSame(1, Base::chunkCount(0));
        $this->assertSame(1, Base::chunkCount(1));
        $this->assertSame(1, Base::chunkCount(APP_LIMIT_UPLOAD_CHUNK_SIZE));
        $this->assertSame(2, Base::chunkCount(APP_LIMIT_UPLOAD_CHUNK_SIZE + 1));
    }

    public function testSourceMatchesRejectsMissingAndWrongSize(): void
    {
        $path = \sys_get_temp_dir() . '/appwrite-video-source-' . \uniqid('', true);
        $this->assertFalse(Base::sourceMatches($path, 4));
        $this->assertFalse(Base::sourceMatches($path, 0));

        \file_put_contents($path, 'abcd');
        try {
            $this->assertTrue(Base::sourceMatches($path, 4));
            $this->assertFalse(Base::sourceMatches($path, 3));
            $this->assertFalse(Base::sourceMatches($path, 0));
        } finally {
            @\unlink($path);
        }
    }

    public function testSourceExistsClearsStaleNegativeCache(): void
    {
        $path = \sys_get_temp_dir() . '/appwrite-video-source-' . \uniqid('', true);
        // Prime PHP's stat cache with a miss, then create the file — without
        // clearstatcache the next is_file() can still report false in-process.
        $this->assertFalse(\is_file($path));
        \file_put_contents($path, 'x');
        try {
            $this->assertTrue(Base::sourceExists($path));
        } finally {
            @\unlink($path);
        }
    }

    public function testStaleSourceStatusesContainsDownloadingOnly(): void
    {
        $this->assertSame([Base::SOURCE_DOWNLOADING], Base::STALE_SOURCE_STATUSES);
    }

    public function testStaleEncodeStatusesIncludesPostEncodePhases(): void
    {
        $this->assertSame([
            Base::STATUS_STARTED,
            Base::STATUS_ENDED,
            Base::STATUS_UPLOADING,
        ], Base::STALE_ENCODE_STATUSES);
    }

    #[DataProvider('releaseGate')]
    public function testCanReleaseSource(string $status, bool $inFlight, bool $jobsRemain, bool $expected): void
    {
        $this->assertSame($expected, Base::canReleaseSource($status, $inFlight, $jobsRemain));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}>
     */
    public static function releaseGate(): array
    {
        return [
            'idle ready' => [Base::SOURCE_READY, false, false, true],
            'idle error' => [Base::SOURCE_ERROR, false, false, true],
            'idle aborted' => [Base::SOURCE_ABORTED, false, false, true],
            'download running' => [Base::SOURCE_DOWNLOADING, false, false, false],
            'rendition in flight' => [Base::SOURCE_READY, true, false, false],
            'job dir remains' => [Base::SOURCE_READY, false, true, false],
            'pending video with leftover job' => [Base::SOURCE_PENDING, false, true, false],
            'removed idle' => [Base::SOURCE_REMOVED, false, false, true],
        ];
    }

    #[DataProvider('staleDownloadCases')]
    public function testShouldAbortStaleDownload(array $attributes, bool $expected): void
    {
        $cutoff = new \DateTime('2026-01-01T12:00:00+00:00');
        $video = new Document($attributes);
        $this->assertSame($expected, Base::shouldAbortStaleDownload($video, $cutoff));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function staleDownloadCases(): array
    {
        return [
            'stale incomplete download' => [[
                'status' => Base::SOURCE_DOWNLOADING,
                'chunksUploaded' => 2,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], true],
            'fresh incomplete download' => [[
                'status' => Base::SOURCE_DOWNLOADING,
                'chunksUploaded' => 2,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T12:30:00.000+00:00',
            ], false],
            'stale download with all chunks' => [[
                'status' => Base::SOURCE_DOWNLOADING,
                'chunksUploaded' => 10,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], true],
            'fresh download with all chunks' => [[
                'status' => Base::SOURCE_DOWNLOADING,
                'chunksUploaded' => 10,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T12:30:00.000+00:00',
            ], false],
            'ready status ignored' => [[
                'status' => Base::SOURCE_READY,
                'chunksUploaded' => 2,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], false],
            'pending status ignored' => [[
                'status' => Base::SOURCE_PENDING,
                'chunksUploaded' => 0,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], false],
        ];
    }

    #[DataProvider('staleEncodeCases')]
    public function testShouldAbortStaleEncode(array $attributes, bool $expected): void
    {
        $cutoff = new \DateTime('2026-01-01T12:00:00+00:00');
        $rendition = new Document($attributes);
        $this->assertSame($expected, Base::shouldAbortStaleEncode($rendition, $cutoff));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function staleEncodeCases(): array
    {
        return [
            'stale started below 100' => [[
                'status' => Base::STATUS_STARTED,
                'progress' => '50',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], true],
            'stale started at 100' => [[
                'status' => Base::STATUS_STARTED,
                'progress' => '100',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], true],
            'fresh started' => [[
                'status' => Base::STATUS_STARTED,
                'progress' => '50',
                '$updatedAt' => '2026-01-01T12:30:00.000+00:00',
            ], false],
            'stale uploading' => [[
                'status' => Base::STATUS_UPLOADING,
                'progress' => '100',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], true],
            'fresh uploading' => [[
                'status' => Base::STATUS_UPLOADING,
                'progress' => '100',
                '$updatedAt' => '2026-01-01T12:30:00.000+00:00',
            ], false],
            'stale ended' => [[
                'status' => Base::STATUS_ENDED,
                'progress' => '99',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], true],
            'pending excluded' => [[
                'status' => Base::STATUS_PENDING,
                'progress' => '0',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], false],
        ];
    }

    public function testReleaseTmpSourceRemovesSourceAndLeftovers(): void
    {
        $projectId = 'proj-' . \uniqid('', true);
        $videoId = 'vid-' . \uniqid('', true);
        $dir = Base::tmpPath($projectId, $videoId);
        $source = Base::tmpSourcePath($projectId, $videoId);

        $this->assertTrue(\mkdir($dir, 0755, true) || \is_dir($dir));
        \file_put_contents($source, 'source');
        \file_put_contents($source . '.part', 'part');
        \file_put_contents($source . '.decoded', 'decoded');

        try {
            Base::releaseTmpSource($projectId, $videoId);
            $this->assertFileDoesNotExist($source);
            $this->assertFileDoesNotExist($source . '.part');
            $this->assertFileDoesNotExist($source . '.decoded');
        } finally {
            @\unlink($source);
            @\unlink($source . '.part');
            @\unlink($source . '.decoded');
            @\rmdir($dir);
            @\rmdir(\dirname($dir));
        }
    }

    public function testReleaseTmpJobRemovesOnlyTargetJobDirectory(): void
    {
        $projectId = 'proj-' . \uniqid('', true);
        $videoId = 'vid-' . \uniqid('', true);
        $staleId = 'rendition-stale';
        $liveId = 'rendition-live';
        $staleDir = Base::tmpJobPath($projectId, $videoId, $staleId);
        $liveDir = Base::tmpJobPath($projectId, $videoId, $liveId);

        $this->assertTrue(\mkdir($staleDir . '/out', 0755, true) || \is_dir($staleDir . '/out'));
        $this->assertTrue(\mkdir($liveDir . '/out', 0755, true) || \is_dir($liveDir . '/out'));
        \file_put_contents($staleDir . '/out/segment.ts', 'x');
        \file_put_contents($liveDir . '/out/segment.ts', 'y');

        try {
            Base::releaseTmpJob($projectId, $videoId, $staleId);
            $this->assertDirectoryDoesNotExist($staleDir);
            $this->assertDirectoryExists($liveDir);
            $this->assertFileExists($liveDir . '/out/segment.ts');
        } finally {
            foreach ([$staleDir, $liveDir] as $dir) {
                if (\is_dir($dir . '/out')) {
                    @\unlink($dir . '/out/segment.ts');
                    @\rmdir($dir . '/out');
                }
                @\rmdir($dir);
            }
            @\rmdir(Base::tmpPath($projectId, $videoId) . '/jobs');
            @\rmdir(Base::tmpPath($projectId, $videoId));
            @\rmdir(\dirname(Base::tmpPath($projectId, $videoId)));
        }
    }
}
