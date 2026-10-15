<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\Headers;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\StreamConfig;

/**
 * E2E tests for the batched JetStream publish. Requires a JetStream-enabled
 * server (NATS_URL).
 */
final class JetStreamPublishManyTest extends TestCase
{
    private const string STREAM = 'PUBLISH_MANY_TEST';

    private Connection $conn;
    private JetStream $js;

    protected function setUp(): void
    {
        $this->conn = Connection::connect(getenv('NATS_URL') ?: 'nats://127.0.0.1:4222');
        $this->js = $this->conn->jetStream();

        try {
            $this->js->deleteStream(self::STREAM);
        } catch (\Throwable) {
            // not there yet
        }

        $this->js->createStream(new StreamConfig(
            name: self::STREAM,
            subjects: ['publishmany.>'],
            duplicateWindow: 120,
        ));
    }

    protected function tearDown(): void
    {
        try {
            $this->js->deleteStream(self::STREAM);
        } catch (\Throwable) {
            // already gone
        }
        $this->conn->close();
    }

    public function testEmptyBatchPublishesNothing(): void
    {
        $this->assertSame([], $this->js->publishMany([]));
        $this->assertSame(0, $this->js->getStreamInfo(self::STREAM)->state->messages);
    }

    public function testRejectsAWindowBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->js->publishMany([['subject' => 'publishmany.a']], window: 0);
    }

    /**
     * The acknowledgments come back in whatever order the server stored the
     * messages, so this reads each message back out of the stream at the
     * sequence its acknowledgment reported. A batch that returned the right
     * count while mixing up which acknowledgment belonged to which message
     * fails here and passes on a count assertion alone.
     */
    public function testEachAcknowledgementBelongsToItsOwnMessage(): void
    {
        $messages = [];
        for ($i = 0; $i < 50; $i++) {
            $messages[] = ['subject' => 'publishmany.a', 'data' => "payload-{$i}"];
        }

        // A window smaller than the batch so the collection runs more than once.
        $acks = $this->js->publishMany($messages, window: 8);

        $this->assertCount(50, $acks);

        foreach ($acks as $index => $ack) {
            $this->assertSame(self::STREAM, $ack->stream);
            $this->assertSame(
                "payload-{$index}",
                $this->js->getMessage(self::STREAM, $ack->sequence)->data,
                "acknowledgment {$index} points at sequence {$ack->sequence}, which holds another message",
            );
        }
    }

    public function testRepublishedMessageIdsComeBackAsDuplicates(): void
    {
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = ['subject' => 'publishmany.a', 'data' => "payload-{$i}", 'msgId' => "id-{$i}"];
        }

        $first = $this->js->publishMany($messages);
        foreach ($first as $ack) {
            $this->assertFalse($ack->duplicate);
        }

        $second = $this->js->publishMany($messages);
        foreach ($second as $ack) {
            $this->assertTrue($ack->duplicate);
        }

        // The duplicates collapsed rather than double-delivering.
        $this->assertSame(10, $this->js->getStreamInfo(self::STREAM)->state->messages);
    }

    public function testHeadersReachTheStream(): void
    {
        $headers = new Headers();
        $headers->set('X-Trace', 'abc123');

        $acks = $this->js->publishMany([
            ['subject' => 'publishmany.a', 'data' => 'with-headers', 'headers' => $headers],
        ]);

        $stored = $this->js->getMessage(self::STREAM, $acks[0]->sequence);
        $this->assertSame('abc123', $stored->headers?->get('X-Trace'));
    }

    /**
     * A server-side rejection of one message has to reach the caller. The batch
     * writes every message before reading any acknowledgment, so the rejection
     * arrives while other messages are still in flight.
     */
    public function testARejectedMessageThrows(): void
    {
        $messages = [
            ['subject' => 'publishmany.a', 'data' => 'fine'],
            ['subject' => 'publishmany.a', 'data' => 'rejected', 'expectedStream' => 'NO_SUCH_STREAM'],
            ['subject' => 'publishmany.a', 'data' => 'also-fine'],
        ];

        $this->expectException(JetStreamException::class);
        $this->js->publishMany($messages);
    }

    public function testTheConnectionStaysUsableAfterARejection(): void
    {
        try {
            $this->js->publishMany([
                ['subject' => 'publishmany.a', 'data' => 'rejected', 'expectedStream' => 'NO_SUCH_STREAM'],
            ]);
            $this->fail('expected the rejection to throw');
        } catch (JetStreamException) {
            // the reply subscription is torn down on the way out
        }

        $acks = $this->js->publishMany([['subject' => 'publishmany.a', 'data' => 'after']]);
        $this->assertSame('after', $this->js->getMessage(self::STREAM, $acks[0]->sequence)->data);
    }
}
