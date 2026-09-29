<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\AuthenticationException;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\PermissionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;
use Utopia\NATS\Transport\WebSocketTransport;

/**
 * A connection the server has already closed must not keep reporting itself as
 * connected: the next publish would write into a dead socket and return success,
 * losing the message while reporting a send. Covers the -ERR classification, the
 * caller-independent keepalive, and the transports' closed-resource guards.
 */
final class ConnectionDeathDetectionTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        stream_wrapper_register('utopia-shortwrite', ShortWriteStream::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        stream_wrapper_unregister('utopia-shortwrite');
        ShortWriteStream::$afterFirstWrite = null;
    }

    /** @param array<string, mixed> $extra */
    private function connect(FakeTransport $fake, array $extra = []): Connection
    {
        return Connection::connect(new ConnectionOptions(...array_merge([
            'servers' => 'nats://127.0.0.1:4222',
            'transportFactory' => fn(string $scheme): FakeTransport => $fake,
            // Keep reconnects instant and free of jitter so the assertions below
            // are about behaviour rather than timing.
            'reconnectWait' => 0.0,
            'reconnectJitter' => 0.0,
        ], $extra)));
    }

    // --- -ERR classification (pure) ---
    /**
     * @return \Iterator<int<0, max>, array{string, bool}>
     */
    public static function serverErrorProvider(): \Iterator
    {
        yield ['Stale Connection', true];
        yield ['stale connection', true];
        yield ['Slow Consumer', true];
        yield ['Maximum Payload Exceeded', true];
        yield ['Invalid Client Protocol', true];
        // Closing too, even though reconnecting after them is refused: whether
        // the socket is gone and whether a retry can help are separate
        // questions. See reconnectsAfterProvider().
        yield ['Authorization Violation', true];
        yield ['Authentication Expired', true];
        yield ['Secure Connection - TLS Required', true];
        yield ['Maximum Connections Exceeded', true];
        yield ['Unknown Protocol Operation', true];
        // Genuinely not connection-closing.
        yield ["Permissions Violation for Publish to 'foo'", false];
        yield ['Maximum Subscriptions Exceeded', false];
        yield ['Invalid Subject', false];
        yield ['', false];
    }

    #[DataProvider('serverErrorProvider')]
    public function testClosesConnectionClassifiesServerErrors(string $message, bool $expected): void
    {
        $this->assertSame($expected, Connection::closesConnection($message));
    }

    /**
     * @return \Iterator<int<0, max>, array{string, bool}>
     */
    public static function reconnectsAfterProvider(): \Iterator
    {
        // Transient or infrastructural: a fresh connection is a real fix.
        yield ['Stale Connection', true];
        yield ['Slow Consumer', true];
        yield ['Maximum Payload Exceeded', true];
        // Nothing a retry can change -- reconnecting would hot-loop against a
        // server that refuses us identically every time.
        yield ['Authorization Violation', false];
        yield ['Authentication Timeout', false];
        yield ['Authentication Expired', false];
        yield ['Secure Connection - TLS Required', false];
        yield ['Maximum Connections Exceeded', false];
        yield ['Unknown Protocol Operation', false];
    }

    #[DataProvider('reconnectsAfterProvider')]
    public function testReconnectsAfterSeparatesRecoverableErrors(string $message, bool $expected): void
    {
        $this->assertSame($expected, Connection::reconnectsAfter($message));
    }

    // --- Finding: reconnect on a connection-closing -ERR ---

    public function testConnectionClosingErrorTriggersReconnect(): void
    {
        $reconnected = false;
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'onReconnect' => function () use (&$reconnected): void {
                $reconnected = true;
            },
        ]);

        $fake->pushInbound("-ERR 'Stale Connection'\r\n");

        // The error still surfaces to the caller, but the connection underneath
        // has been rebuilt rather than left dead-but-"connected".
        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (ProtocolException) {
            // expected
        }

        $this->assertTrue($reconnected, 'A connection-closing -ERR must reconnect');
        $this->assertTrue($conn->isConnected());

        $conn->close();
    }

    public function testConnectionClosingErrorWithoutReconnectMarksConnectionDead(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, ['allowReconnect' => false]);

        $fake->pushInbound("-ERR 'Stale Connection'\r\n");

        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (ProtocolException) {
            // expected
        }

        // The whole point: status no longer claims a usable connection, so the
        // next publish raises instead of writing into a dead socket.
        $this->assertFalse($conn->isConnected());
        $this->expectException(ConnectionException::class);
        $conn->publish('foo', 'bar');
    }

    /**
     * The regression the split predicates exist for.
     *
     * An authorization failure closes the connection server-side but must not
     * be reconnected. When one predicate answered both questions, "do not
     * reconnect" also meant "do not record it as closed", so the status stayed
     * connected over a socket the server had already dropped -- and the next
     * publish() wrote into it and returned success. Exactly the silent loss the
     * -ERR handling was added to remove, on a different error.
     */
    public function testUnrecoverableClosingErrorMarksConnectionDeadWithoutReconnecting(): void
    {
        $reconnected = false;
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'onReconnect' => function () use (&$reconnected): void {
                $reconnected = true;
            },
        ]);

        $fake->pushInbound("-ERR 'Authorization Violation'\r\n");

        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (AuthenticationException) {
            // expected
        }

        $this->assertFalse($reconnected, 'Credentials the server just rejected must not be retried');
        $this->assertFalse($conn->isConnected(), 'The socket is gone; the status must say so');

        // The half that matters: the next write raises instead of vanishing.
        $this->expectException(ConnectionException::class);
        $conn->publish('foo', 'bar');
    }

    public function testNonClosingErrorLeavesConnectionIntact(): void
    {
        $reconnected = false;
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'onReconnect' => function () use (&$reconnected): void {
                $reconnected = true;
            },
        ]);

        $fake->pushInbound("-ERR 'Permissions Violation for Publish to \"foo\"'\r\n");

        try {
            $conn->processMessage(1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (PermissionException) {
            // expected
        }

        $this->assertFalse($reconnected, 'A permissions error must not recycle the connection');
        $this->assertTrue($conn->isConnected());

        $conn->close();
    }

    // --- Finding: keepalive independent of the caller ---

    public function testTickSendsKeepalivePingWhenDue(): void
    {
        $fake = new FakeTransport();
        // pingInterval 0 makes the keepalive due on every check.
        $conn = $this->connect($fake, ['pingInterval' => 0.0]);

        $before = $fake->written;
        $conn->tick();

        $this->assertStringContainsString(
            "PING\r\n",
            substr($fake->written, \strlen($before)),
            'tick() must drive the keepalive without a message being read',
        );

        $conn->close();
    }

    public function testTickRecyclesAConnectionThatStoppedAnsweringPings(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'pingInterval' => 0.0,
            'maxPingsOut' => 1,
        ]);

        // Counted off the wire rather than from a callback: a recycled
        // connection re-runs the handshake, so a fresh CONNECT is the effect
        // worth asserting on.
        $handshakes = fn(): int => substr_count($fake->written, 'CONNECT ');
        $this->assertSame(1, $handshakes());

        // The handshake needed its PONG; from here the server goes silent.
        $fake->answerPings = false;

        // First tick sends the PING and collects no answer; the second sees the
        // outstanding ping budget exhausted and recycles.
        $conn->tick();
        $this->assertSame(1, $handshakes(), 'One unanswered ping is still within budget');

        // The same silent server serves the reconnect, so it cannot complete
        // either -- which is the honest outcome. A connection that cannot be
        // rebuilt must raise rather than report itself healthy.
        try {
            $conn->tick();
            $this->fail('tick() must not return normally once the connection is dead');
        } catch (ConnectionException) {
            // expected
        }

        $this->assertGreaterThan(
            1,
            $handshakes(),
            'An unanswered keepalive must recycle the connection',
        );

        $conn->close();
    }

    /**
     * A publisher must not be able to out-ping itself.
     *
     * A PING is only half a keepalive: the read path is what clears the
     * outstanding count, and a caller that only writes never reads. Emitting
     * from the write path therefore accumulated one unanswered PING per
     * interval on every write-only connection until the budget was spent, and
     * then declared a perfectly healthy socket stale -- the keepalive causing
     * the outage it exists to prevent, on a connection the server was watching
     * publish the whole time.
     *
     * The server here answers nothing, standing in for a connection with no
     * reader. What is asserted is that publishing alone neither emits a
     * keepalive nor rebuilds the connection.
     */
    public function testPublishingDoesNotAccumulateUnansweredPings(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'pingInterval' => 0.0,
            'maxPingsOut' => 1,
        ]);

        $handshakes = fn(): int => substr_count($fake->written, 'CONNECT ');
        $this->assertSame(1, $handshakes());

        // Nothing will answer from here, exactly as on a connection whose
        // holder only ever writes to it.
        $fake->answerPings = false;
        $before = \strlen($fake->written);

        for ($i = 0; $i < 5; $i++) {
            $conn->publish('foo', 'bar');
        }

        $written = substr($fake->written, $before);

        $this->assertStringNotContainsString(
            "PING\r\n",
            $written,
            'The write path must take the keepalive verdict without emitting a PING nothing will collect',
        );
        $this->assertSame(
            1,
            $handshakes(),
            'A busy publisher must not reconnect: the server can see its traffic, so the socket is not idle',
        );
        $this->assertTrue($conn->isConnected());

        $conn->close();
    }


    public function testTickCollectsItsOwnPongsSoAHealthyConnectionSurvives(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'pingInterval' => 0.0,
            'maxPingsOut' => 2,
        ]);

        $handshakes = fn(): int => substr_count($fake->written, 'CONNECT ');

        // checkPings() only writes. Nothing else clears the outstanding count
        // on a connection whose holder never reads -- a pooled publisher -- so
        // without collecting its own PONGs the keepalive would march to
        // maxPingsOut and declare a live server stale. Far more ticks than the
        // budget, against a server answering every one of them.
        for ($i = 0; $i < 20; $i++) {
            $conn->tick();
        }

        $this->assertSame(
            1,
            $handshakes(),
            'A server answering every ping must never be recycled, however long the connection idles',
        );

        // And it is still usable rather than merely un-recycled.
        $conn->publish('subject', 'payload');
        $this->assertStringContainsString('PUB subject', $fake->written);

        $conn->close();
    }

    /**
     * The write path takes the keepalive verdict before writing -- and only the
     * verdict.
     *
     * This test used to assert that publish() emitted a PING ahead of the PUB,
     * which is what the keepalive looked like when it was one method. But a
     * PING is only half a keepalive: the read path clears the outstanding
     * count, and a caller that only writes never reads, so emitting here made
     * every write-only connection accumulate one unanswered PING per interval
     * until the budget was spent and a healthy socket was declared stale. A
     * connection that is actively publishing is not idle either -- the server
     * can see its traffic -- so there was nothing for that PING to keep alive.
     *
     * What has to survive is the ordering intent: a connection already known to
     * be dead must be recycled *before* the payload is written, so a publish
     * never lands on a socket we have already given up on. That is asserted
     * here, and {@see self::testPublishingDoesNotAccumulateUnansweredPings()}
     * pins the half that was removed.
     */
    public function testPublishTakesTheKeepaliveVerdictBeforeWriting(): void
    {
        $fake = new FakeTransport();
        $conn = $this->connect($fake, [
            'pingInterval' => 0.0,
            'maxPingsOut' => 1,
        ]);

        $handshakes = fn(): int => substr_count($fake->written, 'CONNECT ');

        // Spend the budget from the read side, which is where it is spent in
        // practice: a PING the now-silent server will never answer.
        $fake->answerPings = false;
        $conn->tick();
        $this->assertSame(1, $handshakes());

        $offset = \strlen($fake->written);

        try {
            $conn->publish('foo', 'bar');
            $this->fail('A publish onto a spent keepalive budget must not report success');
        } catch (ConnectionException) {
            // The reconnect is served by the same silent server, so it cannot
            // complete -- which is the honest outcome.
        }

        $this->assertGreaterThan(
            1,
            $handshakes(),
            'The write path must act on a connection whose keepalive budget is spent',
        );
        $this->assertStringNotContainsString(
            'PUB foo',
            substr($fake->written, $offset),
            'The payload must not reach a socket the keepalive had already condemned',
        );
    }

    // --- Finding: transports must not raise TypeError on a closed stream ---
    /**
     * @return \Iterator<int<0, max>, array{class-string}>
     */
    public static function streamTransportProvider(): \Iterator
    {
        yield [TcpTransport::class];
        yield [TlsTransport::class];
        yield [WebSocketTransport::class];
    }

    /**
     * A close() concurrent with a yielded fread/fwrite leaves a closed resource
     * behind. Every stream function raises TypeError on one, and TypeError is an
     * \Error -- so it bypasses the ConnectionException catches that drive
     * reconnection. The transports must report it as a connection failure.
     *
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testClosedStreamRaisesConnectionExceptionNotTypeError(string $class): void
    {
        $transport = $this->transportWithClosedStream($class);

        $this->expectException(ConnectionException::class);
        $transport->write("PING\r\n");
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testClosedStreamReadsRaiseConnectionExceptionNotTypeError(string $class): void
    {
        $transport = $this->transportWithClosedStream($class);

        $this->expectException(ConnectionException::class);
        $transport->read(64);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testClosedStreamIsNotReportedAsConnected(string $class): void
    {
        $transport = $this->transportWithClosedStream($class);

        // Previously feof() on the closed resource raised TypeError here.
        $this->assertFalse($transport->isConnected());
    }

    /**
     * The same defect, one call deeper: mid-loop rather than on entry.
     *
     * fwrite() performs short writes, so write() loops -- and under Swoole's
     * stream hooks each fwrite() is a yield point, which makes the gap between
     * two iterations a window a close() can land in. The loop captured its
     * handle once before the first pass, so the next iteration wrote to the
     * stale one and raised TypeError: an \Error, so it bypassed the
     * ConnectionException catches that drive reconnection, exactly as the
     * on-entry case did before it was fixed.
     *
     * A stream wrapper supplies the short write and the seam in one: PHP calls
     * stream_write() repeatedly inside a single fwrite(), so accepting one byte
     * and then refusing makes fwrite() report a genuine partial write, and the
     * callback fired on the way past swaps the property for the closed handle a
     * concurrent close() would leave behind.
     *
     * Both versions raise ConnectionException here, so the message is what
     * tells them apart -- and it is the real distinction, not an incidental
     * one. A loop that re-reads the property reports the connection gone; one
     * writing to the copy it captured gets as far as the socket and blames the
     * write. Before the fix there was no third outcome only because this test
     * cannot run PHP's stream functions against a genuinely closed resource
     * without them raising TypeError, which is the production symptom.
     *
     * @param class-string $class
     */
    #[DataProvider('streamTransportProvider')]
    public function testStreamClosedMidWriteRaisesConnectionExceptionNotTypeError(string $class): void
    {
        $transport = new $class();
        $property = new \ReflectionProperty($class, 'stream');

        $stream = fopen('utopia-shortwrite://mid-loop', 'r+');
        $this->assertIsResource($stream);
        $property->setValue($transport, $stream);

        ShortWriteStream::$afterFirstWrite = function () use ($transport, $property): void {
            $dead = fopen('php://temp', 'r+');
            fclose($dead);
            $property->setValue($transport, $dead);
        };

        try {
            $transport->write("PING\r\n");
            $this->fail('A write onto a stream closed mid-loop must not report success');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString(
                'Not connected',
                $e->getMessage(),
                'The loop must re-read the stream property, not write to the handle it captured',
            );
        } finally {
            ShortWriteStream::$afterFirstWrite = null;
        }
    }

    /**
     * Build a transport holding a stream that has been closed underneath it,
     * standing in for a close() that landed during a yielded socket call.
     *
     * @param class-string $class
     */
    private function transportWithClosedStream(string $class): object
    {
        $transport = new $class();

        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);

        $property = new \ReflectionProperty($class, 'stream');
        $property->setValue($transport, $stream);

        fclose($stream);

        return $transport;
    }
}

/**
 * A stream that accepts one byte per write, so a transport's short-write loop is
 * guaranteed more than one pass, and that runs a callback after the first of
 * them -- the seam a concurrent close() would land in.
 */
final class ShortWriteStream
{
    /** Fired once, after the first write, to stand in for the racing close(). */
    public static ?\Closure $afterFirstWrite = null;

    /** @var resource|null Set by PHP for stream context; unused here. */
    public $context;

    private int $writes = 0;

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->writes++;

        // PHP drives stream_write() in a loop inside one fwrite() call until
        // everything is accepted, so accepting a byte and then refusing is what
        // makes fwrite() itself return short -- which is the only thing the
        // transport's own loop responds to.
        if ($this->writes > 1) {
            return 0;
        }

        if (self::$afterFirstWrite instanceof \Closure) {
            $callback = self::$afterFirstWrite;
            self::$afterFirstWrite = null;
            $callback();
        }

        return min(1, \strlen($data));
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    // PHP passes three arguments; none of them matter to a stream that only has
    // to accept a timeout being set on it.
    public function stream_set_option(): bool
    {
        return true;
    }
}
