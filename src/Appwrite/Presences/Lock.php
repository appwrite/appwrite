<?php

namespace Appwrite\Presences;

use Swoole\Coroutine\Channel;

/**
 * Striped coroutine mutex: a fixed pool of locks shared across keys, so the number of lock objects
 * stays constant no matter how many keys are locked. Keys that hash to the same stripe serialize.
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
