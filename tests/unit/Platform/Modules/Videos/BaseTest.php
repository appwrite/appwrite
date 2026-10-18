<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Videos;

use Appwrite\Platform\Modules\Videos\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Lock\File;

final class BaseTest extends TestCase
{
    private static string $tmpRoot = '';

    protected function setUp(): void
    {
        Config::setParam('videos-codecs', require \dirname(__DIR__, 5) . '/app/config/videos-codecs.php');

        if (!\defined('APP_STORAGE_VIDEOS_TMP')) {
            self::$tmpRoot = \sys_get_temp_dir() . '/appwrite-videos-tmp-' . \uniqid('', true);
            \define('APP_STORAGE_VIDEOS_TMP', self::$tmpRoot);
        }
    }

    #[DataProvider('codecOutputMatrix')]
    public function testCodecSupportsOutput(string $codec, string $output, bool $expected): void
    {
        $this->assertSame($expected, Base::codecSupportsOutput($codec, $output));
    }

    /**
     * @return \Iterator<string, array{string, string, bool}>
     */
    public static function codecOutputMatrix(): \Iterator
    {
        yield 'h264 hls' => [Base::CODEC_H264, Base::OUTPUT_HLS, true];
        yield 'h264 dash' => [Base::CODEC_H264, Base::OUTPUT_DASH, true];
        yield 'h264 cmaf' => [Base::CODEC_H264, Base::OUTPUT_CMAF, true];
        yield 'hevc hls' => [Base::CODEC_HEVC, Base::OUTPUT_HLS, true];
        yield 'hevc dash' => [Base::CODEC_HEVC, Base::OUTPUT_DASH, true];
        yield 'hevc cmaf' => [Base::CODEC_HEVC, Base::OUTPUT_CMAF, true];
        yield 'vp9 dash' => [Base::CODEC_VP9, Base::OUTPUT_DASH, true];
        yield 'vp9 hls' => [Base::CODEC_VP9, Base::OUTPUT_HLS, false];
        yield 'vp9 cmaf' => [Base::CODEC_VP9, Base::OUTPUT_CMAF, false];
        yield 'unknown codec' => ['av1', Base::OUTPUT_HLS, false];
        yield 'unknown output' => [Base::CODEC_H264, 'mkv', false];
    }

    public function testEnabledCodecsOmitsDisabled(): void
    {
        $this->assertSame([Base::CODEC_H264], Base::enabledCodecs());
        $this->assertEqualsCanonicalizing(
            [Base::CODEC_H264, Base::CODEC_HEVC, Base::CODEC_VP9],
            Base::codecIds()
        );
    }

    public function testNormalizeCodecDefaultsEmptyToH264(): void
    {
        $this->assertSame(Base::CODEC_H264, Base::normalizeCodec(null));
        $this->assertSame(Base::CODEC_H264, Base::normalizeCodec(''));
        $this->assertSame(Base::CODEC_HEVC, Base::normalizeCodec('HEVC'));
    }

    public function testTmpJobPathShape(): void
    {
        $path = Base::tmpJobPath('proj1', 'vid1', Base::JOB_RENDITION, 'ren1');
        $name = \basename($path);

        $this->assertSame(Base::tmpJobsRoot() . '/' . $name, $path);
        $this->assertSame(12, \strpos($name, '~'));
        $this->assertTrue(\ctype_digit(\substr($name, 0, 12)));
        $this->assertStringEndsWith('~proj1~vid1~rendition-ren1', $name);
    }

    public function testJobExpiredByStamp(): void
    {
        $now = \gmdate('YmdHi');
        $old = \gmdate('YmdHi', \time() - Base::TMP_TTL - 120);
        $cutoff = \gmdate('YmdHi', \time() - Base::TMP_TTL);

        $this->assertTrue(Base::jobExpired($old . '~proj~vid~rendition-a', $cutoff));
        $this->assertFalse(Base::jobExpired($cutoff . '~proj~vid~rendition-edge', $cutoff));
        $this->assertFalse(Base::jobExpired($now . '~proj~vid~timeline-b', $cutoff));
        $this->assertFalse(Base::jobExpired('not-a-stamp', $cutoff));
        $this->assertFalse(Base::jobExpired('2026100617xx~proj~vid~caption-c', $cutoff));
        $this->assertFalse(Base::jobExpired('202610061759', $cutoff));
    }

    public function testJobMatchesProjectAndVideo(): void
    {
        $name = '202610121200~proj1~vid1~rendition-ren1';

        $this->assertTrue(Base::jobMatches($name, 'proj1', 'vid1'));
        $this->assertTrue(Base::jobMatches($name, 'proj1'));
        $this->assertFalse(Base::jobMatches($name, 'proj1', 'vid2'));
        $this->assertFalse(Base::jobMatches($name, 'proj1', 'vid'));
        $this->assertFalse(Base::jobMatches($name, 'proj', 'vid1'));
        $this->assertFalse(Base::jobMatches($name, 'proj10'));
        $this->assertFalse(Base::jobMatches('202610121200~proj10~vid1~rendition-ren1', 'proj1', 'vid1'));
        $this->assertFalse(Base::jobMatches('not-a-stamp', 'proj1', 'vid1'));
        $this->assertFalse(Base::jobMatches('2026101212xx~proj1~vid1~rendition-ren1', 'proj1', 'vid1'));
        $this->assertFalse(Base::jobMatches('202610121200', 'proj1'));
        $this->assertFalse(Base::jobMatches($name, ''));
    }

    public function testRenditionLockPathShape(): void
    {
        $this->assertSame(
            \rtrim(APP_STORAGE_VIDEOS_TMP, '/') . '/locks/proj1/ren1.lock',
            Base::renditionLockPath('proj1', 'ren1')
        );
    }

    public function testRemoveRenditionLockDeletesFileAndIsIdempotent(): void
    {
        $path = Base::renditionLockPath('proj-rm', 'ren-rm');
        $directory = \dirname($path);
        if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            $this->fail('Could not create lock directory');
        }

        try {
            \file_put_contents($path, '');
            $this->assertFileExists($path);

            Base::removeRenditionLock('proj-rm', 'ren-rm');
            $this->assertFileDoesNotExist($path);

            Base::removeRenditionLock('proj-rm', 'ren-rm');
            $this->assertFileDoesNotExist($path);
        } finally {
            if (\is_file($path)) {
                @\unlink($path);
            }
            if (\is_dir($directory)) {
                @\rmdir($directory);
            }
        }
    }

    public function testRenditionLockContendsAcrossFileInstances(): void
    {
        $path = Base::renditionLockPath('proj-lock', 'ren-lock');
        $directory = \dirname($path);
        if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            $this->fail('Could not create lock directory');
        }

        $holder = new File($path);
        $waiter = new File($path);

        try {
            $this->assertTrue($holder->tryAcquire());
            $this->assertFalse($waiter->tryAcquire(), 'Second File instance must not acquire while first holds');
            $holder->release();
            $this->assertTrue($waiter->tryAcquire());
            $waiter->release();
        } finally {
            Base::removeRenditionLock('proj-lock', 'ren-lock');
            if (\is_dir($directory)) {
                @\rmdir($directory);
            }
        }
    }
}
