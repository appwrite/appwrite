<?php

namespace Utopia\Telemetry;

abstract class Gauge
{
    /**
     * @param array<string, mixed> $advisory
     */
    public static function lazy(
        Adapter $telemetry,
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): self {
        return new class ($telemetry, $name, $unit, $description, $advisory) extends Gauge {
            private ?Gauge $inner = null;

            /**
             * @param array<string, mixed> $advisory
             */
            public function __construct(
                private Adapter $telemetry,
                private string $name,
                private ?string $unit,
                private ?string $description,
                private array $advisory,
            ) {}

            /**
             * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
             */
            public function record(float|int $amount, iterable $attributes = []): void
            {
                $this->inner ??= $this->telemetry->createGauge(
                    $this->name,
                    $this->unit,
                    $this->description,
                    $this->advisory,
                );

                $this->inner->record($amount, $attributes);
            }
        };
    }

    /**
     * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     */
    abstract public function record(float|int $amount, iterable $attributes = []): void;
}
