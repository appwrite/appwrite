<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Tests\Unit\Support\PartialWriteStream;
use Utopia\NATS\Transport\TcpTransport;

/**
 * TcpTransport::write must loop until every byte is written, even when the
 * underlying stream accepts only a few bytes per fwrite (short writes). The
 * partial-write stream wrapper forces that condition deterministically.
 */
final class TcpTransportWriteTest extends TestCase
{
    protected function setUp(): void
    {
        PartialWriteStream::$buffer = '';
        PartialWriteStream::$chunk = 3;
        PartialWriteStream::$writeCalls = 0;

        if (!\in_array('partialwrite', stream_get_wrappers(), true)) {
            stream_wrapper_register('partialwrite', PartialWriteStream::class);
        }
    }

    protected function tearDown(): void
    {
        if (\in_array('partialwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('partialwrite');
        }
    }

    public function testWriteLoopsUntilAllBytesWritten(): void
    {
        $stream = fopen('partialwrite://x', 'w+');
        $this->assertNotFalse($stream);

        $transport = new TcpTransport();
        $prop = new \ReflectionProperty(TcpTransport::class, 'stream');
        $prop->setValue($transport, $stream);

        $data = 'HELLO NATS WORLD'; // 16 bytes, chunk size 3
        $written = $transport->write($data);

        $this->assertSame(\strlen($data), $written);
        $this->assertSame($data, PartialWriteStream::$buffer, 'all bytes reached the sink');
        // 16 bytes at 3 bytes/call => ceil(16/3) = 6 fwrite calls.
        $this->assertSame(6, PartialWriteStream::$writeCalls);

        fclose($stream);
    }
}
