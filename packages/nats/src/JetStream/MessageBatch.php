<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;
use Utopia\NATS\Message;

final class MessageBatch implements \IteratorAggregate, \Countable
{
    /** @var list<JetStreamMessage> */
    private array $messages = [];

    public function __construct(
        private readonly Connection $conn,
    ) {}

    public function addMessage(Message $msg): void
    {
        $this->messages[] = new JetStreamMessage($this->conn, $msg);
    }

    /** @return list<JetStreamMessage> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->messages);
    }

    public function count(): int
    {
        return \count($this->messages);
    }
}
