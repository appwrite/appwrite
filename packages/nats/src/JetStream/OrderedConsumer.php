<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;

/**
 * An ordered push consumer: ephemeral, single-threaded, in-order delivery.
 *
 * It creates an ephemeral push consumer with flow control and idle heartbeats
 * enabled, then delivers messages one at a time via next(). Gaps in the
 * consumer delivery sequence (detected on delivered messages or reported by
 * idle heartbeats) trigger an automatic recreate of the consumer starting from
 * the message after the last one that was successfully delivered.
 */
final class OrderedConsumer
{
    private \Utopia\NATS\Subscription $sub;
    private ConsumerInfo $info;
    private int $expectedConsumerSeq;
    private int $lastStreamSeq = 0;

    public function __construct(
        private readonly Connection $conn,
        private readonly JetStream $js,
        private readonly string $stream,
        private readonly DeliverPolicy $deliverPolicy = DeliverPolicy::All,
        private readonly ?string $filterSubject = null,
        private readonly float $idleHeartbeat = 5.0,
    ) {
        $this->create(null);
    }

    /**
     * Return the next message in order, or null on timeout. Flow-control and
     * heartbeat frames are handled internally; on a detected gap the consumer
     * is recreated and iteration continues seamlessly.
     */
    public function next(?float $timeout = null): ?JetStreamMessage
    {
        $timeout ??= 5.0;
        $deadline = microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $msg = $this->sub->nextMessage($remaining);
            if (!$msg instanceof \Utopia\NATS\Message) {
                return null;
            }

            if (PushSubscription::handleControl($this->conn, $msg)) {
                // A heartbeat reports the last consumer sequence the server has
                // delivered; if it is ahead of us we missed messages.
                $lastConsumer = $msg->headers?->get('Nats-Last-Consumer');
                if ($lastConsumer !== null && (int) $lastConsumer > $this->expectedConsumerSeq - 1) {
                    $this->reset($this->lastStreamSeq + 1);
                }
                continue;
            }

            $jsMsg = new JetStreamMessage($this->conn, $msg);
            $meta = $jsMsg->metadata();

            if ($meta->consumerSequence !== $this->expectedConsumerSeq) {
                // Gap: recreate from the message after the last good one.
                $this->reset($this->lastStreamSeq + 1);
                continue;
            }

            $this->expectedConsumerSeq++;
            $this->lastStreamSeq = $meta->streamSequence;

            return $jsMsg;
        }
    }

    public function getConsumerName(): string
    {
        return $this->info->name;
    }

    public function stop(): void
    {
        $this->teardown();
    }

    private function reset(?int $startSeq): void
    {
        $this->teardown();
        $this->create($startSeq);
    }

    private function teardown(): void
    {
        $this->sub->unsubscribe();
        try {
            $this->js->deleteConsumer($this->stream, $this->info->name);
        } catch (\Throwable) {
            // Ephemeral consumer will expire on its own.
        }
    }

    private function create(?int $startSeq): void
    {
        // Subscribe before creating the consumer so no pushed message is missed.
        $deliverSubject = $this->conn->newInbox();
        $this->sub = $this->conn->subscribe($deliverSubject);

        $config = new ConsumerConfig(
            deliverPolicy: $startSeq !== null ? DeliverPolicy::ByStartSequence : $this->deliverPolicy,
            ackPolicy: AckPolicy::None,
            filterSubject: $this->filterSubject,
            replayPolicy: ReplayPolicy::Instant,
            inactiveThreshold: 30.0,
            optStartSeq: $startSeq,
            deliverSubject: $deliverSubject,
            flowControl: true,
            idleHeartbeat: $this->idleHeartbeat,
        );

        $this->info = $this->js->createConsumer($this->stream, $config)->info();
        $this->expectedConsumerSeq = 1;
    }
}
