<?php

declare(strict_types=1);

namespace Utopia\Compression\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Compression\Algorithms\Brotli;
use Utopia\Compression\Algorithms\Deflate;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Snappy;
use Utopia\Compression\Algorithms\XZ;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;

final class CompressionTest extends TestCase
{
    public function testFromName(): void
    {
        $this->assertEquals(new Brotli(), Compression::fromName('brotli'));
        $this->assertEquals(new Snappy(), Compression::fromName('snappy'));
        $this->assertEquals(new XZ(), Compression::fromName('xz'));
        $this->assertEquals(new Zstd(), Compression::fromName('zstd'));
        $this->assertEquals(null, Compression::fromName('unknown'));
    }

    public function testFromAcceptEncodingSkipsRefusedEncodings(): void
    {
        $this->assertEquals(new Deflate(), Compression::fromAcceptEncoding('gzip;q=0, deflate'));
    }

    /**
     * RFC 9110 section 12.4.2 spells a weight `OWS ";" OWS "q=" qvalue`, so
     * optional whitespace sits around the `;` and a client may legally send
     * `gzip ; q=0.5`. The coding must still be selected.
     */
    public function testFromAcceptEncodingAllowsWhitespaceAroundWeight(): void
    {
        $supported = [Compression::GZIP, Compression::DEFLATE];

        $this->assertEquals(new GZIP(), Compression::fromAcceptEncoding('gzip ;q=0.5', $supported));
        $this->assertEquals(new GZIP(), Compression::fromAcceptEncoding('gzip ; q=0.5', $supported));
        $this->assertEquals(new GZIP(), Compression::fromAcceptEncoding('gzip; q=0.5', $supported));
        $this->assertEquals(new GZIP(), Compression::fromAcceptEncoding("gzip\t;\tq=0.5", $supported));

        // Whitespace does not change which coding wins.
        $this->assertEquals(new Deflate(), Compression::fromAcceptEncoding('gzip ; q=0.5, deflate ; q=0.9', $supported));
    }

    public function testFromAcceptEncoding(): void
    {
        $this->assertEquals(new Brotli(), Compression::fromAcceptEncoding('br'));

        // Quality
        $this->assertEquals(new Brotli(), Compression::fromAcceptEncoding('br;q=0.5'));

        // Quality and other encodings
        $this->assertEquals(new Brotli(), Compression::fromAcceptEncoding('br;q=0.5, gzip;q=0.5'));

        // Supported
        $this->assertEquals(new GZIP(), Compression::fromAcceptEncoding('gzip;q=0.5, br;q=0.5', [Compression::GZIP]));
        $this->assertEquals(new Snappy(), Compression::fromAcceptEncoding('snappy;q=0.6, br;q=0.4', [Compression::SNAPPY]));

        // First in priority
        $this->assertEquals(new GZIP(), Compression::fromAcceptEncoding('gzip;q=0.5, br;q=0.5', [Compression::BROTLI, Compression::GZIP]));
        $this->assertEquals(new Snappy(), Compression::fromAcceptEncoding('snappy;q=0.6, br;q=0.4', [Compression::SNAPPY, Compression::BROTLI]));

        // Not supported
        $this->assertEquals(null, Compression::fromAcceptEncoding('gzip;q=0.5, br;q=0.5', [Compression::SNAPPY]));
        $this->assertEquals(null, Compression::fromAcceptEncoding('snappy;q=0.6, br;q=0.4', [Compression::GZIP]));

        // Invalid accept encoding
        $this->assertEquals(null, Compression::fromAcceptEncoding('adfkljasdjkf', [Compression::BROTLI]));
        $this->assertEquals(null, Compression::fromAcceptEncoding('adfkljasdjkf'));
    }
}
