<?php

declare(strict_types=1);

namespace Utopia\Telemetry;

interface Adapter
{
    /**
     * @param array<string, mixed> $advisory
     */
    public function createCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): Counter;

    /**
     * @param array<string, mixed> $advisory
     */
    public function createHistogram(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): Histogram;

    /**
     * @param array<string, mixed> $advisory
     */
    public function createGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): Gauge;

    /**
     * @param array<string, mixed> $advisory
     */
    public function createUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): UpDownCounter;

    /**
     * @param array<string, mixed> $advisory
     */
    public function createObservableGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): ObservableGauge;

    public function collect(): bool;
}
