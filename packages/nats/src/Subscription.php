<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class Subscription
{
    private readonly \SplQueue $pendingMessages;
    private bool $active = true;
    private ?int $maxMessages = null;
    private int $received = 0;
    private int $pendingBytes = 0;
    private bool $slowConsumerSignaled = false;

    /** @var Connection|null Back-reference for sync operations */
    private ?Connection $connection = null;

    public function __construct(
        public readonly string $sid,
        public readonly string $subject,
        public readonly ?string $queue = null,
        private readonly ?\Closure $callback = null,
        private readonly int $pendingMsgsLimit = 65536,
        private readonly int $pendingBytesLimit = 67_108_864,
        private readonly ?\Closure $onSlowConsumer = null,
    ) {
        $this->pendingMessages = new \SplQueue();
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    public function nextMessage(?float $timeout = null): ?Message
    {
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        while (true) {
            // Drain anything already queued before blocking on the socket.
            if (!$this->pendingMessages->isEmpty()) {
                $msg = $this->pendingMessages->dequeue();
                $this->pendingBytes -= \strlen((string) $msg->data);
                if ($this->pendingBytes < 0) {
                    $this->pendingBytes = 0;
                }
                $this->slowConsumerSignaled = false;
                return $msg;
            }

            if (!$this->active || !$this->connection instanceof \Utopia\NATS\Connection) {
                return null;
            }

            $remaining = $deadline !== null ? $deadline - microtime(true) : null;
            if ($remaining !== null && $remaining <= 0) {
                return null;
            }

            $this->connection->processMessage($remaining);
        }
    }

    public function unsubscribe(?int $afterMessages = null): void
    {
        if ($this->connection instanceof \Utopia\NATS\Connection) {
            $this->connection->unsubscribe($this, $afterMessages);
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deliver(Message $msg): void
    {
        if ($this->callback instanceof \Closure) {
            $this->received++;
            ($this->callback)($msg);
        } else {
            // Guard the pending queue against unbounded growth: a consumer that
            // never drains its messages signals slow-consumer and the message is
            // dropped rather than exhausting memory.
            $msgBytes = \strlen($msg->data);
            if ($this->pendingMessages->count() >= $this->pendingMsgsLimit
                || ($this->pendingBytes + $msgBytes) > $this->pendingBytesLimit) {
                $this->signalSlowConsumer();
                return;
            }

            $this->received++;
            $this->pendingBytes += $msgBytes;
            $this->pendingMessages->enqueue($msg);
        }

        if ($this->maxMessages !== null && $this->received >= $this->maxMessages) {
            $this->active = false;
        }
    }

    public function getPendingCount(): int
    {
        return $this->pendingMessages->count();
    }

    public function getPendingBytes(): int
    {
        return $this->pendingBytes;
    }

    private function signalSlowConsumer(): void
    {
        if ($this->slowConsumerSignaled) {
            return;
        }

        $this->slowConsumerSignaled = true;

        if ($this->onSlowConsumer instanceof \Closure) {
            ($this->onSlowConsumer)($this);
        }
    }

    public function setMaxMessages(int $max): void
    {
        $this->maxMessages = $max;
        if ($this->received >= $max) {
            $this->active = false;
        }
    }

    public function setInactive(): void
    {
        $this->active = false;
    }

    public function getReceived(): int
    {
        return $this->received;
    }

    public function hasCallback(): bool
    {
        return $this->callback instanceof \Closure;
    }
}
