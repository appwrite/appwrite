<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Client;
use Utopia\SMTP\ConnectionException;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\ProtocolException;
use Utopia\SMTP\Tests\Unit\Support\FakeTransport;

/**
 * The reply reader is the one part of the client fed by something it does not
 * control, so what it does with input a healthy server never sends is worth
 * pinning down: bounded, and never a hang.
 */
final class ReplyReadingTest extends TestCase
{
    /**
     * @param  list<string>  $replies
     */
    private function read(array $replies, int $chunk = PHP_INT_MAX): void
    {
        $client = new Client(new FakeTransport($replies, chunk: $chunk), encryption: Encryption::None);

        $client->sendRaw(new Envelope('jane@example.test', ['john@example.test']), 'Body');
    }

    public function testAcceptsAReplyArrivingInPieces(): void
    {
        // A line split across reads is ordinary, not an error.
        $this->read([
            '220 mail.example.test',
            '250 mail.example.test',
            '250 Sender ok',
            '250 Recipient ok',
            '354 Go ahead',
            '250 Ok',
        ], chunk: 7);

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsALineWithoutACode(): void
    {
        $this->expectException(ProtocolException::class);

        $this->read(['hello there']);
    }

    public function testRejectsACodeThatIsNotThreeDigits(): void
    {
        $this->expectException(ProtocolException::class);

        $this->read(['22 too short']);
    }

    public function testRejectsAReplyClassThatDoesNotExist(): void
    {
        $this->expectException(ProtocolException::class);

        $this->read(['620 not a class RFC 5321 defines']);
    }

    public function testRejectsACodeThatChangesPartWayThrough(): void
    {
        $this->expectException(ProtocolException::class);

        $this->read(['220-mail.example.test', '250 different code']);
    }

    public function testRejectsALineTooLongToBeAReply(): void
    {
        // Delivered in pieces, so the buffer grows without ever seeing a line
        // ending. A server doing this to an unbounded reader is a memory leak.
        $this->expectException(ProtocolException::class);

        $this->read(['220 ' . str_repeat('a', 6000)], chunk: 64);
    }

    public function testRejectsAReplyWithTooManyLines(): void
    {
        $lines = [];

        for ($index = 0; $index < 200; ++$index) {
            $lines[] = '220-continuation';
        }

        $lines[] = '220 done';

        $this->expectException(ProtocolException::class);

        $this->read($lines);
    }

    public function testFailsWhenTheServerHangsUpPartWayThroughAReply(): void
    {
        $this->expectException(ConnectionException::class);

        $this->read(['220-mail.example.test']);
    }

    public function testFailsWhenTheServerSaysNothingAtAll(): void
    {
        $this->expectException(ConnectionException::class);

        $this->read([]);
    }

    public function testKeepsTheTextOfEveryContinuationLine(): void
    {
        $transport = new FakeTransport([
            '220 mail.example.test',
            '250-mail.example.test',
            '250-PIPELINING',
            '250 SIZE 100',
        ]);
        $client = new Client($transport, encryption: Encryption::None);

        $this->assertTrue($client->capabilities()->has('PIPELINING'));
        $this->assertSame(100, $client->capabilities()->maxSize());
    }
}
