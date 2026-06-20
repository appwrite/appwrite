<?php

namespace Utopia\Telemetry\Adapter;

use Utopia\Telemetry\Adapter;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

/**
 * Test adapter allows access to the underlying telemetry resources. Can be used in tests to verify metrics.
 */
class Test implements Adapter
{
    /**
     * @var array<string, Counter>
     */
    public array $counters = [];

    /**
     * @var array<string, Histogram>
     */
    public array $histograms = [];

    /**
     * @var array<string, Gauge>
     */
    public array $gauges = [];

    /**
     * @var array<string, UpDownCounter>
     */
    public array $upDownCounters = [];

    /**
     * @var array<string, ObservableGauge>
     */
    public array $observableGauges = [];

    /**
     * @param array<string, mixed> $advisory
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        $counter = new class extends Counter {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->values[] = $amount;
            }
        };
        $this->counters[$name] = $counter;
        return $counter;
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        $histogram = new class extends Histogram {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
                $this->values[] = $amount;
            }
        };
        $this->histograms[$name] = $histogram;
        return $histogram;
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        $gauge = new class extends Gauge {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
                $this->values[] = $amount;
            }
        };
        $this->gauges[$name] = $gauge;
        return $gauge;
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        $upDownCounter = new class extends UpDownCounter {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->values[] = $amount;
            }
        };
        $this->upDownCounters[$name] = $upDownCounter;
        return $upDownCounter;
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        // Cache by name to mirror the real OpenTelemetry adapter: many sources sharing a metric
        // name (e.g. one per pool) get the same instrument and each contributes its own callback.
        return $this->observableGauges[$name] ??= new class extends ObservableGauge {
            /** @var list<\Closure> */
            public array $callbacks = [];

            public function observe(callable $callback): void
            {
                $this->callbacks[] = \Closure::fromCallable($callback);
            }
        };
    }

    public function collect(): bool
    {
        return true;
    }
}
