<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Protocol\Parser;
use Utopia\NATS\Protocol\ServerOp;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * A frame body that fails halfway through leaves the buffer mid-frame, so the
 * stream is no longer parseable. Reporting that as a timeout is indistinguishable
 * from "nothing arrived" and leaves the connection permanently desynced -- and
 * the dropped frame is often the PubAck itself.
 */
final class ParserFrameIntegrityTest extends TestCase
{
    public function testCompleteFrameStillParses(): void
    {
        $fake = new FakeTransport();
        $parser = new Parser($fake);
        $fake->pushInbound("MSG foo 1 5\r\nhello\r\n");

        [$op, $data] = $parser->next(1.0);

        $this->assertSame(ServerOp::Msg, $op);
        $this->assertSame('hello', $data['payload']);
        $this->assertSame('foo', $data['subject']);
    }

    public function testMidFrameTimeoutIsNotReportedAsATimeout(): void
    {
        $fake = new FakeTransport();
        $parser = new Parser($fake);
        // Header announces five payload bytes that never arrive.
        $fake->pushInbound("MSG foo 1 5\r\n");

        try {
            $parser->next(0.01);
            $this->fail('Expected the mid-frame failure to raise');
        } catch (TimeoutException) {
            $this->fail('A mid-frame failure must not surface as a timeout: the caller would read it as "nothing arrived" and carry on against a desynced stream');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('desynced', $e->getMessage());
        }
    }

    public function testPoisonedParserRefusesEvenValidFollowingData(): void
    {
        $fake = new FakeTransport();
        $parser = new Parser($fake);
        $fake->pushInbound("MSG foo 1 5\r\n");

        try {
            $parser->next(0.01);
        } catch (ConnectionException) {
            // expected; the parser is now poisoned
        }

        // Whatever arrives next cannot be trusted to start on a frame boundary,
        // so the parser must keep refusing until the connection is rebuilt.
        $fake->pushInbound("PING\r\n");

        $this->expectException(ConnectionException::class);
        $parser->next(1.0);
    }

    public function testMidFrameFailureSurfacesTheOriginalCause(): void
    {
        $fake = new FakeTransport();
        $parser = new Parser($fake);
        $fake->pushInbound("HMSG foo 1 12 20\r\n");

        try {
            $parser->next(0.01);
            $this->fail('Expected the mid-frame failure to raise');
        } catch (ConnectionException $e) {
            $this->assertInstanceOf(TimeoutException::class, $e->getPrevious());
        }
    }
}
