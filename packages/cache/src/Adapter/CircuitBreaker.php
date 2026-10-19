<?php

namespace Utopia\Cache\Adapter;

use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Reads fall back to a miss while the cache fails or the breaker is open. Writes that would change what
 * the cache holds (save, saveMany, purge, flush) throw Unavailable instead: a caller invalidating an entry
 * must learn that it is still there. Lease saves and touches only skip a fill or a refresh, so they fall
 * back to false like reads.
 */
class CircuitBreaker implements Adapter, Feature\Batchable, Feature\Leasable, Feature\Telemetry
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly UtopiaCircuitBreaker $breaker,
    ) {
    }

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
            open: fn (): mixed => $fallback,
            close: fn (): mixed => $this->adapter->{$method}(...$args),
        );
    }

    /**
     * Run a write through the breaker. A failure counts against the breaker and throws, as does an open breaker.
     * With $stored, a false result is a failure too: the adapter reports a save it could not make as false.
     *
     * @param  array<mixed>  $args
     *
     * @throws Unavailable
     */
    private function write(string $method, array $args, string $key, bool $stored): mixed
    {
        $attempt = new class () {
            public ?Throwable $failure = null;
        };

        return $this->breaker->call(
            open: function () use ($method, $key, $attempt): never {
                if ($attempt->failure instanceof Unavailable) {
                    throw $attempt->failure;
                }

                throw new Unavailable("Cache {$method} of '{$key}' failed: ".($attempt->failure?->getMessage() ?? 'the cache circuit is open'), previous: $attempt->failure);
            },
            close: function () use ($method, $args, $key, $stored, $attempt): mixed {
                try {
                    $result = $this->adapter->{$method}(...$args);
                } catch (Throwable $error) {
                    $attempt->failure = $error;

                    throw $error;
                }

                if ($stored && $result === false) {
                    $attempt->failure = new Unavailable("Cache {$method} of '{$key}' was not stored");

                    throw $attempt->failure;
                }

                return $result;
            },
        );
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args(), false);
    }

    /**
     * @throws Unavailable When the save does not reach the cache
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($key === '' || $key === '0' || empty($data)) {
            return false;
        }

        /** @var string|array<int|string, mixed> $result */
        $result = $this->write(__FUNCTION__, \func_get_args(), $key, stored: true);

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
     * @return array<string, mixed>|false false when the adapter keeps no fields
     *
     * @throws Unavailable When the save does not reach the cache
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        if (! $this->adapter instanceof Feature\Batchable || $data === []) {
            return false;
        }

        /** @var array<string, mixed> $result */
        $result = $this->write('saveMany', [$key, $data, $ttl], $key, stored: true);

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
            /** @var bool|string|array<int|string, mixed> $result */
            $result = $this->delegate('save', [$key, $data, $hash], false);

            return $result;
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

    /**
     * @throws Unavailable When the purge does not reach the cache
     */
    public function purge(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->write(__FUNCTION__, \func_get_args(), $key, stored: false);

        return $result;
    }

    /**
     * @throws Unavailable When the flush does not reach the cache
     */
    public function flush(): bool
    {
        /** @var bool $result */
        $result = $this->write(__FUNCTION__, [], '', stored: false);

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
