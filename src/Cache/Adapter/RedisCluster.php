<?php

namespace Utopia\Cache\Adapter;

use Exception;
use RedisCluster as Client;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Batchable;
use Utopia\Cache\Feature\Retryable;

class RedisCluster implements Adapter, Batchable, Retryable
{
    private int $maxRetries = 0;

    private int $retryDelay = 1000;

    /**
     * @param  array<string>  $seeds
     * @param  string|array<string>|null  $auth  Password string or ['username', 'password'] array for ACL
     */
    public function __construct(protected Client $redis, protected array $seeds, protected ?string $name = null, private readonly float $timeout = 1.5, private readonly float $readTimeout = 1.5, private readonly bool $persistent = false, private readonly string|array|null $auth = null) {}

    /**
     * @param  int  $maxRetries (0-10)
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(Retryable::MIN_RETRIES, min($maxRetries, Retryable::MAX_RETRIES));

        return $this;
    }

    /**
     * @param  int  $retryDelay time in milliseconds
     */
    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;

        return $this;
    }

    /**
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        /** @var string|false */
        $redis_string = $this->execute(fn(): string => $this->redis->hGet($key, $hash));

        if ($redis_string === false || ! \is_string($redis_string)) {
            return false;
        }

        $cache = Json::decode($redis_string);

        // A purged key keeps its field until re-cached, holding a value that
        // is not an envelope.
        if (! \is_array($cache) || ! isset($cache['time'], $cache['data'])) {
            return false;
        }

        if ($cache['time'] + $ttl > time()) { // Cache is valid
            return $cache['data'];
        }

        return false;
    }

    /**
     * HMGET for an explicit field list, HGETALL when $fields is empty.
     *
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array
    {
        $now = time();

        /** @var array<string, mixed> $raw */
        $raw = (array) ($fields === []
            ? $this->execute(fn(): array => $this->redis->hGetAll($key))
            : $this->execute(fn(): array => $this->redis->hMget($key, $fields)));

        $result = [];
        foreach ($raw as $field => $value) {
            if (! \is_string($value)) {
                continue;
            }

            $cache = Json::decode($value);
            if (! \is_array($cache)) {
                continue;
            }
            if (! isset($cache['time'], $cache['data'])) {
                continue;
            }

            if ($cache['time'] + $ttl > $now) {
                $result[(string) $field] = $cache['data'];
            }
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($key === '' || $key === '0' || empty($data)) {
            return false;
        }

        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        try {
            $value = json_encode([
                'time' => time(),
                'data' => $data,
            ], flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        try {
            $this->execute(fn(): int => $this->redis->hSet($key, $hash, $value));

            if ($ttl > 0) {
                $this->execute(fn(): bool => $this->redis->expire($key, $ttl));
            }

            return $data;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * HMSET every field => value pair of $data in one round trip, then one EXPIRE.
     *
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        $map = [];
        foreach ($data as $field => $value) {
            try {
                $map[(string) $field] = json_encode([
                    'time' => time(),
                    'data' => $value,
                ], flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return false;
            }
        }

        if ($map === []) {
            return false;
        }

        try {
            $this->execute(fn(): bool => $this->redis->hMSet($key, $map));

            if ($ttl > 0) {
                $this->execute(fn(): bool => $this->redis->expire($key, $ttl));
            }

            return $data;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        if ($hash === '' || $hash === '0') {
            $hash = $key;
        }

        /** @var string|false */
        $redis_string = $this->execute(fn(): string => $this->redis->hGet($key, $hash));

        if ($redis_string === false || ! \is_string($redis_string)) {
            return false;
        }

        try {
            /** @var array{time: int, data: mixed} $cache */
            $cache = Json::decode($redis_string, JSON_THROW_ON_ERROR);
            $cache['time'] = time();
            $value = json_encode($cache, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return $this->execute(fn(): int => $this->redis->hSet($key, $hash, $value)) !== false;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        /** @var array<string> */
        $keys = (array) $this->execute(fn(): array => $this->redis->hKeys($key));

        if (empty($keys)) {
            return [];
        }

        return $keys;
    }

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash !== '' && $hash !== '0') {
            return (bool) $this->execute(fn(): int => $this->redis->hdel($key, $hash));
        }

        return (bool) $this->execute(fn(): int => $this->redis->del($key));
    }

    public function flush(): bool
    {
        return (bool) $this->execute(function (): true {
            /** @var array<string> $masters */
            $masters = $this->redis->_masters();
            foreach ($masters as $master) {
                $this->redis->flushDB($master);
            }

            return true;
        });
    }

    public function ping(): bool
    {
        try {
            return (bool) $this->execute(function (): true {
                foreach ($this->redis->_masters() as $master) {
                    $this->redis->ping($master);
                }

                return true;
            });
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        $size = $this->execute(function () {
            $size = 0;
            foreach ($this->redis->_masters() as $master) {
                $size += $this->redis->dbSize($master);
            }

            return $size;
        });

        if ($size === false || ! is_numeric($size)) {
            return 0;
        }

        return (int) $size;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function getClusterName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getSeeds(): array
    {
        return $this->seeds;
    }

    /**
     * Execute a Redis command with retry logic
     *
     *
     * @throws \RedisClusterException
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisClusterException $th) {
                if (! $this->isConnectionError($th)) {
                    throw $th;
                }

                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $th;
                }

                usleep($this->retryDelay * 1000); // Convert milliseconds to microseconds

                try {
                    $this->reconnect();
                } catch (\RedisClusterException) {
                    // Reconnect failed, will retry on next iteration
                }
            }
        }

        // This line is unreachable but required for PHPStan
        throw new \RedisClusterException('Failed to execute Redis command');
    }

    /**
     * Check if the exception is a connection-related error that should trigger reconnect.
     *
     * RedisClusterException always returns error code 0 with no subclasses for different error types.
     * The only way to differentiate connection errors from command errors is by message matching.
     */
    private function isConnectionError(Exception $e): bool
    {
        $connectionErrors = [
            'went away',
            'socket',
            'read error on connection',
            'connection lost',
            'timed out',
            'timeout',
            'connection refused',
            'no connection',
            'broken pipe',
            // Redis Cluster specific
            "couldn't map cluster keyspace",
            "can't communicate with any node",
            'clusterdown',
            'is not covered by any node',
        ];

        $message = strtolower($e->getMessage());
        return array_any($connectionErrors, fn(string $needle): bool => str_contains($message, $needle));
    }

    private function reconnect(): void
    {
        $newRedis = new Client(
            $this->name,
            $this->seeds,
            $this->timeout,
            $this->readTimeout,
            $this->persistent,
            $this->auth,
        );

        $this->redis = $newRedis;
    }

    public function getName(?string $key = null): string
    {
        return 'redis-cluster';
    }
}
