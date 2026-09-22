<?php

declare(strict_types=1);

namespace Utopia\Tests\Scopes;

/**
 * A pooled resource that counts its own upkeep, so a maintenance sweep is
 * observable. Named rather than anonymous because Pool's TResource is not
 * covariant: an anonymous class makes the pool's type unmatchable at the seam.
 */
final class TickingResource
{
    public int $ticks = 0;

    /**
     * @param  \Closure(): void|null  $onTick  Runs inside tick(), to fail one.
     */
    public function __construct(
        public readonly int $serial,
        private readonly ?\Closure $onTick = null,
    ) {}

    public function tick(): void
    {
        ++$this->ticks;

        if ($this->onTick instanceof \Closure) {
            ($this->onTick)();
        }
    }
}
