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

    public function testStaleSourceStatusesContainsDownloadingOnly(): void
    {
        $this->assertSame([Base::SOURCE_DOWNLOADING], Base::STALE_SOURCE_STATUSES);
    }

    public function testStaleEncodeStatusesContainsStartedOnly(): void
    {
        $this->assertSame([Base::STATUS_STARTED], Base::STALE_ENCODE_STATUSES);
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
            'complete chunks still downloading' => [[
                'status' => Base::SOURCE_DOWNLOADING,
                'chunksUploaded' => 10,
                'chunksTotal' => 10,
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
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
            'started at 100' => [[
                'status' => Base::STATUS_STARTED,
                'progress' => '100',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], false],
            'fresh started' => [[
                'status' => Base::STATUS_STARTED,
                'progress' => '50',
                '$updatedAt' => '2026-01-01T12:30:00.000+00:00',
            ], false],
            'uploading excluded' => [[
                'status' => Base::STATUS_UPLOADING,
                'progress' => '100',
                '$updatedAt' => '2026-01-01T11:00:00.000+00:00',
            ], false],
            'waiting excluded' => [[
                'status' => Base::STATUS_WAITING,
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

    public function testReleaseTmpJobsRemovesJobDirectories(): void
    {
        $projectId = 'proj-' . \uniqid('', true);
        $videoId = 'vid-' . \uniqid('', true);
        $jobs = Base::tmpPath($projectId, $videoId) . '/jobs';
        $jobDir = $jobs . '/job-' . \uniqid('', true);

        $this->assertTrue(\mkdir($jobDir . '/out', 0755, true) || \is_dir($jobDir . '/out'));
        \file_put_contents($jobDir . '/out/segment.ts', 'x');

        try {
            Base::releaseTmpJobs($projectId, $videoId);
            $this->assertDirectoryDoesNotExist($jobDir);
        } finally {
            if (\is_dir($jobDir . '/out')) {
                @\unlink($jobDir . '/out/segment.ts');
                @\rmdir($jobDir . '/out');
            }
            @\rmdir($jobDir);
            @\rmdir($jobs);
            @\rmdir(Base::tmpPath($projectId, $videoId));
            @\rmdir(\dirname(Base::tmpPath($projectId, $videoId)));
        }
    }
}
