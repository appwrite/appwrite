<?php

namespace Utopia\Telemetry\Adapter;

use Utopia\Telemetry\Adapter;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

class None implements Adapter
{
    /**
     * @param array<string, mixed> $advisory
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return new class () extends Counter {
            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        return new class () extends Histogram {
            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        return new class () extends Gauge {
            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        return new class () extends UpDownCounter {
            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        return new class () extends ObservableGauge {
            public function observe(callable $callback): void
            {
            }
        };
    }

    public function collect(): bool
    {
        return true;
    }
}
