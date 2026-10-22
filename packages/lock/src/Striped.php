<?php

declare(strict_types=1);

namespace Utopia\Lock;

/**
 * A fixed pool of {@see Lock}s. Each key hashes to one stripe, so {@see get()} returns a `Lock` the
 * caller drives through the normal interface, while the number of underlying locks stays constant no
 * matter how many distinct keys are used — callers that would otherwise allocate a lock per key (per
 * socket, per id, ...) keep memory bounded. Keys that hash to the same stripe serialise against each
 * other.
 */
final class Striped
{
    private const DEFAULT_STRIPES = 256;

    /** @var array<int, Lock> */
    private array $stripes = [];

    private int $size;

    public function __construct(int $size = self::DEFAULT_STRIPES)
    {
        $this->size = \max(1, $size);

        for ($i = 0; $i < $this->size; $i++) {
            $this->stripes[$i] = new Mutex();
        }
    }

    /**
     * The lock for $key. The same key (and any key on the same stripe) always returns the same lock.
     */
    public function get(int|string $key): Lock
    {
        $hash = \is_int($key) ? $key : \crc32($key);

        return $this->stripes[\abs($hash % $this->size)];
    }
}
