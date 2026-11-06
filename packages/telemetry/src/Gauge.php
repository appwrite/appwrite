<?php

namespace Utopia\Telemetry;

abstract class Gauge
{
    /**
     * @deprecated Instruments are lazy by default; call Adapter::createGauge() instead. Removed in the next major.
     *
     * @param array<string, mixed> $advisory
     */
    public static function lazy(
        Adapter $telemetry,
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): self {
        return $telemetry->createGauge($name, $unit, $description, $advisory);
    }

    /**
     * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     */
    abstract public function record(float|int $amount, iterable $attributes = []): void;
}
