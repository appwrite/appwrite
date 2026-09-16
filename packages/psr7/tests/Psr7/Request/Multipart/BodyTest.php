<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7\Request\Multipart;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Psr7\Request\Multipart\Body;
use Utopia\Psr7\Request\Multipart\Part;

final class BodyTest extends TestCase
{
    public function testItSerialisesFilePartsLazilyAndMatchesAStreamedRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'utopia-part-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'file-bytes');

        try {
            $body = new Body('abc123', [
                Part::field('name', 'Ada'),
                Part::file('doc', $path, 'doc.txt', 'text/plain'),
            ]);

            $expected = "--abc123\r\n"
                . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
                . "Ada\r\n"
                . "--abc123\r\n"
                . "Content-Disposition: form-data; name=\"doc\"; filename=\"doc.txt\"\r\n"
                . "Content-Type: text/plain\r\n\r\n"
                . "file-bytes\r\n"
                . "--abc123--\r\n";

            $this->assertSame(\strlen($expected), $body->getSize());
            $this->assertSame($expected, (string) $body);

            // A chunked read reassembles the same bytes, and the body rewinds for retries.
            $body->rewind();
            $streamed = '';
            while (!$body->eof()) {
                $streamed .= $body->read(7);
            }

            $this->assertSame($expected, $streamed);
        } finally {
            unlink($path);
        }
    }

    public function testItRejectsMissingFiles(): void
    {
        $this->expectException(RuntimeException::class);

        Part::file('doc', '/nonexistent/utopia-missing-file');
    }
}
