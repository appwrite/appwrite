<?php

declare(strict_types=1);

namespace Utopia\Queue;

interface Consumer
{
    /** Block up to $timeout seconds for the next message and claim it, or null on timeout. */
    public function receive(Queue $queue, int $timeout): ?Message;

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
