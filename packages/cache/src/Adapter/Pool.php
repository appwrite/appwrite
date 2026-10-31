<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Batchable;
use Utopia\Pools\Pool as UtopiaPool;

class Pool implements Adapter, Batchable, \Utopia\Cache\Feature\Versioned, \Utopia\Cache\Feature\Legacy
{
    private readonly bool $versioned;

    /**
     * @param  UtopiaPool<covariant Adapter>  $pool The pool to use for connections. Must contain instances of Adapter.
     *
     * @throws \Exception
     */
    public function __construct(protected UtopiaPool $pool)
    {
        $this->versioned = $this->pool->use(function (mixed $resource): bool {
            if (! ($resource instanceof Adapter)) {
                throw new \Exception('Pool must contain instances of ' . Adapter::class);
            }

            return $resource instanceof \Utopia\Cache\Feature\Versioned && $resource->supportsVersioning();
        });
    }

    /**
     * Forward method calls to the internal adapter instance via the pool.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param  array<mixed>  $args
     */
    public function delegate(string $method, array $args): mixed
    {
        return $this->pool->use(fn (Adapter $adapter) => $adapter->{$method}(...$args));
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        /**
         * @var bool|string|array<mixed> $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    /**
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->pool->use(fn (Adapter $adapter): array => $adapter instanceof Batchable ? $adapter->loadMany($key, $fields, $ttl) : []);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        /** @var array<string, mixed>|false $result */
        $result = $this->pool->use(fn (Adapter $adapter): array|false => $adapter instanceof Batchable ? $adapter->saveMany($key, $data, $ttl) : false);

        return $result;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function list(string $key): array
    {
        /**
         * @var array<string> $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function flush(): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function ping(): bool
    {
        /**
         * @var bool $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function getSize(): int
    {
        /**
         * @var int $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function getName(?string $key = null): string
    {
        /**
         * @var string $result
         */
        $result = $this->delegate(__FUNCTION__, \func_get_args());

        return $result;
    }

    public function getGeneration(string $key): string
    {
        /** @var string $result */
        $result = $this->pool->use(function (Adapter $adapter) use ($key): string {
            if (! $adapter instanceof \Utopia\Cache\Feature\Legacy) {
                throw new \Utopia\Cache\Exception\Unavailable('Legacy migration requires Redis');
            }

            return $adapter->getGeneration($key);
        });

        return $result;
    }

    /** @param array<int|string, mixed>|string $data */
    public function saveWithGeneration(string $key, array|string $data, string $hash, string $generation, int $ttl = 0): bool
    {
        /** @var bool $result */
        $result = $this->pool->use(function (Adapter $adapter) use ($key, $data, $hash, $generation, $ttl): bool {
            if (! $adapter instanceof \Utopia\Cache\Feature\Legacy) {
                throw new \Utopia\Cache\Exception\Unavailable('Legacy migration requires Redis');
            }

            return $adapter->saveWithGeneration($key, $data, $hash, $generation, $ttl);
        });

        return $result;
    }

    public function purgeLegacy(string $key, string $hash = ''): bool
    {
        /** @var bool $result */
        $result = $this->pool->use(function (Adapter $adapter) use ($key, $hash): bool {
            if (! $adapter instanceof \Utopia\Cache\Feature\Legacy) {
                throw new \Utopia\Cache\Exception\Unavailable('Legacy migration requires Redis');
            }

            return $adapter->purgeLegacy($key, $hash);
        });

        return $result;
    }

    public function supportsVersioning(): bool
    {
        return $this->versioned;
    }

    public function getVersion(string $key): string
    {
        return $this->pool->use(function (Adapter $adapter) use ($key): string {
            if (! $adapter instanceof \Utopia\Cache\Feature\Versioned) {
                throw new \Utopia\Cache\Exception\Unavailable("Versioned cache required");
            }

            return $adapter->getVersion($key);
        });
    }

    /** @return array{version: string, value: mixed} */
    public function loadVersioned(string $key): array
    {
        return $this->pool->use(function (Adapter $adapter) use ($key): array {
            if (! $adapter instanceof \Utopia\Cache\Feature\Versioned) {
                throw new \Utopia\Cache\Exception\Unavailable("Versioned cache required");
            }

            return $adapter->loadVersioned($key);
        });
    }

    /** @param array<int|string, mixed>|string $data */
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): string|false
    {
        return $this->pool->use(function (Adapter $adapter) use ($key, $data, $hash, $version): string|false {
            if (! $adapter instanceof \Utopia\Cache\Feature\Versioned) {
                throw new \Utopia\Cache\Exception\Unavailable("Versioned cache required");
            }

            return $adapter->compareAndSwap($key, $data, $hash, $version);
        });
    }
}
