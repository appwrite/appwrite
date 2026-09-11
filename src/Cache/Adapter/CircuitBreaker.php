<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

class CircuitBreaker implements Adapter, Feature\Batchable, Feature\Leasable, Feature\Telemetry
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly UtopiaCircuitBreaker $breaker,
    ) {}

    /**
     * Forward method calls to the internal adapter through the circuit breaker.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param  array<mixed>  $args
     */
    public function delegate(string $method, array $args, mixed $fallback): mixed
    {
        return $this->breaker->call(
            open: fn(): mixed => $fallback,
            close: fn(): mixed => $this->adapter->{$method}(...$args),
        );
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args(), false);
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        /** @var bool|string|array<int|string, mixed> $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    /**
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array
    {
        if (! $this->adapter instanceof Feature\Batchable) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $this->delegate('loadMany', [$key, $fields, $ttl], []);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        if (! $this->adapter instanceof Feature\Batchable) {
            return false;
        }

        /** @var array<string, mixed>|false $result */
        $result = $this->delegate('saveMany', [$key, $data, $ttl], false);

        return $result;
    }

    public function getGeneration(string $key): string
    {
        if (! $this->adapter instanceof Feature\Leasable) {
            return '0';
        }

        /** @var string $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), '0');

        return $result;
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        if (! $this->adapter instanceof Feature\Leasable) {
            return $this->save($key, $data, $hash);
        }

        /** @var bool|string|array<int|string, mixed> $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function list(string $key): array
    {
        /** @var string[] $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), []);

        return $result;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function flush(): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function ping(): bool
    {
        /** @var bool $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), false);

        return $result;
    }

    public function getSize(): int
    {
        /** @var int $result */
        $result = $this->delegate(__FUNCTION__, \func_get_args(), 0);

        return $result;
    }

    public function getName(?string $key = null): string
    {
        try {
            return $this->adapter->getName($key);
        } catch (\Throwable) {
            return 'circuit-breaker';
        }
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->breaker->setTelemetry($telemetry);

        if ($this->adapter instanceof Feature\Telemetry) {
            $this->adapter->setTelemetry($telemetry);
        }
    }
}
