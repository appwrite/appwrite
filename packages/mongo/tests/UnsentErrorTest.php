<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mongo\Exception;
use Utopia\Mongo\UnsentException;

/**
 * Connection validation and the dial before it run ahead of every send, so a
 * failure raised there leaves the command unsent and nothing applied - the one
 * class of failure a caller may safely replay. That distinction was previously
 * legible only in the message text, and a cloud pool consequently surfaced a
 * replayable refusal to clients as a 500 during a backing resize.
 *
 * It is carried by the type and nothing else. A code cannot carry it: a
 * post-send error response is parsed into an ordinary Exception holding the
 * SERVER's code, which includes codes that look pre-send.
 */
final class UnsentErrorTest extends TestCase
{
    public function testAnUnsentFailureIsDistinguishableByType(): void
    {
        $unsent = new UnsentException('Client is not connected to MongoDB');

        $this->assertTrue($unsent->isUnsentError());
        $this->assertInstanceOf(Exception::class, $unsent, 'Callers that catch the package exception must still catch it');
    }

    public function testAPostSendTimeoutIsNotReportedAsUnsent(): void
    {
        $timeout = new Exception('Receive timeout: no data received within reasonable time', 11601);

        $this->assertTrue($timeout->isTimeoutError());
        $this->assertFalse(
            $timeout->isUnsentError(),
            'The command was already on the wire, so its outcome is unknown and it must never be replayed blindly',
        );
    }

    /**
     * The reason a code cannot carry this: the server picks it. A response
     * that happens to report HostUnreachable is still a post-send answer, and
     * replaying the operation it answered could apply it twice.
     */
    public function testAServerErrorResponseIsNeverReportedAsUnsent(): void
    {
        $response = new \stdClass();
        $response->code = 6;
        $response->codeName = 'HostUnreachable';
        $response->errmsg = 'host unreachable';

        $this->assertFalse(Exception::fromResponse($response)->isUnsentError());
    }
}
