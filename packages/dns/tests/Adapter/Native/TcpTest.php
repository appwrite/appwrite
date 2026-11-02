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
class RespondingTcp extends Tcp
{
    public function callRespond(Socket $client, string $payload): void
    {
        $this->respond($client, $payload);
    }

    public function isClosed(Socket $client): bool
    {
        return !\in_array($client, $this->clients, true);
    }

    /**
     * respond() expects accept() to have already registered the client in
     * $this->clients so close() has something to remove; tests call respond()
     * directly, so register the socket the same way accept() would.
     */
    public function register(Socket $client): void
    {
        $this->clients[spl_object_id($client)] = $client;
    }
}

/**
 * A peer that never drains its receive window: every write reports
 * EAGAIN/EWOULDBLOCK forever. Real kernel send buffers on a loopback
 * connection do not stay blocked under sustained backpressure on every
 * platform this suite runs on, so this simulates the stall deterministically
 * instead of relying on OS buffering.
 */
class StallingTcp extends RespondingTcp
{
    public int $writeAttempts = 0;

    protected function write(Socket $client, string $data): int|false
    {
        $this->writeAttempts++;

        return false;
    }

    protected function writeErrno(Socket $client): int
    {
        return SOCKET_EAGAIN;
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

        $tcp = new StallingTcp(writeTimeout: 1);
        $tcp->register($server);

        $started = microtime(true);
        $tcp->callRespond($server, str_repeat('x', Message::MAX_SIZE));
        $elapsed = microtime(true) - $started;

        // Bounded by the write timeout, not hanging forever: a generous margin
        // over it, never anywhere close to what an unbounded retry would take.
        $this->assertGreaterThanOrEqual(1.0, $elapsed, 'The retry loop must honor writeTimeout rather than giving up early.');
        $this->assertLessThan(3.0, $elapsed, 'A stalled write must be bounded by writeTimeout, not retried forever.');

        $this->assertGreaterThan(1, $tcp->writeAttempts, 'The loop must actually retry on EAGAIN before giving up.');
        $this->assertTrue($tcp->isClosed($server), 'respond() must close a connection it gives up on.');

        // respond() already closed $server via close() above.
        @socket_close($client);
    }

    public function testWithoutTheDeadlineAStalledPeerIsNeverDropped(): void
    {
        // Proves the regression the write timeout exists for: with no
        // deadline, the retry loop spins on a permanently stalled peer
        // forever. Bounded here to a handful of iterations instead of letting
        // it actually hang the test suite.
        $tcp = new class () extends StallingTcp {
            protected function write(Socket $client, string $data): int|false
            {
                $this->writeAttempts++;

                if ($this->writeAttempts > 50) {
                    throw new \RuntimeException('Simulated infinite retry: the loop never reached a deadline check.');
                }

                return false;
            }
        };

        [$server, $client] = $this->connectedPair();
        $tcp->register($server);

        // writeTimeout is irrelevant here: this double never consults it, by
        // design, to stand in for the pre-fix code that had no deadline at all.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated infinite retry');

        try {
            $tcp->callRespond($server, str_repeat('x', Message::MAX_SIZE));
        } finally {
            @socket_close($server);
            @socket_close($client);
        }
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

        if (!\is_string($address) || !\is_int($port)) {
            $this->fail('socket_getsockname() did not report an address and port.');
        }

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);
        $this->assertNotFalse(socket_connect($client, $address, $port));

        $server = socket_accept($listener);
        $this->assertInstanceOf(Socket::class, $server);

        socket_close($listener);

        return [$server, $client];
    }
}
