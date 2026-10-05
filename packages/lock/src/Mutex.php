<?php

declare(strict_types=1);

namespace Utopia\Lock;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Lock\Exception\Contention;

final class Mutex implements Lock
{
    private readonly Channel $channel;

    private bool $syncHeld = false;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    #[\Override]
    public function acquire(float $timeout = 0.0): bool
    {
        if (! $this->inCoroutine()) {
            if ($this->syncHeld) {
                return false;
            }
            $this->syncHeld = true;

            return true;
        }

        return (bool) $this->channel->push(true, $timeout);
    }

    #[\Override]
    public function tryAcquire(): bool
    {
        if (! $this->inCoroutine()) {
            if ($this->syncHeld) {
                return false;
            }
            $this->syncHeld = true;

            return true;
        }

        if ($this->channel->isFull()) {
            return false;
        }

        return (bool) $this->channel->push(true, 0.001);
    }

    #[\Override]
    public function release(): void
    {
        if (! $this->inCoroutine()) {
            $this->syncHeld = false;

            return;
        }

        if ($this->channel->isEmpty()) {
            return;
        }

        $this->channel->pop(0.001);
    }

    #[\Override]
    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new Contention('Failed to acquire mutex within timeout');
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    private function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && Coroutine::getCid() > 0;
    }
}
