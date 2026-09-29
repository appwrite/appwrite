<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;
use Utopia\NATS\Headers;
use Utopia\NATS\Message;

final class JetStreamMessage
{
    private ?MsgMetadata $metadata = null;

    public function __construct(
        private readonly Connection $conn,
        public readonly Message $message,
    ) {}

    public function ack(): void
    {
        $this->respond('');
    }

    /**
     * Acknowledge and wait for the server to confirm the ack was persisted.
     * Unlike ack(), this blocks until confirmation or throws on timeout.
     */
    public function ackSync(?float $timeout = null): void
    {
        if ($this->message->replyTo === null) {
            throw new \RuntimeException('Cannot acknowledge: message has no reply subject');
        }

        $this->conn->request($this->message->replyTo, '', $timeout ?? 5.0);
    }

    public function nak(?float $delay = null): void
    {
        if ($delay !== null) {
            $nanos = StreamConfig::secondsToNanos($delay);
            $this->respond("-NAK {\"delay\":{$nanos}}");
        } else {
            $this->respond('-NAK');
        }
    }

    public function inProgress(): void
    {
        $this->respond('+WPI');
    }

    public function term(?string $reason = null): void
    {
        if ($reason !== null) {
            $this->respond("+TERM {$reason}");
        } else {
            $this->respond('+TERM');
        }
    }

    public function metadata(): MsgMetadata
    {
        if (!$this->metadata instanceof \Utopia\NATS\JetStream\MsgMetadata) {
            if ($this->message->replyTo === null) {
                throw new \RuntimeException('Message has no reply subject for metadata parsing');
            }
            $this->metadata = MsgMetadata::fromReplySubject($this->message->replyTo);
        }

        return $this->metadata;
    }

    public function getData(): string
    {
        return $this->message->data;
    }

    public function getSubject(): string
    {
        return $this->message->subject;
    }

    public function getHeaders(): ?Headers
    {
        return $this->message->headers;
    }

    private function respond(string $data): void
    {
        if ($this->message->replyTo === null) {
            throw new \RuntimeException('Cannot acknowledge: message has no reply subject');
        }

        $this->conn->publish($this->message->replyTo, $data);
    }
}
