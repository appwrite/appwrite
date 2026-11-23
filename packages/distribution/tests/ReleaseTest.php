<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Release;

final class ReleaseTest extends TestCase
{
    public function testDefaults(): void
    {
        $release = new Release('io.example.app', Release::TRACK_PRODUCTION, '1.0.0', '42');

        $this->assertSame('io.example.app', $release->identifier);
        $this->assertSame(Release::TRACK_PRODUCTION, $release->track);
        $this->assertSame('1.0.0', $release->version);
        $this->assertSame('42', $release->build);
        $this->assertSame(1.0, $release->fraction);
        $this->assertSame([], $release->notes);
        $this->assertTrue($release->automatic);
        $this->assertSame([], $release->groups);
        $this->assertNull($release->encryption);
    }

    public function testAllFields(): void
    {
        $release = new Release(
            'io.example.app',
            Release::TRACK_TESTFLIGHT_EXTERNAL,
            '1.0.0',
            '42',
            fraction: 0.05,
            notes: ['en-US' => 'Bug fixes', 'de-DE' => 'Fehlerbehebungen'],
            automatic: false,
            groups: ['group-1', 'group-2'],
            encryption: false,
        );

        $this->assertSame(0.05, $release->fraction);
        $this->assertSame(['en-US' => 'Bug fixes', 'de-DE' => 'Fehlerbehebungen'], $release->notes);
        $this->assertFalse($release->automatic);
        $this->assertSame(['group-1', 'group-2'], $release->groups);
        $this->assertFalse($release->encryption);
    }

    public function testCustomTrackIsAccepted(): void
    {
        $release = new Release('io.example.app', 'closed-testers', '1.0.0', '42');

        $this->assertSame('closed-testers', $release->track);
    }

    public function testFullFractionIsAccepted(): void
    {
        $release = new Release('io.example.app', Release::TRACK_PRODUCTION, '1.0.0', '42', fraction: 1.0);

        $this->assertSame(1.0, $release->fraction);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function fractions(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'above one' => [1.5];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('fractions')]
    public function testFractionOutsideRangeThrows(float $fraction): void
    {
        $this->expectException(Invalid::class);

        new Release('io.example.app', Release::TRACK_PRODUCTION, '1.0.0', '42', fraction: $fraction);
    }

    public function testEmptyTrackThrows(): void
    {
        $this->expectException(Invalid::class);

        new Release('io.example.app', '', '1.0.0', '42');
    }
}
