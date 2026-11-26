<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Videos;

use Appwrite\Platform\Modules\Videos\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class BaseTest extends TestCase
{
    protected function setUp(): void
    {
        Config::setParam('videos-codecs', require \dirname(__DIR__, 5) . '/app/config/videos-codecs.php');

        if (!\defined('APP_STORAGE_VIDEOS_TMP')) {
            \define('APP_STORAGE_VIDEOS_TMP', \sys_get_temp_dir() . '/appwrite-videos-tmp-' . \uniqid('', true));
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

    public function testJobMatchesRenditionSuffix(): void
    {
        $name = '202610121200~proj1~vid1~rendition-ren1';
        $neighbor = '202610121200~proj1~vid1~rendition-ren2';
        $caption = '202610121200~proj1~vid1~caption-cap1';

        $this->assertTrue(Base::jobMatches($name, 'proj1', 'vid1', 'rendition-ren1'));
        $this->assertFalse(Base::jobMatches($name, 'proj1', 'vid1', 'rendition-ren2'));
        $this->assertFalse(Base::jobMatches($name, 'proj1', 'vid1', 'rendition-ren'));
        $this->assertFalse(Base::jobMatches($neighbor, 'proj1', 'vid1', 'rendition-ren1'));
        $this->assertFalse(Base::jobMatches($caption, 'proj1', 'vid1', 'rendition-ren1'));
        $this->assertFalse(Base::jobMatches($name, 'proj1', '', 'rendition-ren1'));
        $this->assertTrue(Base::jobMatches($neighbor, 'proj1', 'vid1'));
    }

    public function testWithProjectAppendsUpdatedAt(): void
    {
        $base = new class () extends Base {
            public function expose(string $uri, \Utopia\Database\Document $project, \Utopia\Database\Document $video): string
            {
                return $this->withProject($uri, $project, $video);
            }

            public function exposePublic(\Utopia\Database\Document $video): bool
            {
                return $this->isPublicRead($video);
            }
        };

        $project = new \Utopia\Database\Document(['$id' => 'proj1']);
        $video = new \Utopia\Database\Document([
            '$id' => 'vid1',
            '$updatedAt' => '2026-01-02T03:04:05.000+00:00',
            '$permissions' => ['read("any")'],
        ]);

        $this->assertSame(
            '/v1/videos/vid1/outputs/hls/master.m3u8?project=proj1&updatedAt=2026-01-02T03%3A04%3A05.000%2B00%3A00',
            $base->expose('/v1/videos/vid1/outputs/hls/master.m3u8', $project, $video)
        );
        $this->assertTrue($base->exposePublic($video));
        $this->assertFalse($base->exposePublic(new \Utopia\Database\Document([
            '$permissions' => ['read("user:abc")'],
        ])));
        $this->assertSame('video-proj1-vid1', Base::videoSurrogateKey('proj1', 'vid1'));
        $this->assertSame('video-proj1-vid1', Base::videoSurrogateKey('Proj1', 'Vid1'));
    }

}
