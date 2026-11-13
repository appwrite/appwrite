<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\Telemetry\Counter;

/** Keeps each amount and its attributes. */
final class RecordingCounter extends Counter
{
    /** @var list<array{float|int, array<mixed>}> */
    public array $added = [];

    public function add(float|int $amount, iterable $attributes = []): void
    {
        $this->added[] = [$amount, \is_array($attributes) ? $attributes : \iterator_to_array($attributes)];
    }
}
