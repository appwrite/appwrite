<?php

namespace Utopia\Cache\Adapter;

use Memcached as Client;
use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Retryable;

class Hazelcast implements Adapter, Retryable
{
    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    public function __construct(protected Client $memcached) {}

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
        $cache = $this->execute(fn(): mixed => $this->memcached->get($key));
        if (\is_string($cache)) {
            $cache = Json::decode($cache);
        }

        if (! \is_array($cache)) {
            return false;
        }

        if (($cache['time'] + $ttl > time())) { // Cache is valid
            return $cache['data'];
        }

        return false;
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

        $cache = [
            'time' => time(),
            'data' => $data,
        ];

        return ($this->execute(fn(): bool => $this->memcached->set($key, json_encode($cache)))) ? $data : false;
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $cache = $this->execute(fn(): mixed => $this->memcached->get($key));
        if (\is_string($cache)) {
            $cache = Json::decode($cache);
        }

        if (! \is_array($cache)) {
            return false;
        }

        $cache['time'] = time();

        return (bool) $this->execute(fn(): bool => $this->memcached->set($key, json_encode($cache)));
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        return (bool) $this->execute(fn(): bool => $this->memcached->delete($key));
    }

    /**
     * @return bool
     * currently hazelcast doesn't support flush functionality, so returning false in that case
     */
    public function flush(): bool
    {
        return false;
    }

    public function ping(): bool
    {
        try {
            $statuses = $this->execute(fn(): array => $this->memcached->getServerList());

            return ! empty($statuses);
        } catch (\MemcachedException) {
            return false;
        }
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        $size = 0;
        $servers = $this->memcached->getServerList();
        if (! empty($servers)) {
            $stats = $this->memcached->getStats();
            $key = $servers[0]['host'] . ':' . $servers[0]['port'];
            if (isset($stats[$key])) {
                $size = $stats[$key]['total_items'] ?? 0;
            }
        }

        return $size;
    }

    public function getName(?string $key = null): string
    {
        return 'hazelcast';
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Execute a Memcached command with retry logic
     *
     * @param  callable  $callback The Memcached operation to execute
     * @return mixed The result of the Memcached operation
     *
     * @throws \MemcachedException When all retry attempts fail
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            $result = $callback();

            if ($result === false && \in_array($this->memcached->getResultCode(), [
                \Memcached::RES_HOST_LOOKUP_FAILURE,
                \Memcached::RES_UNKNOWN_READ_FAILURE,
                \Memcached::RES_WRITE_FAILURE,
                \Memcached::RES_PROTOCOL_ERROR,
                \Memcached::RES_INVALID_HOST_PROTOCOL,
                \Memcached::RES_CONNECTION_SOCKET_CREATE_FAILURE,
                \Memcached::RES_CONNECTION_FAILURE,
                \Memcached::RES_SERVER_TEMPORARILY_DISABLED,
                \Memcached::RES_SERVER_MARKED_DEAD,
                \Memcached::RES_TIMEOUT,
            ])) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw new \MemcachedException('Hazelcast connection failed after ' . $attempts . ' attempts. Error: ' . $this->memcached->getResultMessage());
                }

                usleep($this->retryDelay * 1000);

                continue;
            }

            return $result;
        }

        return false;
    }
}
