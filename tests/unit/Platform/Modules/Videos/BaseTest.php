<?php

namespace Tests\Unit\Platform\Modules\Videos;

use Appwrite\Platform\Modules\Videos\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            'download running' => [Base::SOURCE_DOWNLOADING, false, false, false],
            'rendition in flight' => [Base::SOURCE_READY, true, false, false],
            'job dir remains' => [Base::SOURCE_READY, false, true, false],
            'pending video with leftover job' => [Base::SOURCE_PENDING, false, true, false],
            'removed idle' => [Base::SOURCE_REMOVED, false, false, true],
        ];
    }
}
