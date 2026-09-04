<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

/**
 * A publisher that accepts messages without waiting for the broker: enqueue()
 * hands the message off for background delivery and returns immediately. It does
 * not report delivery — only that the message was accepted. When the buffer is
 * full and can't accept more, it throws BufferFullException so the caller can
 * shed or slow down. Implementations decide how the deferred work runs —
 * Broker\Background drains a Swoole channel on reader coroutines.
 */
interface Asynchronous
{
    /**
     * Hands a message off to be published in the background, returning without
     * waiting for the broker to accept it.
     *
     * @throws BufferFullException when the buffer is full and the message
     *                               cannot be accepted.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): void;
}
