<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Transport\TcpTransport;

/**
 * The connected socket must have Nagle disabled.
 *
 * Request-reply here writes the inbox SUB and then the request PUB before it
 * reads anything. With Nagle on, that second small write is held until the peer
 * acknowledges the first, and the peer's delayed-ACK timer makes that around
 * 40ms -- per exchange, on loopback as readily as over a network. Nothing fails,
 * so no test that only checks correctness can see it; the option has to be
 * asserted on the socket itself.
 */
final class TcpTransportNoDelayTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private string $address = '';

    protected function setUp(): void
    {
        if (!\function_exists('socket_import_stream')) {
            $this->markTestSkipped('Reading a socket option back needs ext-sockets');
        }

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("Could not open a loopback listener: [{$errno}] {$errstr}");
        }

        $this->server = $server;
        $this->address = (string) stream_socket_get_name($server, false);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            fclose($this->server);
        }
    }

    public function testConnectedSocketHasNagleDisabled(): void
    {
        [$host, $port] = explode(':', $this->address);

        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 2.0);

        try {
            $this->assertTrue($transport->isConnected());

            $property = new \ReflectionProperty(TcpTransport::class, 'stream');
            $stream = $property->getValue($transport);
            $this->assertIsResource($stream);

            // socket_import_stream shares the underlying descriptor, so this reads
            // the option actually set on the connected socket rather than a copy of
            // whatever the context asked for.
            $socket = socket_import_stream($stream);
            if ($socket === false) {
                $this->markTestSkipped('This platform cannot import a stream as a socket');
            }

            // Asserted as "not off" rather than a literal: the value read back is
            // platform-specific (1 on Linux, 4 on macOS) while 0 is off everywhere.
            $this->assertNotSame(
                0,
                socket_get_option($socket, SOL_TCP, TCP_NODELAY),
                'TCP_NODELAY must be set, or every request-reply stalls on the peer delayed ACK',
            );
        } finally {
            $transport->close();
        }
    }
}
