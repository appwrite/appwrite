<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\OrderedConsumer;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;

/**
 * E2E test for the ordered consumer gap detection and auto-recreate (reset)
 * path. On a healthy single server real consumer-sequence gaps never occur, so
 * the reset branch in OrderedConsumer::next() is otherwise unexercised.
 *
 * We force it deterministically: after consuming part of the stream we perturb
 * the private $expectedConsumerSeq (simulating a delivery the consumer believes
 * it already saw). The next observed message then mismatches, which must
 * trigger reset($lastStreamSeq + 1) -> teardown() + create() and recreate the
 * ephemeral consumer from the message after the last good one. Iteration must
 * recover and still yield every message exactly once, in stream order, with no
 * loss and no duplicate handed to the caller past the reset.
 *
 * If the reset logic is broken (e.g. it recreated from the wrong stream
 * sequence, or did not recreate at all) this test fails: the collected stream
 * sequences would show a gap or a duplicate.
 *
 * Requires a JetStream-enabled server (NATS_URL).
 */
final class OrderedConsumerTest extends TestCase
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
        $name = 'OC_' . uniqid();
        $this->js->createStream(new StreamConfig(
            name: $name,
            subjects: [$subject],
            storage: StorageType::Memory,
        ));
        $this->streams[] = $name;
        return $name;
    }

    private function setExpectedConsumerSeq(OrderedConsumer $ordered, int $value): void
    {
        $ref = new \ReflectionProperty(OrderedConsumer::class, 'expectedConsumerSeq');
        $ref->setValue($ordered, $value);
    }

    private function getExpectedConsumerSeq(OrderedConsumer $ordered): int
    {
        $ref = new \ReflectionProperty(OrderedConsumer::class, 'expectedConsumerSeq');
        return (int) $ref->getValue($ordered);
    }

    public function testResetRecreatesAndRecoversInOrder(): void
    {
        $id = uniqid();
        $subject = "orderedreset.{$id}";
        $stream = $this->createStream($subject);

        $count = 20;
        for ($i = 1; $i <= $count; $i++) {
            $this->js->publish($subject, "msg-{$i}");
        }

        $ordered = $this->js->orderedConsumer($stream);

        // Consume the first half normally. These stream sequences are 1..half.
        $half = 10;
        $streamSeqs = [];
        for ($i = 0; $i < $half; $i++) {
            $msg = $ordered->next(3.0);
            $this->assertInstanceOf(JetStreamMessage::class, $msg);
            $streamSeqs[] = $msg->metadata()->streamSequence;
        }
        $this->assertSame(range(1, $half), $streamSeqs, 'first half must arrive in order before the reset');

        $consumerBefore = $ordered->getConsumerName();

        // Simulate a missed delivery: advance the expected consumer sequence by
        // one so the very next pushed message (whose consumer sequence is what
        // we would otherwise expect) reads as a gap and forces a reset.
        $expected = $this->getExpectedConsumerSeq($ordered);
        $this->setExpectedConsumerSeq($ordered, $expected + 1);

        // Drain the rest. The first next() here observes the gap, triggers
        // reset($lastStreamSeq + 1) and recreates the consumer from the message
        // after the last good one (stream seq $half + 1), then returns it.
        for ($i = 0; $i < $count - $half; $i++) {
            $msg = $ordered->next(3.0);
            $this->assertInstanceOf(JetStreamMessage::class, $msg);
            $streamSeqs[] = $msg->metadata()->streamSequence;
        }

        $consumerAfter = $ordered->getConsumerName();

        // The reset must have torn down and recreated the ephemeral consumer.
        $this->assertNotSame($consumerBefore, $consumerAfter, 'reset must recreate the ephemeral consumer');

        // Every message delivered to the caller exactly once, in stream order,
        // with no loss and no duplicate across the reset boundary.
        $this->assertSame(range(1, $count), $streamSeqs, 'all messages must be delivered once, in order, across the reset');

        // No further messages remain.
        $this->assertNotInstanceOf(\Utopia\NATS\JetStream\JetStreamMessage::class, $ordered->next(0.5), 'no extra or duplicate messages after recovery');

        $ordered->stop();
    }
}
