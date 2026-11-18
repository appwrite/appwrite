<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Telemetry\Counter;

/** Keeps every add, in order, for a test to read back. */
final class RecordingCounter extends Counter
{
    /** @var list<array{int|float, iterable}> */
    public array $added = [];

    public function add(float|int $amount, iterable $attributes = []): void
    {
        $this->added[] = [$amount, $attributes];
    }
}
