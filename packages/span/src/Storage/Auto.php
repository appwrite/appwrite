<?php

namespace Utopia\Span\Storage;

use Utopia\Span\Span;

/**
 * Auto-detecting storage that chooses the right backend:
 * - Swoole Coroutine storage when running inside a coroutine
 * - Memory storage for plain PHP (FPM, CLI)
 */
class Auto implements Storage
{
    private readonly Memory $memory;
    private ?Coroutine $coroutine = null;

    public function __construct()
    {
        $this->memory = new Memory();

        if (\extension_loaded('swoole')) {
            $this->coroutine = new Coroutine();
        }
    }

    public function get(): ?Span
    {
        if ($this->coroutine instanceof \Utopia\Span\Storage\Coroutine && $this->isInCoroutine()) {
            return $this->coroutine->get();
        }

        return $this->memory->get();
    }

    public function set(?Span $span): void
    {
        if ($this->coroutine instanceof \Utopia\Span\Storage\Coroutine && $this->isInCoroutine()) {
            $this->coroutine->set($span);
            return;
        }

        $this->memory->set($span);
    }

    private function isInCoroutine(): bool
    {
        return \Swoole\Coroutine::getCid() !== -1;
    }
}
