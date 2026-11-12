<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Transport;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Tests\Support\Peer;
use Utopia\NATS\Tests\Support\Wait;
use Utopia\NATS\Transport\WebSocketTransport;

final class WebSocketTransportPollTest extends TestCase
{
    private const string PING_FRAME = "\x89\x00";

    private ?Peer $peer = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->peer?->stop();
    }

    public function testIdleConnectionIsNotReadable(): void
    {
        [$transport] = $this->connect();

        $this->assertFalse($transport->poll());

        $transport->close();
    }

    public function testDataFrameIsReadableAndLeftForTheRead(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send(self::binaryFrame("PING\r\nPONG\r\n"));

        $this->assertTrue(Wait::until($transport->poll(...)));
        $this->assertSame("PING\r\n", $transport->readLine(1.0));
        $this->assertTrue($transport->poll(), 'The rest of the frame is decoded and buffered');
        $this->assertSame("PONG\r\n", $transport->readLine(1.0));

        $transport->close();
    }

    public function testPingFrameIsReadableButCarriesNothingForTheReader(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->send(self::PING_FRAME);

        $this->assertTrue(Wait::until($transport->poll(...)), 'The socket has bytes, so it reads as ready');

        $started = microtime(true);
        try {
            $transport->read(64, 0.1);
            $this->fail('A ping frame has no NATS bytes, so the read must wait out its timeout');
        } catch (TimeoutException) {
        }

        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertFalse($transport->poll(), 'The read answered the ping and left nothing behind');

        $transport->close();
    }

    public function testReadTimingOutMidFrameKeepsTheFrame(): void
    {
        [$transport, $peer] = $this->connect();
        $frame = self::binaryFrame("MSG a 1 5\r\nhello\r\n");
        $peer->send(substr($frame, 0, 4));

        try {
            $transport->read(65536, 0.05);
            $this->fail('Half a frame has nothing for the reader yet');
        } catch (TimeoutException) {
        }
        $peer->send(substr($frame, 4));

        $this->assertSame("MSG a 1 5\r\nhello\r\n", $transport->read(65536, 1.0));

        $transport->close();
    }

    public function testHalfAFrameIsNotReadable(): void
    {
        [$transport, $peer] = $this->connect();
        $frame = self::binaryFrame("PING\r\n");
        $peer->send(substr($frame, 0, 3));

        try {
            $transport->read(65536, 0.05);
            $this->fail('Half a frame has nothing for the reader yet');
        } catch (TimeoutException) {
        }

        $this->assertFalse($transport->poll(), 'The buffered half frame must not read as ready');
        $peer->send(substr($frame, 3));
        $this->assertTrue(Wait::until($transport->poll(...)));
        $this->assertSame("PING\r\n", $transport->read(64, 1.0));

        $transport->close();
    }

    public function testClosedByThePeerIsReadable(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->close();

        $this->assertTrue(Wait::until($transport->poll(...)));
        $this->expectException(ConnectionException::class);
        $transport->read(64, 1.0);
    }

    #[RequiresPhpExtension('sockets')]
    public function testResetByThePeerIsReadable(): void
    {
        [$transport, $peer] = $this->connect();
        $peer->reset();

        $this->assertTrue(Wait::until($transport->poll(...)), 'A reset must read as ready, so the next read reports it');

        $transport->close();
    }

    public function testClosedTransportIsReadable(): void
    {
        [$transport] = $this->connect();
        $transport->close();

        $this->assertTrue($transport->poll(), 'A read would raise at once');
    }

    /** @return array{WebSocketTransport, Peer} */
    private function connect(): array
    {
        $this->peer = new Peer(websocket: true);
        $transport = new WebSocketTransport();
        $transport->connect('127.0.0.1', $this->peer->port, 5.0);
        $this->peer->accept();

        return [$transport, $this->peer];
    }

    private static function binaryFrame(string $payload): string
    {
        return "\x82" . pack('C', \strlen($payload)) . $payload;
    }
}
