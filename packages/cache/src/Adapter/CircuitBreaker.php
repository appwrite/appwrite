<?php

namespace Utopia\Cache\Adapter;

use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as UtopiaCircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

class CircuitBreaker implements Adapter, Feature\Batchable, Feature\Telemetry, \Utopia\Cache\Feature\Versioned, \Utopia\Cache\Feature\Legacy
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
     * A purge the breaker cannot confirm throws instead of falling back to false, which callers read as nothing to
     * purge: the entry would stay cached after the data behind it changed.
     *
     * @throws Unavailable when the circuit is open or the adapter fails the purge
     */
    public function purge(string $key, string $hash = ''): bool
    {
        $failure = null;

        /** @var bool $result */
        $result = $this->breaker->call(
            open: function () use ($key, &$failure): never {
                throw new Unavailable("Cache purge of '{$key}' could not be confirmed", previous: $failure);
            },
            close: function () use ($key, $hash, &$failure): bool {
                try {
                    return $this->adapter->purge($key, $hash);
                } catch (Throwable $error) {
                    $failure = $error;

                    throw $error;
                }
            },
        );

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

    public function getGeneration(string $key): string
    {
        /** @var string $result */
        $result = $this->breaker->call(
            open: fn (): never => throw new Unavailable('Legacy cache unavailable'),
            close: function () use ($key): string {
                if (! $this->adapter instanceof \Utopia\Cache\Feature\Legacy) {
                    throw new Unavailable('Legacy migration requires Redis');
                }

                return $this->adapter->getGeneration($key);
            },
        );

        return $result;
    }

    /** @param array<int|string, mixed>|string $data */
    public function saveWithGeneration(string $key, array|string $data, string $hash, string $generation): bool
    {
        /** @var bool $result */
        $result = $this->breaker->call(
            open: fn (): never => throw new Unavailable('Legacy cache unavailable'),
            close: function () use ($key, $data, $hash, $generation): bool {
                if (! $this->adapter instanceof \Utopia\Cache\Feature\Legacy) {
                    throw new Unavailable('Legacy migration requires Redis');
                }

                return $this->adapter->saveWithGeneration($key, $data, $hash, $generation);
            },
        );

        return $result;
    }

    public function purgeLegacy(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->breaker->call(
            open: fn (): never => throw new Unavailable('Legacy cache unavailable'),
            close: function () use ($key, $hash): bool {
                if (! $this->adapter instanceof \Utopia\Cache\Feature\Legacy) {
                    throw new Unavailable('Legacy migration requires Redis');
                }

                return $this->adapter->purgeLegacy($key, $hash);
            },
        );

        return $result;
    }

    public function supportsVersioning(): bool
    {
        return $this->adapter instanceof \Utopia\Cache\Feature\Versioned && $this->adapter->supportsVersioning();
    }

    // OPEN and transport failures must throw, never masquerade as an evicted record.
    public function getVersion(string $key): string
    {
        /** @var string $result */
        $result = $this->breaker->call(
            open: fn (): never => throw new Unavailable('Versioned cache unavailable'),
            close: fn (): string => $this->versionedAdapter()->getVersion($key),
        );

        return $result;
    }

    /** @return array{version: string, value: mixed} */
    public function loadVersioned(string $key): array
    {
        /** @var array{version: string, value: mixed} $result */
        $result = $this->breaker->call(
            open: fn (): never => throw new Unavailable('Versioned cache unavailable'),
            close: fn (): array => $this->versionedAdapter()->loadVersioned($key),
        );

        return $result;
    }

    /** @param array<int|string, mixed>|string $data */
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): string|false
    {
        /** @var string|false $result */
        $result = $this->breaker->call(
            open: fn (): never => throw new Unavailable('Versioned cache unavailable'),
            close: fn (): string|false => $this->versionedAdapter()->compareAndSwap($key, $data, $hash, $version),
        );

        return $result;
    }

    private function versionedAdapter(): \Utopia\Cache\Feature\Versioned
    {
        $adapter = $this->adapter;
        if (! $adapter instanceof \Utopia\Cache\Feature\Versioned) {
            throw new \Utopia\Cache\Exception\Unavailable('Versioned cache required');
        }

        return $adapter;
    }
}
