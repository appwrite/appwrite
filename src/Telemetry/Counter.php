<?php

namespace Utopia\Telemetry;

abstract class Counter
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
        return new class ($telemetry, $name, $unit, $description, $advisory) extends Counter {
            private ?Counter $inner = null;

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
            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->inner ??= $this->telemetry->createCounter(
                    $this->name,
                    $this->unit,
                    $this->description,
                    $this->advisory,
                );

                $this->inner->add($amount, $attributes);
            }
        };
    }

    /**
     * @param iterable<non-empty-string, array<mixed>|bool|float|int|string|null> $attributes
     */
    abstract public function add(float|int $amount, iterable $attributes = []): void;
}
