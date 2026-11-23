<?php

declare(strict_types=1);

namespace Utopia\Lock;

use InvalidArgumentException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Lock\Exception\Contention;

final class Semaphore implements Lock
{
    private readonly Channel $channel;

    private int $syncHeld = 0;

    public function __construct(private readonly int $permits)
    {
        if ($permits < 1) {
            throw new InvalidArgumentException('Permits must be at least 1');
        }

        $this->channel = new Channel($permits);
    }

    #[\Override]
    public function acquire(float $timeout = 0.0): bool
    {
        if (! $this->inCoroutine()) {
            if ($this->syncHeld >= $this->permits) {
                return false;
            }
            $this->syncHeld++;

            return true;
        }

        return (bool) $this->channel->push(true, $timeout);
    }

    #[\Override]
    public function tryAcquire(): bool
    {
        if (! $this->inCoroutine()) {
            if ($this->syncHeld >= $this->permits) {
                return false;
            }
            $this->syncHeld++;

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
            if ($this->syncHeld > 0) {
                $this->syncHeld--;
            }

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
            throw new Contention('Failed to acquire semaphore within timeout');
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
