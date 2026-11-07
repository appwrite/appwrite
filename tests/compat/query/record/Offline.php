<?php

namespace Tests\Compat\Query\Record;

/**
 * A database connection while recording integration tests: accepts every
 * call and returns nothing (an empty result, or itself to chain on).
 */
final class Offline implements \IteratorAggregate, \ArrayAccess, \Countable
{
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator([]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }

    public function count(): int
    {
        return 0;
    }

    /**
     * @param list<mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($name === 'fetch' || $name === 'fetchColumn' || $name === 'fetchObject') {
            return false;
        }
        if (\str_starts_with($name, 'fetch') || \str_starts_with($name, 'list') || \str_starts_with($name, 'get')) {
            return [];
        }

        return $this;
    }
}
