<?php

declare(strict_types=1);

namespace Utopia\Telemetry;

abstract class ObservableGauge
{
    /**
     * Register an observation callback that will be invoked during collection.
     *
     * The callback receives an observer callable that can be called with a value and optional attributes
     * to record an observation: $observer(float|int $value, iterable $attributes = [])
     *
     * Callbacks accumulate: adapters cache gauges by name, so several sources (e.g. one per connection
     * pool) share a single instrument, and each registered callback contributes its own series.
     *
     * @param callable(callable(float|int, iterable<non-empty-string, array<mixed>|bool|float|int|string|null>): void): void $callback
     */
    abstract public function observe(callable $callback): void;
}
