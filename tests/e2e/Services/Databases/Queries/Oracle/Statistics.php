<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Statistics
{
    /**
     * @param list<int> $values
     */
    public function __construct(private array $values)
    {
    }

    public function count(): int
    {
        return \count($this->values);
    }

    public function sum(): ?int
    {
        return $this->values === [] ? null : \array_sum($this->values);
    }

    public function mean(): ?float
    {
        return $this->values === [] ? null : \array_sum($this->values) / \count($this->values);
    }

    public function minimum(): ?int
    {
        return $this->values === [] ? null : \min($this->values);
    }

    public function maximum(): ?int
    {
        return $this->values === [] ? null : \max($this->values);
    }

    public function populationVariance(): ?float
    {
        $mean = $this->mean();

        return $mean === null ? null : $this->squaredDeviations($mean) / \count($this->values);
    }

    public function sampleVariance(): ?float
    {
        $mean = $this->mean();

        return $mean === null || \count($this->values) < 2
            ? null
            : $this->squaredDeviations($mean) / (\count($this->values) - 1);
    }

    public function populationDeviation(): ?float
    {
        $variance = $this->populationVariance();

        return $variance === null ? null : \sqrt($variance);
    }

    public function sampleDeviation(): ?float
    {
        $variance = $this->sampleVariance();

        return $variance === null ? null : \sqrt($variance);
    }

    public function bitwiseAnd(): ?int
    {
        return $this->fold(static fn (int $carry, int $value): int => $carry & $value);
    }

    public function bitwiseOr(): ?int
    {
        return $this->fold(static fn (int $carry, int $value): int => $carry | $value);
    }

    public function bitwiseXor(): ?int
    {
        return $this->fold(static fn (int $carry, int $value): int => $carry ^ $value);
    }

    private function squaredDeviations(float $mean): float
    {
        return \array_sum(\array_map(static fn (int $value): float => ($value - $mean) ** 2, $this->values));
    }

    /**
     * @param callable(int, int): int $operation
     */
    private function fold(callable $operation): ?int
    {
        if ($this->values === []) {
            return null;
        }

        $values = $this->values;
        $result = \array_shift($values);
        foreach ($values as $value) {
            $result = $operation($result, $value);
        }

        return $result;
    }
}
