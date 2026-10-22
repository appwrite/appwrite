<?php

namespace Appwrite\Presences;

use Swoole\Coroutine\Channel;

/**
 * Striped per-key coroutine mutex for realtime presence writes.
 *
 * Two presence messages on one socket run as separate coroutines in the same worker and would both
 * read the socket's in-memory hold as empty before either writes it back -- double-counting the
 * row's `connections`, which onClose then releases only once (the socket stays online after it
 * disconnects). Serializing presence writes per socket fixes it, but one lock object per connection
 * would cost a Channel for every live socket.
 *
 * Instead a fixed pool of locks is shared across all connections: a key (the connection id) hashes to
 * one stripe, so a socket's own messages always serialize against each other, memory stays
 * O(stripes) regardless of connection count, and two unrelated sockets only ever wait on each other
 * on the rare stripe collision (harmless -- one extra presence write waits a single DB round-trip).
 *
 * A single instance is shared per worker (see app/realtime.php), so it is safe to inject anywhere a
 * presence write happens, including Cloud, which runs the same realtime process.
 */
class Lock
{
    private const DEFAULT_STRIPES = 256;

    /** @var array<int, Channel> */
    private array $stripes = [];

    private int $size;

    public function __construct(int $size = self::DEFAULT_STRIPES)
    {
        $this->size = \max(1, $size);

        for ($i = 0; $i < $this->size; $i++) {
            $stripe = new Channel(1);
            $stripe->push(true);
            $this->stripes[$i] = $stripe;
        }
    }

    /**
     * Acquire the stripe for $key, blocking the coroutine until it is free. Always pair with
     * {@see release} for the same key, ideally through a try/finally.
     */
    public function acquire(int|string $key): void
    {
        $this->stripe($key)->pop();
    }

    public function release(int|string $key): void
    {
        $this->stripe($key)->push(true);
    }

    private function stripe(int|string $key): Channel
    {
        $hash = \is_int($key) ? $key : \crc32($key);

        return $this->stripes[\abs($hash % $this->size)];
    }
}
