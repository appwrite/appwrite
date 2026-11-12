<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\Telemetry\UpDownCounter;

/** Ignores every change. */
final class SilentUpDownCounter extends UpDownCounter
{
    public function add(float|int $amount, iterable $attributes = []): void
    {
    }
}
