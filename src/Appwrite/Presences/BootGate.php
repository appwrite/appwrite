<?php

namespace Appwrite\Presences;

use Swoole\Atomic;

/**
 * Whether this realtime process has cleared the presences left online by realtime processes
 * that are gone. Presence writes wait for it; everything else is served from boot.
 */
final class BootGate
{
    public function __construct(
        private readonly Atomic $opened,
        private readonly float $deadline,
    ) {
    }

    public function open(): void
    {
        $this->opened->set(1);
    }

    public function isOpen(): bool
    {
        // Read with get() only: Atomic::wait() would consume the 1 and close the gate again.
        return $this->opened->get() === 1;
    }

    /**
     * Waits until the gate opens or the boot deadline passes. True when it opened in time.
     */
    public function wait(): bool
    {
        while (!$this->isOpen() && \microtime(true) < $this->deadline) {
            // A hooked sleep inside a coroutine: only the waiting presence write pauses.
            \usleep(50_000);
        }

        return $this->isOpen();
    }
}
