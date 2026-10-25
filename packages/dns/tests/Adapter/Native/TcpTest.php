<?php

declare(strict_types=1);

namespace Utopia\DNS\Tests\Adapter\Native;

use PHPUnit\Framework\TestCase;
use Socket;
use Utopia\DNS\Adapter\Native\Tcp;
use Utopia\DNS\Message;

/**
 * Exposes respond(), the way the real server calls it from inside the select
 * loop, without needing accept() to have populated the client's bookkeeping.
 */
final class RespondingTcp extends Tcp
{
    public function callRespond(Socket $client, string $payload): void
    {
        $this->respond($client, $payload);
    }
}

/**
 * respond() runs synchronously inside the single select loop that services
 * every other client and both transports: a peer that never drains its
 * receive window must not be allowed to retry the write forever, or it stalls
 * the whole server, not just its own connection.
 */
final class TcpTest extends TestCase
{
    public function testAPeerThatNeverReadsIsDroppedWithinTheWriteTimeout(): void
    {
        [$server, $client] = $this->connectedPair();

        // Shrink both ends' buffers so the kernel has little room for written
        // bytes once the client stops reading, then confirm — by writing until
        // a real EAGAIN/EWOULDBLOCK is observed, not by assuming a given
        // payload size is enough — that the connection is actually stalled
        // before respond() is ever called. Loopback buffering varies enough by
        // platform that a single write of any fixed size is not a reliable way
        // to reach that state.
        @socket_set_option($server, SOL_SOCKET, SO_SNDBUF, 1024);
        @socket_set_option($client, SOL_SOCKET, SO_RCVBUF, 1024);
        socket_set_nonblock($server);
        $this->fillSendBufferUntilBlocked($server);

        $tcp = new RespondingTcp(writeTimeout: 1);

        $started = microtime(true);
        $tcp->callRespond($server, str_repeat('x', Message::MAX_SIZE));
        $elapsed = microtime(true) - $started;

        // Bounded by the write timeout, not hanging forever: a generous margin
        // over it, never anywhere close to what an unbounded retry would take.
        $this->assertLessThan(3.0, $elapsed, 'A stalled write must be bounded by writeTimeout, not retried forever.');

        // The server's own socket handle must be the one respond() gave up on
        // and closed: writing through it now fails. The client's receive
        // buffer still holds the filler bytes written before the connection
        // stalled, so reading from that end first would observe that queued
        // data rather than the closure itself.
        $this->assertFalse(@socket_write($server, 'x'), 'respond() must close the connection once the write deadline is exceeded.');

        @socket_close($client);
    }

    /**
     * Writes to $server, which nothing ever reads, until socket_write() itself
     * reports EAGAIN/EWOULDBLOCK — proof the connection is genuinely stalled,
     * not an assumption based on buffer sizes a platform is free to round up.
     */
    private function fillSendBufferUntilBlocked(Socket $server): void
    {
        $chunk = str_repeat('x', 65536);

        for ($i = 0; $i < 64; $i++) {
            $written = @socket_write($server, $chunk);

            if ($written === false && \in_array(socket_last_error($server), [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true)) {
                socket_clear_error($server);
                return;
            }
        }

        $this->fail('Could not stall the connection by writing to it; the test setup needs revisiting for this platform.');
    }

    public function testAPeerThatReadsPromptlyIsNotDropped(): void
    {
        [$server, $client] = $this->connectedPair();
        socket_set_nonblock($server);
        socket_set_nonblock($client);

        $tcp = new RespondingTcp(writeTimeout: 5);
        $payload = 'answer';

        $tcp->callRespond($server, $payload);

        $received = '';
        $deadline = microtime(true) + 2;
        while (\strlen($received) < \strlen($payload) + 2 && microtime(true) < $deadline) {
            $chunk = @socket_read($client, 4096);
            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
            usleep(1000);
        }

        $this->assertSame(pack('n', \strlen($payload)) . $payload, $received);

        @socket_close($server);
        @socket_close($client);
    }

    /**
     * @return array{0: Socket, 1: Socket} [server-side accepted socket, client-side socket]
     */
    private function connectedPair(): array
    {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $listener);
        $this->assertNotFalse(socket_bind($listener, '127.0.0.1', 0));
        $this->assertNotFalse(socket_listen($listener));

        $address = '';
        $port = 0;
        socket_getsockname($listener, $address, $port);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);
        $this->assertNotFalse(socket_connect($client, $address, $port));

        $server = socket_accept($listener);
        $this->assertInstanceOf(Socket::class, $server);

        socket_close($listener);

        return [$server, $client];
    }
}
