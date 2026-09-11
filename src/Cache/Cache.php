<?php

namespace Utopia\Cache;

use Utopia\Cache\Feature\Batchable;
use Utopia\Cache\Feature\Leasable;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;

class Cache
{
    /**
     * @var bool If cache keys are case-sensitive
     */
    public bool $caseSensitive = false;

    protected Telemetry $telemetry;

    protected ?Histogram $operationDuration = null;

    protected ?Counter $loadResults = null;

    /**
     * Set telemetry adapter. Instruments are created lazily on first use to
     * avoid emitting empty data point streams on every export interval.
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
        $this->operationDuration = null;
        $this->loadResults = null;

        if ($this->adapter instanceof Feature\Telemetry) {
            $this->adapter->setTelemetry($telemetry);
        }
    }

    private function getOperationDuration(): Histogram
    {
        return $this->operationDuration ??= $this->telemetry->createHistogram(
            'cache.operation.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]],
        );
    }

    private function getLoadResults(): Counter
    {
        return $this->loadResults ??= $this->telemetry->createCounter(
            'cache.load.total',
            null,
            'Cache load operations broken down by hit/miss result.',
        );
    }

    /**
     * Initialize with a no-op telemetry adapter by default.
     */
    public function __construct(private readonly Adapter $adapter)
    {
        $this->telemetry = new NoTelemetry();
    }

    /**
     * Toggle case sensitivity of keys inside cache
     *
     * @param  bool  $value if true, cache keys will be case-sensitive
     */
    public function setCaseSensitivity(bool $value): bool
    {
        return $this->caseSensitive = $value;
    }

    /**
     * Load cached data. return false in no valid cache.
     *
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->load($key, $ttl, $hash);
        $duration = microtime(true) - $start;
        $adapterName = $this->adapter->getName($key);
        $this->getOperationDuration()->record($duration, [
            'operation' => 'load',
            'adapter' => $adapterName,
        ]);
        $this->getLoadResults()->add(1, [
            'adapter' => $adapterName,
            'result' => $result === false ? 'miss' : 'hit',
        ]);

        return $result;
    }

    /**
     * Save data to cache. Returns data on success of false on failure.
     *
     * When $ttl > 0 the key is also given a key-level expiry (adapters that
     * support it arm it; others keep their timestamp TTL and ignore it). $ttl = 0
     * preserves the prior behaviour and leaves any expiry untouched.
     *
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, mixed $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);
        $start = microtime(true);

        try {
            return $this->adapter->save($key, $data, $hash, $ttl);
        } finally {
            $duration = microtime(true) - $start;
            $this->getOperationDuration()->record($duration, [
                'operation' => 'save',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Load several fields of $key in one round trip, as a field => value map with
     * missing/expired fields omitted. An empty $fields loads every field. Adapters
     * that do not store fields return an empty map.
     *
     * @param  int  $ttl time in seconds
     * @param  string[]  $fields
     * @return array<string, mixed>
     */
    public function loadMany(string $key, int $ttl, array $fields = []): array
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        if (! $this->caseSensitive) {
            $fields = array_map(strtolower(...), $fields);
        }

        $start = microtime(true);
        $result = $this->adapter instanceof Batchable
            ? $this->adapter->loadMany($key, $fields, $ttl)
            : [];
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'loadMany',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Write every field => value pair of $data in one round trip. When $ttl > 0
     * the key is given a key-level expiry. Returns $data on success, or false on
     * failure or for adapters that do not store fields.
     *
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        if (! $this->caseSensitive) {
            $data = array_change_key_case($data, CASE_LOWER);
        }

        $start = microtime(true);

        try {
            return $this->adapter instanceof Batchable
                ? $this->adapter->saveMany($key, $data, $ttl)
                : false;
        } finally {
            $duration = microtime(true) - $start;
            $this->getOperationDuration()->record($duration, [
                'operation' => 'saveMany',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Current generation token for $key (advances on each purge). Returns '0'
     * when the underlying adapter does not support leasing.
     */
    public function getGeneration(string $key): string
    {
        if (! $this->adapter instanceof Leasable) {
            return '0';
        }

        $key = $this->caseSensitive ? $key : strtolower($key);

        return $this->adapter->getGeneration($key);
    }

    /**
     * Save $data only if the key's generation still equals $generation (i.e. no
     * purge happened since the caller captured it via getGeneration). Closes the
     * cache-aside read-after-write race. Falls back to an unconditional save when
     * the adapter does not support leasing.
     *
     * @param  string|array<int|string, mixed>  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function saveWithLease(string $key, mixed $data, string $hash, string $generation): bool|string|array
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);
        $start = microtime(true);

        try {
            if (! $this->adapter instanceof Leasable) {
                return $this->adapter->save($key, $data, $hash);
            }

            return $this->adapter->saveWithLease($key, $data, $hash, $generation);
        } finally {
            $duration = microtime(true) - $start;
            $this->getOperationDuration()->record($duration, [
                'operation' => 'saveWithLease',
                'adapter' => $this->adapter->getName($key),
            ]);
        }
    }

    /**
     * Refresh a cache entry timestamp without replacing its data.
     *
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->touch($key, $hash);
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'touch',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Returns a list of keys.
     *
     * @return string[]
     */
    public function list(string $key): array
    {
        $key = $this->caseSensitive ? $key : strtolower($key);

        $start = microtime(true);
        $result = $this->adapter->list($key);
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'list',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Removes data from cache. Returns true on success of false on failure.
     *
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        $key = $this->caseSensitive ? $key : strtolower($key);
        $hash = $this->caseSensitive ? $hash : strtolower($hash);

        $start = microtime(true);
        $result = $this->adapter->purge($key, $hash);
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'purge',
            'adapter' => $this->adapter->getName($key),
        ]);

        return $result;
    }

    /**
     * Removes all data from cache. Returns true on success of false on failure.
     */
    public function flush(): bool
    {
        $start = microtime(true);
        $result = $this->adapter->flush();
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'flush',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }

    /**
     * Check Cache Connecitivity
     */
    public function ping(): bool
    {
        return $this->adapter->ping();
    }

    /**
     * Get db size.
     */
    public function getSize(): int
    {
        $start = microtime(true);
        $result = $this->adapter->getSize();
        $duration = microtime(true) - $start;
        $this->getOperationDuration()->record($duration, [
            'operation' => 'size',
            'adapter' => $this->adapter->getName(),
        ]);

        return $result;
    }
}
