<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\Telemetry\Histogram;

/** Keeps each recorded value. */
final class RecordingHistogram extends Histogram
{
    /** @var list<float|int> */
    public array $recorded = [];

    public function record(float|int $amount, iterable $attributes = []): void
    {
        $this->recorded[] = $amount;
    }
}
