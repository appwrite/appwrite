<?php

namespace Utopia\Telemetry;

abstract class Counter
{
    /**
     * @deprecated Instruments are lazy by default; call Adapter::createCounter() instead. Removed in the next major.
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
        return $telemetry->createCounter($name, $unit, $description, $advisory);
    }

    /**
     * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     */
    abstract public function add(float|int $amount, iterable $attributes = []): void;
}
