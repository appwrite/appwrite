<?php

namespace Appwrite\Presences;

use Utopia\Lock\Lock as UtopiaLock;
use Utopia\Lock\Mutex;

/**
 * A fixed pool of coroutine mutexes. get($key) returns the Lock for a key, so the number of lock
 * objects stays constant no matter how many keys are in play; keys that hash to the same stripe
 * serialise. Used to serialise a socket's presence writes without one lock object per connection.
 */
final class Lock
{
    private const DEFAULT_STRIPES = 256;

    /** @var array<int, UtopiaLock> */
    private array $stripes = [];

    private int $size;

    public function __construct(int $size = self::DEFAULT_STRIPES)
    {
        $this->size = \max(1, $size);

        for ($i = 0; $i < $this->size; $i++) {
            $this->stripes[$i] = new Mutex();
        }
    }

    public function get(int|string $key): UtopiaLock
    {
        $hash = \is_int($key) ? $key : \crc32($key);

        return $this->stripes[\abs($hash % $this->size)];
    }
}
