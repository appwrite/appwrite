<?php

namespace Utopia\Queue\Connection;

use Utopia\Queue\Connection;

class Redis implements Connection
{
    protected const int CONNECT_MAX_ATTEMPTS = 5;
    protected const int CONNECT_BACKOFF_MS = 100;
    protected const int CONNECT_MAX_BACKOFF_MS = 3_000;
    protected ?\Redis $redis = null;

    public function __construct(protected string $host, protected int $port = 6379, protected ?string $user = null, protected ?string $password = null, protected float $connectTimeout = -1, protected float $readTimeout = -1) {}

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        $response = $this->rightPopLeftPush($queue, $destination, $timeout);

        if (!$response) {
            return false;
        }

        return json_decode($response, true);
    }
    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        $response = $this->call(fn(\Redis $redis): \Redis|string|false => $redis->bRPopLPush($queue, $destination, $timeout));

        if (!$response) {
            return false;
        }

        return $response;
    }
    public function rightPushArray(string $queue, array $value): bool
    {
        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->rPush($queue, json_encode($value)));
    }

    public function rightPush(string $queue, string $value): bool
    {
        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->rPush($queue, $value));
    }

    public function leftPushArray(string $queue, array $value): bool
    {
        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->lPush($queue, json_encode($value)));
    }

    public function leftPush(string $queue, string $value): bool
    {
        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->lPush($queue, $value));
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        if ($payloads === []) {
            return true;
        }

        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->lPush($queue, ...$payloads));
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        if ($payloads === []) {
            return true;
        }

        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->rPush($queue, ...$payloads));
    }

    /** @phpstan-impure */
    public function rightPopArray(string $queue, int $timeout): array|false
    {
        $response = $this->rightPop($queue, $timeout);

        if ($response === false) {
            return false;
        }

        return json_decode($response, true) ?? false;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        $response = $this->call(fn(\Redis $redis): array|\Redis|false|null => $redis->brPop([$queue], $timeout));

        if (empty($response)) {
            return false;
        }

        return $response[1];
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        $response = $this->call(fn(\Redis $redis): \Redis|array|false|null => $redis->blPop($queue, $timeout));

        if (empty($response)) {
            return false;
        }

        return json_decode((string) $response[1], true) ?? false;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        $response = $this->call(fn(\Redis $redis): \Redis|array|false|null => $redis->blPop($queue, $timeout));

        if (empty($response)) {
            return false;
        }

        return $response[1];
    }

    public function listRemove(string $queue, string $key): bool
    {
        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->lRem($queue, $key, 1), idempotent: true);
    }

    public function remove(string $key): bool
    {
        return (bool) $this->call(fn(\Redis $redis): int|\Redis|false => $redis->del($key), idempotent: true);
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        return $this->set($key, json_encode($value), $ttl);
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        if ($ttl > 0) {
            return $this->call(fn(\Redis $redis): bool|\Redis => $redis->setex($key, $ttl, $value), idempotent: true);
        }
        return $this->call(fn(\Redis $redis): \Redis|string|bool => $redis->set($key, $value), idempotent: true);
    }

    public function get(string $key): array|string|null
    {
        return $this->call(fn(\Redis $redis): mixed => $redis->get($key), idempotent: true);
    }

    public function listSize(string $key): int
    {
        return $this->call(fn(\Redis $redis): int|\Redis|false => $redis->lLen($key), idempotent: true);
    }

    public function increment(string $key): int
    {
        return $this->call(fn(\Redis $redis): int|\Redis|false => $redis->incr($key));
    }

    public function decrement(string $key): int
    {
        return $this->call(fn(\Redis $redis): int|\Redis|false => $redis->decr($key));
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        $start = $offset;
        $end = $start + $total - 1;

        return $this->call(fn(\Redis $redis): array|\Redis|false => $redis->lRange($key, $start, $end), idempotent: true);
    }

    public function ping(): bool
    {
        try {
            $this->getRedis()->ping();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function close(): void
    {
        try {
            $this->redis?->close();
        } catch (\Throwable) {
        } finally {
            $this->redis = null;
        }
    }

    /**
     * Run a command on the cached client. When phpredis reports the socket
     * is gone, drop the client so the next command opens a fresh one, and
     * for a command that is safe to repeat run it again right away.
     *
     * phpredis reconnects a dropped socket by itself, but when the peer stays
     * away past its retry budget it parks the client in a failed state for
     * good, and every later command throws "went away". A worker that lived
     * through a broker failover longer than those retries would otherwise fail
     * every ack until the process restarts.
     *
     * Commands whose execution the server may already have applied (pops,
     * pushes, counters) are never replayed: the caller sees the error and the
     * broker's own retry path decides.
     *
     * @template T
     * @param callable(\Redis): T $command
     * @return T
     */
    protected function call(callable $command, bool $idempotent = false): mixed
    {
        try {
            return $command($this->getRedis());
        } catch (\RedisException $e) {
            if (!$this->isTransportError($e)) {
                throw $e;
            }

            $this->close();

            if (!$idempotent) {
                throw $e;
            }

            return $command($this->getRedis());
        }
    }

    /**
     * phpredis errors that mean the socket is gone rather than the command
     * being wrong. Anything else (WRONGTYPE, OOM, ...) is the caller's to see.
     */
    private function isTransportError(\RedisException $e): bool
    {
        return (bool) preg_match(
            '/went away|Connection (lost|closed|refused)|read error on connection/',
            $e->getMessage(),
        );
    }

    protected function getRedis(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        $connectTimeout = $this->connectTimeout < 0 ? 0 : $this->connectTimeout;

        for ($attempt = 1; $attempt <= self::CONNECT_MAX_ATTEMPTS; $attempt++) {
            $redis = new \Redis();

            try {
                $redis->connect($this->host, $this->port, $connectTimeout);

                if ($this->password !== null && $this->password !== '') {
                    $hasUser = $this->user !== null && $this->user !== '';
                    $redis->auth($hasUser ? [$this->user, $this->password] : $this->password);
                }

                if ($this->readTimeout >= 0) {
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeout);
                }

                $this->redis = $redis;
                return $this->redis;
            } catch (\RedisException $e) {
                try {
                    $redis->close();
                } catch (\Throwable) {
                }

                if ($attempt === self::CONNECT_MAX_ATTEMPTS) {
                    throw new \RedisException(
                        \sprintf(
                            'Failed to connect to Redis at %s:%d after %d attempts: %s',
                            $this->host,
                            $this->port,
                            self::CONNECT_MAX_ATTEMPTS,
                            $e->getMessage(),
                        ),
                        $e->getCode(),
                        $e,
                    );
                }

                // Exponential backoff with full jitter to avoid thundering herd on recovery.
                $backoffMs = min(
                    self::CONNECT_MAX_BACKOFF_MS,
                    self::CONNECT_BACKOFF_MS * (2 ** ($attempt - 1)),
                );
                usleep(mt_rand(0, $backoffMs) * 1000);
            }
        }

        throw new \RedisException(\sprintf(
            'Unreachable: Redis connect loop for %s:%d exited after %d attempts without success or exception.',
            $this->host,
            $this->port,
            self::CONNECT_MAX_ATTEMPTS,
        ));
    }
}
