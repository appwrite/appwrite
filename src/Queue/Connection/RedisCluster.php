<?php

namespace Utopia\Queue\Connection;

use Utopia\Queue\Connection;

class RedisCluster implements Connection
{
    protected const int CONNECT_MAX_ATTEMPTS = 5;
    protected const int CONNECT_BACKOFF_MS = 100;
    protected const int CONNECT_MAX_BACKOFF_MS = 3_000;
    protected ?\RedisCluster $redis = null;

    public function __construct(protected array $seeds, protected float $connectTimeout = -1, protected float $readTimeout = -1, protected ?string $user = null, protected ?string $password = null) {}

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
        $response = $this->getRedis()->bRPopLPush($queue, $destination, $timeout);

        if (!$response) {
            return false;
        }

        return $response;
    }
    public function rightPushArray(string $queue, array $value): bool
    {
        return (bool) $this->getRedis()->rPush($queue, json_encode($value));
    }

    public function rightPush(string $queue, string $value): bool
    {
        return (bool) $this->getRedis()->rPush($queue, $value);
    }

    public function leftPushArray(string $queue, array $value): bool
    {
        return (bool) $this->getRedis()->lPush($queue, json_encode($value));
    }

    public function leftPush(string $queue, string $value): bool
    {
        return (bool) $this->getRedis()->lPush($queue, $value);
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        if ($payloads === []) {
            return true;
        }

        return (bool) $this->getRedis()->lPush($queue, ...$payloads);
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        if ($payloads === []) {
            return true;
        }

        return (bool) $this->getRedis()->rPush($queue, ...$payloads);
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
        $response = $this->getRedis()->brPop([$queue], $timeout);

        if (empty($response)) {
            return false;
        }

        return $response[1];
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        $response = $this->getRedis()->blPop([$queue], $timeout);

        if (empty($response)) {
            return false;
        }

        return json_decode((string) $response[1], true) ?? false;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        $response = $this->getRedis()->blPop([$queue], $timeout);

        if (empty($response)) {
            return false;
        }

        return $response[1];
    }

    public function listRemove(string $queue, string $key): bool
    {
        return (bool) $this->getRedis()->lRem($queue, $key, 1);
    }

    public function remove(string $key): bool
    {
        return (bool) $this->getRedis()->del($key);
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        return $this->set($key, json_encode($value), $ttl);
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        if ($ttl > 0) {
            return $this->getRedis()->setex($key, $ttl, $value);
        }
        return $this->getRedis()->set($key, $value);
    }

    public function get(string $key): array|string|null
    {
        return $this->getRedis()->get($key);
    }

    public function listSize(string $key): int
    {
        return $this->getRedis()->lLen($key);
    }

    public function increment(string $key): int
    {
        return $this->getRedis()->incr($key);
    }

    public function decrement(string $key): int
    {
        return $this->getRedis()->decr($key);
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        $start = $offset;
        $end = $start + $total - 1;

        return $this->getRedis()->lRange($key, $start, $end);
    }

    public function ping(): bool
    {
        try {
            foreach ($this->getRedis()->_masters() as $master) {
                $this->getRedis()->ping($master);
            }

            return true;
        } catch (\Throwable) {
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

    protected function getRedis(): \RedisCluster
    {
        if ($this->redis instanceof \RedisCluster) {
            return $this->redis;
        }

        $connectTimeout = $this->connectTimeout < 0 ? 0 : $this->connectTimeout;
        $readTimeout = $this->readTimeout < 0 ? 0 : $this->readTimeout;

        for ($attempt = 1; $attempt <= self::CONNECT_MAX_ATTEMPTS; $attempt++) {
            try {
                $auth = match (true) {
                    $this->password === null || $this->password === '' => null,
                    $this->user !== null && $this->user !== '' => [$this->user, $this->password],
                    default => $this->password,
                };
                $this->redis = new \RedisCluster(null, $this->seeds, $connectTimeout, $readTimeout, false, $auth);
                return $this->redis;
            } catch (\RedisClusterException $e) {
                if ($attempt === self::CONNECT_MAX_ATTEMPTS) {
                    throw new \RedisClusterException(
                        \sprintf(
                            'Failed to connect to Redis cluster nodes [%s] after %d attempts: %s',
                            implode(', ', $this->seeds),
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

        throw new \RedisClusterException(\sprintf(
            'Unreachable: Redis cluster connect loop for nodes [%s] exited after %d attempts without success or exception.',
            implode(', ', $this->seeds),
            self::CONNECT_MAX_ATTEMPTS,
        ));
    }
}
