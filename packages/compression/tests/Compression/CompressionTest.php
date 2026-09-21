<?php

declare(strict_types=1);

namespace Utopia\Tests\Compression;

use PHPUnit\Framework\TestCase;
use Utopia\Compression\Algorithms\Brotli;
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
        $this->assertEquals(new Gzip(), Compression::fromAcceptEncoding('gzip;q=0.5, br;q=0.5', [Compression::BROTLI, Compression::GZIP]));
        $this->assertEquals(new Snappy(), Compression::fromAcceptEncoding('snappy;q=0.6, br;q=0.4', [Compression::SNAPPY, Compression::BROTLI]));

        // Not supported
        $this->assertEquals(null, Compression::fromAcceptEncoding('gzip;q=0.5, br;q=0.5', [Compression::SNAPPY]));
        $this->assertEquals(null, Compression::fromAcceptEncoding('snappy;q=0.6, br;q=0.4', [Compression::GZIP]));

        // Invalid accept encoding
        $this->assertEquals(null, Compression::fromAcceptEncoding('adfkljasdjkf', [Compression::BROTLI]));
        $this->assertEquals(null, Compression::fromAcceptEncoding('adfkljasdjkf'));
    }
}
