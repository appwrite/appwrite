<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;

/**
 * E2E tests for the extended JetStream features: push consumers, ordered
 * consumers with flow control, ackSync, expanded metadata and stream message
 * operations. Requires a JetStream-enabled server (NATS_URL).
 */
final class JetStreamExtraTest extends TestCase
{
    private Connection $conn;
    private JetStream $js;
    /** @var list<string> */
    private array $streams = [];

    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    protected function setUp(): void
    {
        $this->conn = Connection::connect($this->getServerUrl());
        $this->js = $this->conn->jetStream();
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            try {
                $this->js->deleteStream($stream);
            } catch (\Throwable) {
                // already gone
            }
        }
        $this->conn->close();
    }

    private function createStream(string $subject): string
    {
        $name = 'JSX_' . uniqid();
        $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: [$subject],
            storage: StorageType::Memory,
        ));
        $this->streams[] = $name;
        return $name;
    }

    public function testPushConsumerDeliversAndAcks(): void
    {
        $id = uniqid();
        $subject = "push.{$id}";
        $stream = $this->createStream($subject);

        /** @var list<JetStreamMessage> $received */
        $received = [];
        $push = $this->js->pushSubscribe(
            $stream,
            new ConsumerConfig(ackPolicy: AckPolicy::Explicit),
            function (JetStreamMessage $msg) use (&$received): void {
                $received[] = $msg;
                $msg->ack();
            },
        );

        $this->js->publish($subject, 'hello-push');

        $deadline = microtime(true) + 3.0;
        while ($received === [] && microtime(true) < $deadline) {
            $this->conn->processMessage(0.5);
        }

        $this->assertCount(1, $received);
        $this->assertSame('hello-push', $received[0]->getData());
        $this->assertSame($subject, $received[0]->getSubject());

        // The ack (fire-and-forget) should clear the pending count shortly.
        $ackPending = 1;
        $deadline = microtime(true) + 3.0;
        while ($ackPending !== 0 && microtime(true) < $deadline) {
            $ackPending = $this->js->getConsumer($stream, $push->getConsumerName())->info()->numAckPending;
            if ($ackPending !== 0) {
                usleep(100_000);
            }
        }
        $this->assertSame(0, $ackPending);

        $push->unsubscribe();
    }

    public function testOrderedConsumerPreservesSequence(): void
    {
        $id = uniqid();
        $subject = "ordered.{$id}";
        $stream = $this->createStream($subject);

        $count = 25;
        for ($i = 1; $i <= $count; $i++) {
            $this->js->publish($subject, "msg-{$i}");
        }

        $ordered = $this->js->orderedConsumer($stream);

        $streamSeqs = [];
        $consumerSeqs = [];
        for ($i = 0; $i < $count; $i++) {
            $msg = $ordered->next(3.0);
            $this->assertInstanceOf(JetStreamMessage::class, $msg);
            $meta = $msg->metadata();
            $streamSeqs[] = $meta->streamSequence;
            $consumerSeqs[] = $meta->consumerSequence;
        }

        $this->assertSame(range(1, $count), $streamSeqs, 'stream sequences must be continuous and in order');
        $this->assertSame(range(1, $count), $consumerSeqs, 'consumer delivery sequences must be continuous');

        $ordered->stop();
    }

    public function testAckSyncConfirmsAndPreventsRedelivery(): void
    {
        $id = uniqid();
        $subject = "acksync.{$id}";
        $stream = $this->createStream($subject);

        $this->js->publish($subject, 'ack-me');

        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'c_' . $id,
            ackPolicy: AckPolicy::Explicit,
            ackWait: 1.0,
        ));

        $batch = $consumer->fetch(1, 2.0);
        $messages = $batch->getMessages();
        $this->assertCount(1, $messages);

        $start = microtime(true);
        $messages[0]->ackSync(2.0);
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(2.0, $elapsed, 'ackSync must return before its timeout');

        // Wait past ack_wait, then confirm the message is not redelivered.
        usleep(1_300_000);
        $again = $consumer->fetch(1, 1.0);
        $this->assertCount(0, $again->getMessages(), 'acked message must not be redelivered');
    }

    public function testAckBatchConfirmsOnlySelectedMessages(): void
    {
        $id = uniqid();
        $subject = "ackbatch.{$id}";
        $stream = $this->createStream($subject);
        foreach (['first', 'unfinished', 'third'] as $data) {
            $this->js->publish($subject, $data);
        }
        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'c_' . $id,
            ackPolicy: AckPolicy::Explicit,
            ackWait: 0.2,
        ));
        $messages = $consumer->fetch(3, 2.0)->getMessages();
        $this->assertCount(3, $messages);
        $confirmed = [];
        $this->js->ackBatch([$messages[0], $messages[2]], static function (int $index, ?\Throwable $error) use (&$confirmed): void {
            $confirmed[$index] = $error;
        });
        ksort($confirmed);
        $this->assertSame([null, null], $confirmed);
        usleep(300_000);
        $remaining = $consumer->fetch(3, 0.5, noWait: true)->getMessages();
        $this->assertCount(1, $remaining);
        $this->assertSame('unfinished', $remaining[0]->getData());
        $remaining[0]->ackSync();
    }

    public function testMetadataStreamOpsAndNumPending(): void
    {
        $id = uniqid();
        $subject = "meta.{$id}";
        $stream = $this->createStream($subject);

        $seqs = [];
        for ($i = 1; $i <= 3; $i++) {
            $ack = $this->js->publish($subject, "payload-{$i}");
            $seqs[] = $ack->sequence;
        }
        $this->assertSame([1, 2, 3], $seqs, 'PubAck sequence numbers must be populated');

        // getMessage by sequence returns the exact payload/subject.
        $msg = $this->js->getMessage($stream, 2);
        $this->assertSame('payload-2', $msg->data);
        $this->assertSame($subject, $msg->subject);
        $this->assertSame(2, $msg->sequence);

        // Create a consumer and consume 2 of 3 without acking.
        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            durableName: 'm_' . $id,
            ackPolicy: AckPolicy::Explicit,
            ackWait: 30.0,
        ));

        $before = $consumer->info(true);
        $this->assertSame(3, $before->numPending, 'all messages pending before consumption');

        $batch = $consumer->fetch(2, 2.0);
        $this->assertCount(2, $batch->getMessages());

        $after = $consumer->info(true);
        $this->assertSame(2, $after->numAckPending, 'delivered-but-unacked count');
        $this->assertSame(1, $after->numPending, 'one message still awaiting delivery');
        $this->assertSame(2, $after->delivered->consumerSeq, 'expanded delivered metadata populated');

        // no_wait fetch returns immediately with the remaining message.
        $remaining = $consumer->fetch(10, 2.0, noWait: true);
        $this->assertCount(1, $remaining->getMessages());

        // deleteMessage removes it from the stream.
        $this->js->deleteMessage($stream, 1);
        $this->expectException(JetStreamException::class);
        $this->js->getMessage($stream, 1);
    }
}
