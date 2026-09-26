<?php

declare(strict_types=1);

namespace Utopia\Queue;

interface Consumer
{
    /**
     * Block up to $timeout seconds for the first message, then claim up to $n
     * messages without waiting for the batch to fill. Values below one use one.
     * Every returned message needs its own commit() or reject(). A failed claim
     * must leave removed messages recoverable, including after process death.
     *
     * @return list<Message> Empty on timeout.
     */
    public function receive(Queue $queue, int $timeout, int $n = 1): array;

    /** Acknowledge a processed message. */
    public function commit(Queue $queue, Message $message): void;

    /**
     * Mark a message as failed.
     *
     * The broker decides what a failure buys — another attempt, or the end of
     * the line — and this is where it decides it. A message the handler marked
     * terminal ({@see Message::terminal()}, set for it by a
     * {@see PermanentFailure}) must not be delivered again: park it wherever
     * exhausted messages go. A broker with no such notion can ignore the flag
     * and reject the way it always has.
     */
    public function reject(Queue $queue, Message $message): void;

    /** Close the consumer and free resources. */
    public function close(): void;
}
