<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use Appwrite\Video\Timeline;
use PHPUnit\Framework\TestCase;

final class TimelineTest extends TestCase
{
    public function testRewritesLegacyPrefixesToThumbnailsWithProject(): void
    {
        $vtt = <<<'VTT'
WEBVTT

00:00:00.000 --> 00:00:02.000
previews/abc#xywh=0,0,160,90

00:00:02.000 --> 00:00:04.000
sprites/def#xywh=160,0,160,90

00:00:04.000 --> 00:00:06.000
thumbnails/ghi#xywh=320,0,160,90
VTT;

        $out = Timeline::withProjectOnThumbnailCues($vtt, 'proj1');

        $this->assertStringContainsString(
            'thumbnails/abc?project=proj1#xywh=0,0,160,90',
            $out
        );
        $this->assertStringContainsString(
            'thumbnails/def?project=proj1#xywh=160,0,160,90',
            $out
        );
        $this->assertStringContainsString(
            'thumbnails/ghi?project=proj1#xywh=320,0,160,90',
            $out
        );
        $this->assertStringNotContainsString('previews/', $out);
        $this->assertStringNotContainsString('sprites/', $out);
    }

    public function testLeavesNonCueLinesAlone(): void
    {
        $vtt = "WEBVTT\n\nNOTE keep me\n";
        $this->assertSame($vtt, Timeline::withProjectOnThumbnailCues($vtt, 'proj1'));
    }
}
