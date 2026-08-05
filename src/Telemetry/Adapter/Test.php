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
 *
 * Instruments appear in these arrays on first write, mirroring the OpenTelemetry adapter: an
 * instrument that never records is never exported, so it never shows up here either.
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
     * Observable gauges awaiting their first callback, kept so that repeated creation of the same
     * name returns one instrument and every source contributes its own callback.
     *
     * @var array<string, ObservableGauge>
     */
    private array $unobservedGauges = [];

    /**
     * @param array<string, mixed> $advisory
     */
    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        $register = function (Counter $counter) use ($name): void {
            $this->counters[$name] = $counter;
        };

        return new class ($register) extends Counter {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param \Closure(Counter): void $register
             */
            public function __construct(private \Closure $register) {}

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
                ($this->register)($this);
                $this->values[] = $amount;
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        $register = function (Histogram $histogram) use ($name): void {
            $this->histograms[$name] = $histogram;
        };

        return new class ($register) extends Histogram {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param \Closure(Histogram): void $register
             */
            public function __construct(private \Closure $register) {}

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
                ($this->register)($this);
                $this->values[] = $amount;
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        $register = function (Gauge $gauge) use ($name): void {
            $this->gauges[$name] = $gauge;
        };

        return new class ($register) extends Gauge {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param \Closure(Gauge): void $register
             */
            public function __construct(private \Closure $register) {}

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
                ($this->register)($this);
                $this->values[] = $amount;
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        $register = function (UpDownCounter $upDownCounter) use ($name): void {
            $this->upDownCounters[$name] = $upDownCounter;
        };

        return new class ($register) extends UpDownCounter {
            /**
             * @var array<int, float|int>
             */
            public array $values = [];

            /**
             * @param \Closure(UpDownCounter): void $register
             */
            public function __construct(private \Closure $register) {}

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function add(float|int $amount, iterable $attributes = []): void
            {
                ($this->register)($this);
                $this->values[] = $amount;
            }
        };
    }

    /**
     * @param array<string, mixed> $advisory
     */
    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        $register = function (ObservableGauge $gauge) use ($name): void {
            $this->observableGauges[$name] = $gauge;
        };

        return $this->observableGauges[$name]
            ?? $this->unobservedGauges[$name]
            ??= new class ($register) extends ObservableGauge {
                /** @var list<\Closure> */
                public array $callbacks = [];

                /**
                 * @param \Closure(ObservableGauge): void $register
                 */
                public function __construct(private \Closure $register) {}

                public function observe(callable $callback): void
                {
                    ($this->register)($this);
                    $this->callbacks[] = \Closure::fromCallable($callback);
                }
            };
    }

    public function collect(): bool
    {
        return true;
    }
}
