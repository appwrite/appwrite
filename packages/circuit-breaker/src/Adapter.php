<?php

declare(strict_types=1);

namespace Utopia\CircuitBreaker;

interface Adapter
{
    public function get(string $key): int|string|null;

    public function set(string $key, int|string $value): void;

    public function increment(string $key, int $by = 1): int;

    public function delete(string $key): void;
}
