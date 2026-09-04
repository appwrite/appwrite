<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

/**
 * Thrown by Asynchronous::enqueue() when the message can't be accepted because
 * the buffer is full and back pressure timed out. The message was not enqueued.
 */
class BufferFullException extends \RuntimeException {}
