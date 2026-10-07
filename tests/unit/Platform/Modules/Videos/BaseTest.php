<?php

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
    }

    #[DataProvider('codecOutputMatrix')]
    public function testCodecSupportsOutput(string $codec, string $output, bool $expected): void
    {
        $this->assertSame($expected, Base::codecSupportsOutput($codec, $output));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function codecOutputMatrix(): array
    {
        return [
            'h264 hls' => [Base::CODEC_H264, Base::OUTPUT_HLS, true],
            'h264 dash' => [Base::CODEC_H264, Base::OUTPUT_DASH, true],
            'h264 cmaf' => [Base::CODEC_H264, Base::OUTPUT_CMAF, true],
            'hevc hls' => [Base::CODEC_HEVC, Base::OUTPUT_HLS, true],
            'hevc dash' => [Base::CODEC_HEVC, Base::OUTPUT_DASH, true],
            'hevc cmaf' => [Base::CODEC_HEVC, Base::OUTPUT_CMAF, true],
            'vp9 dash' => [Base::CODEC_VP9, Base::OUTPUT_DASH, true],
            'vp9 hls' => [Base::CODEC_VP9, Base::OUTPUT_HLS, false],
            'vp9 cmaf' => [Base::CODEC_VP9, Base::OUTPUT_CMAF, false],
            'unknown codec' => ['av1', Base::OUTPUT_HLS, false],
            'unknown output' => [Base::CODEC_H264, 'mkv', false],
        ];
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
}
