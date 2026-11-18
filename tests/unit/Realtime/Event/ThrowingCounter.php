<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\Telemetry\Counter;

/** Fails every add, like a broken telemetry exporter. */
final class ThrowingCounter extends Counter
{
    public function add(float|int $amount, iterable $attributes = []): void
    {
        throw new \RuntimeException('telemetry unavailable');
    }
}
