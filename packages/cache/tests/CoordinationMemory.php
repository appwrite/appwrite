<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Throwable;
use Utopia\Cache\Adapter\Memory;

/** Real in-process cache with a controllable transport failure. */
final class CoordinationMemory extends Memory
{
    public int $calls = 0;

    public ?Throwable $failure = null;

    private function check(): void
    {
        $this->calls++;
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    #[\Override]
    public function getVersion(string $key): string
    {
        $this->check();
        return parent::getVersion($key);
    }

    #[\Override]
    public function loadVersioned(string $key): array
    {
        $this->check();
        return parent::loadVersioned($key);
    }

    #[\Override]
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): string|false
    {
        $this->check();
        return parent::compareAndSwap($key, $data, $hash, $version);
    }
}
