<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Transport;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Tests\Support\Peer;
use Utopia\NATS\Tests\Support\Wait;
use Utopia\NATS\Transport\TcpTransport;

/**
 * poll() answers whether a read would return at once, without waiting and
 * without consuming anything, on a real socket.
 */
final class TcpTransportPollTest extends TestCase
{
    /** @var resource|null */
    private $server;

    /** @var resource|null */
    private $peer;

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->peer, $this->server] as $stream) {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testIdleConnectionIsNotReadable(): void
    {
        $transport = $this->connect();

        $this->assertFalse($transport->poll());

        $transport->close();
    }

    public function testPendingDataIsReadableAndLeftForTheRead(): void
    {
        $transport = $this->connect();
        $this->send("PING\r\n");

        $this->assertTrue(Wait::until($transport->poll(...)));
        $this->assertSame("PING\r\n", $transport->read(64, 1.0));

        $transport->close();
    }

    public function testClosedByThePeerIsReadable(): void
    {
        $transport = $this->connect();
        $this->closePeer();

        $this->assertTrue(Wait::until($transport->poll(...)));

        $transport->close();
    }

    #[RequiresPhpExtension('sockets')]
    public function testResetByThePeerIsReadable(): void
    {
        $transport = $this->connect();
        $this->assertIsResource($this->peer);
        $socket = socket_import_stream($this->peer);
        $this->assertInstanceOf(\Socket::class, $socket);
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        $this->closePeer();

        $this->assertTrue(Wait::until($transport->poll(...)), 'A reset must read as ready, so the next read reports it');
        $this->expectException(ConnectionException::class);
        $transport->read(64, 1.0);
    }

    public function testDataAlreadyInThePhpBufferIsReadable(): void
    {
        $transport = $this->connect();
        $this->send("PING\r\nPONG\r\n");

        $this->assertSame("PING\r\n", $transport->readLine(1.0));
        $this->assertTrue($transport->poll(), 'The rest of the segment sits in the stream buffer, not the socket');

        $transport->close();
    }

    public function testClosedTransportIsReadable(): void
    {
        $transport = $this->connect();
        $transport->close();

        $this->assertTrue($transport->poll(), 'A read would raise at once');
    }

    public function testDescriptorAboveTheSelectLimitIsStillPolled(): void
    {
        $handles = [];
        for ($i = 0; $i < 1100; $i++) {
            $handle = @fopen('/dev/null', 'r');
            if ($handle === false) {
                array_map(fclose(...), $handles);
                $this->markTestSkipped('The descriptor limit is too low to open a socket above 1024');
            }
            $handles[] = $handle;
        }

        try {
            $transport = $this->connect();

            $this->assertFalse($transport->poll(), 'An idle socket above FD_SETSIZE must not read as ready');
            $this->send("PING\r\n");
            $this->assertTrue(Wait::until($transport->poll(...)));

            $transport->close();
        } finally {
            array_map(fclose(...), $handles);
        }
    }

    public function testPollLeavesTheReadBlockingWithItsTimeout(): void
    {
        $transport = $this->connect();
        $transport->poll();

        $started = microtime(true);
        try {
            $transport->read(64, 0.1);
            $this->fail('An idle read must wait out its timeout rather than return');
        } catch (TimeoutException) {
        }

        $this->assertGreaterThanOrEqual(0.09, microtime(true) - $started);

        $transport->close();
    }

    #[RequiresPhpExtension('swoole')]
    #[RunInSeparateProcess]
    public function testHookedSocketIsPolledWithoutBlocking(): void
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $states = [];

        Coroutine\run(function () use (&$states): void {
            $transport = $this->connect();
            $states['idle'] = $transport->poll();
            $this->send("PING\r\n");
            $states['data'] = Wait::until($transport->poll(...));
            $states['read'] = $transport->read(64, 1.0);
            $this->closePeer();
            $states['closed'] = Wait::until($transport->poll(...));
            $transport->close();
        });

        $this->assertSame(['idle' => false, 'data' => true, 'read' => "PING\r\n", 'closed' => true], $states);
    }

    #[RequiresPhpExtension('swoole')]
    #[RequiresPhpExtension('sockets')]
    #[RunInSeparateProcess]
    public function testHookedResetByThePeerIsReadable(): void
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $states = [];

        Coroutine\run(static function () use (&$states): void {
            $peer = new Peer();
            $transport = new TcpTransport();
            $transport->connect('127.0.0.1', $peer->port, 1.0);
            $peer->accept();
            $states['idle'] = $transport->poll();
            $peer->reset();
            $states['reset'] = $transport->poll();
            $transport->close();
        });

        $this->assertSame(['idle' => false, 'reset' => true], $states);
    }

    private function connect(): TcpTransport
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("Could not open a loopback listener: [{$errno}] {$errstr}");
        }
        $this->server = $server;

        [$host, $port] = explode(':', (string) stream_socket_get_name($server, false));
        $transport = new TcpTransport();
        $transport->connect($host, (int) $port, 1.0);

        $peer = stream_socket_accept($server, 1.0);
        $this->assertIsResource($peer);
        $this->peer = $peer;

        return $transport;
    }

    private function send(string $data): void
    {
        $this->assertIsResource($this->peer);
        fwrite($this->peer, $data);
    }

    private function closePeer(): void
    {
        $this->assertIsResource($this->peer);
        fclose($this->peer);
        $this->peer = null;
    }
}
