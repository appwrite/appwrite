<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Batchable;
use Utopia\Cache\Feature\Leasable;

class Sharding implements Adapter, Batchable, Leasable
{
    /**
     * @var Adapter[]
     */
    protected array $adapters;

    protected int $count = 0;

    /**
     * Sharding Adapter.
     *
     * Allows to shard cache across multiple adapters in a consistent way.
     * Using sharding we can increase cache size and balance the read
     * and write load between multiple adapters.
     *
     * Each cache key will be hashed and the hash will be used to determine
     * which adapter to use for fetching or storing this key. Only one
     * adapter will always match a specific key unless a new adapter is
     * added to the pool.
     *
     * When adding a new adapter to the pool, cached will gradually
     * get re-distributed to fill the new adapter, this might cause a
     * significant drop in hit-rate for a short period of time.
     *
     * @param  Adapter[]  $adapters
     */
    public function __construct(array $adapters)
    {
        if ($adapters === []) {
            throw new \Exception('No adapters provided');
        }

        $this->count = \count($adapters);
        $this->adapters = $adapters;
    }

    /**
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->getAdapter($key)->load($key, $ttl, $hash);
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        return $this->getAdapter($key)->save($key, $data, $hash, $ttl);
    }

    /**
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array
    {
        $adapter = $this->getAdapter($key);

        return $adapter instanceof Batchable ? $adapter->loadMany($key, $fields, $ttl) : [];
    }

    /**
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        $adapter = $this->getAdapter($key);

        return $adapter instanceof Batchable ? $adapter->saveMany($key, $data, $ttl) : false;
    }

    public function getGeneration(string $key): string
    {
        $adapter = $this->getAdapter($key);

        return $adapter instanceof Leasable ? $adapter->getGeneration($key) : '0';
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        $adapter = $this->getAdapter($key);

        return $adapter instanceof Leasable
            ? $adapter->saveWithLease($key, $data, $hash, $generation)
            : $adapter->save($key, $data, $hash);
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        return $this->getAdapter($key)->touch($key, $hash);
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return $this->getAdapter($key)->list($key);
    }

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        return $this->getAdapter($key)->purge($key, $hash);
    }

    public function flush(): bool
    {
        $result = true;
        foreach ($this->adapters as $value) {
            $result = $value->flush() && $result;
        }

        return $result;
    }

    public function ping(): bool
    {
        return array_all($this->adapters, fn(\Utopia\Cache\Adapter $value): bool => $value->ping());
    }

    /**
     * Returning total number of keys of all adapters
     */
    public function getSize(): int
    {
        $size = 0;
        foreach ($this->adapters as $value) {
            $size += $value->getSize();
        }

        return $size;
    }

    public function getName(?string $key = null): string
    {
        if ($key === null) {
            return $this->adapters[0]->getName();
        }

        return $this->getAdapter($key)->getName();
    }

    protected function getAdapter(string $key): Adapter
    {
        $hash = crc32($key);
        $index = $hash % $this->count;

        return $this->adapters[$index];
    }
}
