<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\Telemetry\ObservableGauge;

/** Keeps the callback so a test can read the gauge. */
final class RecordingGauge extends ObservableGauge
{
    /** @var list<callable> */
    public array $callbacks = [];

    public function observe(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }
}
