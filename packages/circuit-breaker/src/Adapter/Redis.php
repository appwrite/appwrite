<?php

namespace Utopia\CircuitBreaker\Adapter;

use Utopia\CircuitBreaker\Adapter as CircuitBreakerAdapter;

final readonly class Redis implements CircuitBreakerAdapter
{
    /**
     * @param object $redis A Redis-compatible client exposing get, set, incrBy, and del.
     */
    public function __construct(
        private object $redis,
        private string $prefix = 'breaker:',
    ) {
        foreach (['get', 'set', 'incrBy', 'del'] as $method) {
            if (!method_exists($this->redis, $method)) {
                throw new \InvalidArgumentException(\sprintf(
                    '%s requires a Redis-compatible client with a %s() method.',
                    self::class,
                    $method,
                ));
            }
        }
    }

    public function get(string $key): int|string|null
    {
        $value = $this->command('get', [$this->key($key)]);

        if ($value === false || $value === null) {
            return null;
        }

        if (\is_int($value) || \is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    public function set(string $key, int|string $value): void
    {
        $result = $this->command('set', [$this->key($key), (string) $value]);

        if (!$this->isSuccessfulSet($result)) {
            throw new AdapterException(\sprintf('Failed to set cache key "%s".', $key));
        }
    }

    public function increment(string $key, int $by = 1): int
    {
        $value = $this->command('incrBy', [$this->key($key), $by]);

        if ($value === false || $value === null) {
            throw new AdapterException(\sprintf('Failed to increment cache key "%s".', $key));
        }

        return (int) $value;
    }

    public function delete(string $key): void
    {
        $result = $this->command('del', [$this->key($key)]);

        if ($result === false) {
            throw new AdapterException(\sprintf('Failed to delete cache key "%s".', $key));
        }
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function command(string $method, array $arguments): mixed
    {
        try {
            return $this->redis->{$method}(...$arguments);
        } catch (\Throwable $exception) {
            throw new AdapterException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    private function isSuccessfulSet(mixed $result): bool
    {
        if (\is_bool($result)) {
            return $result;
        }

        if (\is_string($result)) {
            return strtoupper($result) === 'OK';
        }

        if (\is_object($result) && method_exists($result, 'getPayload')) {
            return $result->getPayload() === 'OK';
        }

        return $result !== null;
    }
}
