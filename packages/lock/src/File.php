<?php

declare(strict_types=1);

namespace Utopia\Lock;

use RuntimeException;
use Utopia\Lock\Exception\Contention;

final class File implements Lock
{
    /**
     * @var resource|null
     */
    private $handle;

    public function __construct(
        private readonly string $path,
        private readonly int $mode = LOCK_EX,
    ) {}

    #[\Override]
    public function acquire(float $timeout = 0.0): bool
    {
        $this->open();

        if ($timeout <= 0.0) {
            return $this->tryAcquire();
        }

        $deadline = microtime(true) + $timeout;
        $delay = 0.01;

        do {
            if ($this->tryAcquire()) {
                return true;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return false;
            }

            usleep((int) (min($delay, $remaining) * 1_000_000));
            $delay = min($delay * 2.0, 0.25);
        } while (true);
    }

    #[\Override]
    public function tryAcquire(): bool
    {
        $this->open();

        \assert($this->handle !== null);

        $wouldBlock = 0;

        /** @var int<0, 7> $operation */
        $operation = $this->mode | LOCK_NB;

        return flock($this->handle, $operation, $wouldBlock);
    }

    #[\Override]
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    #[\Override]
    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new Contention("Failed to acquire file lock on {$this->path} within timeout");
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    private function open(): void
    {
        if ($this->handle !== null) {
            return;
        }

        $directory = \dirname($this->path);
        if (! is_dir($directory)) {
            throw new Exception("Lock file directory does not exist: {$directory}");
        }
        if (! is_writable($directory) && ! file_exists($this->path)) {
            throw new Exception("Lock file directory is not writable: {$directory}");
        }

        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Failed to open lock file: {$this->path}");
        }

        $this->handle = $handle;
    }
}
