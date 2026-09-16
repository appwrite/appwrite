<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Mime\Encoding;

final class EncodingTest extends TestCase
{
    public function testAsciiIsRecognised(): void
    {
        $this->assertTrue(Encoding::isAscii('plain text'));
        $this->assertTrue(Encoding::isAscii(''));
        $this->assertFalse(Encoding::isAscii('héllo'));
        $this->assertFalse(Encoding::isAscii("\x80"));
    }

    public function testQuotedPrintableEscapesWhatItMust(): void
    {
        $this->assertSame('h=C3=A9llo', Encoding::quotedPrintable('héllo'));
        $this->assertSame('plain text', Encoding::quotedPrintable('plain text'));
        $this->assertSame('1 =3D 1', Encoding::quotedPrintable('1 = 1'));
    }

    public function testQuotedPrintableNormalisesLineEndings(): void
    {
        $this->assertSame("one\r\ntwo\r\nthree", Encoding::quotedPrintable("one\ntwo\r\nthree"));
    }

    public function testQuotedPrintableKeepsLinesShort(): void
    {
        foreach (explode("\r\n", Encoding::quotedPrintable(str_repeat('long ', 100))) as $line) {
            // RFC 2045 section 6.7: 76 characters, the soft break included.
            $this->assertLessThanOrEqual(76, \strlen($line));
        }
    }

    public function testBase64WrapsAtSeventySix(): void
    {
        $encoded = Encoding::base64(random_bytes(1000));

        foreach (array_filter(explode("\r\n", $encoded)) as $line) {
            $this->assertLessThanOrEqual(76, \strlen($line));
        }

        $this->assertStringEndsWith("\r\n", $encoded, 'a part has to end on its own line');
    }

    public function testBase64RoundTrips(): void
    {
        $data = random_bytes(5000);

        $this->assertSame($data, base64_decode(Encoding::base64($data), true));
    }

    public function testLineEndingsAreNormalised(): void
    {
        $this->assertSame("a\r\nb", Encoding::lineEndings("a\nb"));
        $this->assertSame("a\r\nb", Encoding::lineEndings("a\rb"));
        $this->assertSame("a\r\nb", Encoding::lineEndings("a\r\nb"));
        $this->assertSame("a\r\n\r\nb", Encoding::lineEndings("a\n\rb"));
    }

    public function testEveryBoundaryIsDifferent(): void
    {
        $boundaries = [];

        for ($index = 0; $index < 50; ++$index) {
            $boundaries[] = Encoding::boundary();
        }

        $this->assertCount(50, array_unique($boundaries));
    }

    public function testABoundaryNeedsNoQuotingAndCannotAppearInBase64(): void
    {
        $boundary = Encoding::boundary();

        // RFC 2046 section 5.1.1 bounds a boundary at 70 characters and limits
        // it to a safe set. The leading "=_" keeps it out of any base64 body.
        $this->assertLessThanOrEqual(70, \strlen($boundary));
        $this->assertSame(1, preg_match('/^[0-9A-Za-z\'()+_,\-.\/:=?]+$/', $boundary));
        $this->assertStringStartsWith('=_', $boundary);
    }
}
