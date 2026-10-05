<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;
use Utopia\NATS\Message;
use Utopia\NATS\Subscription;

/**
 * A push consumer subscription. The server pushes messages to the deliver
 * subject; this class subscribes to it and forwards data messages to the
 * user callback (wrapped as JetStreamMessage), transparently answering
 * flow-control requests and swallowing idle heartbeats.
 */
final class PushSubscription
{
    private readonly Subscription $sub;

    public function __construct(
        private readonly Connection $conn,
        private readonly ConsumerInfo $info,
        \Closure $callback,
    ) {
        $deliverSubject = $info->config->deliverSubject;
        if ($deliverSubject === null) {
            throw new \RuntimeException('Consumer has no deliver subject; not a push consumer');
        }

        $handler = function (Message $msg) use ($callback): void {
            if (self::handleControl($this->conn, $msg)) {
                return;
            }
            $callback(new JetStreamMessage($this->conn, $msg));
        };

        $this->sub = $this->conn->subscribe($deliverSubject, $handler, $info->config->deliverGroup);
    }

    /**
     * Handle a JetStream control (status 100) message. Returns true when the
     * message was a control frame and should not be surfaced as data.
     */
    public static function handleControl(Connection $conn, Message $msg): bool
    {
        if (!$msg->headers instanceof \Utopia\NATS\Headers) {
            return false;
        }
        if ($msg->headers->getStatus() !== '100') {
            return false;
        }

        // Flow control request: acknowledge by responding to the reply subject.
        if ($msg->replyTo !== null && $msg->replyTo !== '') {
            $conn->publish($msg->replyTo, '');
            return true;
        }

        // Idle heartbeat: may carry a stalled indicator to nudge flow control.
        $stalled = $msg->headers->get('Nats-Consumer-Stalled');
        if ($stalled !== null && $stalled !== '') {
            $conn->publish($stalled, '');
        }

        return true;
    }

    public function getSubscription(): Subscription
    {
        return $this->sub;
    }

    public function getConsumerInfo(): ConsumerInfo
    {
        return $this->info;
    }

    public function getConsumerName(): string
    {
        return $this->info->name;
    }

    public function unsubscribe(): void
    {
        $this->sub->unsubscribe();
    }
}
