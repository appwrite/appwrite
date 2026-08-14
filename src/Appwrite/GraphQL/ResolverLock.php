<?php

namespace Appwrite\GraphQL;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class ResolverLock
{
    public Channel $channel;
    public ?int $owner = null;
    public int $depth = 0;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    /**
     * Acquire the lock. Re-entering from the same coroutine only
     * increments depth to avoid self-deadlock.
     */
    public function acquire(): void
    {
        $cid = Coroutine::getCid();

        if ($this->owner === $cid) {
            $this->depth++;
            return;
        }

        $this->channel->push(true);
        $this->owner = $cid;
        $this->depth = 1;
    }

    /**
     * Release the lock.
     */
    public function release(): void
    {
        if ($this->owner !== Coroutine::getCid()) {
            return;
        }

        $this->depth--;

        if ($this->depth > 0) {
            return;
        }

        $this->owner = null;
        $this->channel->pop();
    }
}
