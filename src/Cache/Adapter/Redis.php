<?php

namespace Utopia\Cache\Adapter;

use Exception;
use Redis as Client;
use Throwable;
use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis\Envelope;
use Utopia\Cache\Adapter\Redis\Leasable;
use Utopia\Cache\Adapter\Redis\NoScript;
use Utopia\Cache\Feature\Batchable;
use Utopia\Cache\Feature\Retryable;

class Redis extends Leasable implements Adapter, Batchable, Retryable
{
    protected Client $redis;

    private int $maxRetries = 0;

    private int $retryDelay = 1000; // milliseconds

    private readonly string $host;

    private readonly int $port;

    private readonly float $timeout;

    private readonly ?string $persistentId;

    private readonly float $readTimeout;

    /**
     * Credentials replayed on reconnect, as the argument list AUTH takes:
     * [password] for a legacy password, [username, password] for an ACL user.
     *
     * @var array<string>
     */
    private array $auth = [];

    /**
     * Whether the original connection was persistent (pconnect)
     */
    private bool $persistent = false;

    private int $dbIndex = 0;

    /**
     * Redis constructor.
     */
    public function __construct(Client $redis)
    {
        $this->host = $redis->getHost();
        $this->port = $redis->getPort();
        $timeout = $redis->getTimeout();
        $this->timeout = ($timeout !== false) ? $timeout : 0.0;
        $this->persistentId = $redis->getPersistentId();
        $this->readTimeout = $redis->getReadTimeout();

        $this->persistent = $this->persistentId !== null;
        $this->dbIndex = $redis->getDbNum();

        $auth = $redis->getAuth();
        $this->auth = match (true) {
            \is_array($auth) => $auth,
            \is_string($auth) => [$auth],
            default => [],
        };

        $this->redis = $redis;
    }

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

        if ($this->isReserved($hash)) {
            return false;
        }

        $redis_string = $this->execute(fn(): \Redis|string|false => $this->redis->hGet($key, $hash));

        if (! \is_string($redis_string)) {
            return false;
        }

        return Envelope::decode($redis_string, $ttl, time());
    }

    /**
     * HMGET for an explicit field list, HGETALL when $fields is empty. One
     * command either way, so it is multiplexing-safe and retries via execute().
     *
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array
    {
        $now = time();

        /** @var array<string, mixed>|false $raw */
        $raw = $fields === []
            ? $this->execute(fn(): \Redis|array|false => $this->redis->hGetAll($key))
            : $this->execute(fn(): \Redis|array|false => $this->redis->hMget($key, $fields));

        if (! \is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $field => $value) {
            // Missing HMGET fields come back as false; reserved fields never surface.
            if (! \is_string($value)) {
                continue;
            }
            if ($this->isReserved((string) $field)) {
                continue;
            }

            $decoded = Envelope::decode($value, $ttl, $now);
            if ($decoded !== false) {
                $result[(string) $field] = $decoded;
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

        if ($this->isReserved($hash)) {
            return false;
        }

        try {
            $value = Envelope::encode($data, time());
            $this->execute(fn(): \Redis|int|false => $this->redis->hSet($key, $hash, $value));

            if ($ttl > 0) {
                $this->execute(fn(): \Redis|bool => $this->redis->expire($key, $ttl));
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
            $field = (string) $field;
            if ($this->isReserved($field)) {
                continue;
            }
            $map[$field] = Envelope::encode($value, time());
        }

        if ($map === []) {
            return false;
        }

        try {
            $this->execute(fn(): \Redis|bool => $this->redis->hMSet($key, $map));

            if ($ttl > 0) {
                $this->execute(fn(): \Redis|bool => $this->redis->expire($key, $ttl));
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

        if ($this->isReserved($hash)) {
            return false;
        }

        $redis_string = $this->execute(fn(): \Redis|string|false => $this->redis->hGet($key, $hash));

        if (! \is_string($redis_string)) {
            return false;
        }

        $value = Envelope::touch($redis_string, time());
        if ($value === false) {
            return false;
        }

        return $this->execute(fn(): \Redis|int|false => $this->redis->hSet($key, $hash, $value)) !== false;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        /** @var array<string> */
        $keys = $this->execute(fn(): \Redis|array|false => $this->redis->hKeys($key));

        if (empty($keys)) {
            return [];
        }

        // Don't expose reserved internal fields (generation, tombstone) as listable cache fields.
        return array_values(array_filter($keys, fn(string $field): bool => ! $this->isReserved($field)));
    }

    protected function leaseEvalSha(string $sha, string $key, array $args): mixed
    {
        return $this->execute(function () use ($sha, $key, $args) {
            // Client-side reset (no round trip) so the getLastError() check below
            // reflects only this evalSha, not an error left by a prior command.
            $this->redis->clearLastError();

            try {
                $result = $this->redis->evalSha($sha, [$key, ...$args], 1);
            } catch (\RedisException $e) {
                // php-redis may throw NOSCRIPT rather than returning false.
                if (NoScript::matches($e->getMessage())) {
                    throw NoScript::from($e);
                }

                throw $e;
            }

            // ...or it reports the same error as false + getLastError(). A lease
            // script always returns an int on success, so false is an error to
            // inspect; NOSCRIPT means leaseRun() should resend the body.
            $error = (string) $this->redis->getLastError();
            if ($result === false && NoScript::matches($error)) {
                throw NoScript::from($error);
            }

            return $result;
        });
    }

    protected function leaseEval(string $script, string $key, array $args): mixed
    {
        return $this->execute(fn(): mixed => $this->redis->eval($script, [$key, ...$args], 1));
    }

    protected function leaseHget(string $key, string $field): mixed
    {
        return $this->execute(fn(): \Redis|string|false => $this->redis->hGet($key, $field));
    }

    public function flush(): bool
    {
        return (bool) $this->execute(fn(): \Redis|bool => $this->redis->flushDB());
    }

    public function ping(): bool
    {
        try {
            $this->redis->ping();

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        // A purged key keeps its reserved field(s) until re-cached, so DBSIZE can
        // slightly over-count purged-but-not-yet-recached keys.
        /** @var int $size */
        $size = $this->execute(fn(): \Redis|int|false => $this->redis->dbSize());

        return $size;
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
     * Execute a Redis command with retry logic
     *
     *
     * @throws \RedisException
     */
    private function execute(callable $callback): mixed
    {
        $attempts = 0;
        $maxAttempts = 1 + $this->maxRetries;

        while ($attempts < $maxAttempts) {
            try {
                return $callback();
            } catch (\RedisException $th) {
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
                } catch (\RedisException) {
                    // Reconnect failed, will retry on next iteration
                }
            }
        }

        // This line is unreachable but required for PHPStan
        throw new \RedisException('Failed to execute Redis command');
    }

    /**
     * Check if the exception is a connection-related error that should trigger reconnect.
     *
     * RedisException always returns error code 0 with no subclasses for different error types.
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
        ];

        $message = strtolower($e->getMessage());
        return array_any($connectionErrors, fn(string $needle): bool => str_contains($message, $needle));
    }

    private function reconnect(): void
    {
        $newRedis = new Client();

        if ($this->persistent) {
            $newRedis->pconnect(
                $this->host,
                $this->port,
                $this->timeout,
                $this->persistentId,
                0,
                $this->readTimeout,
            );
        } else {
            $newRedis->connect(
                $this->host,
                $this->port,
                $this->timeout,
                $this->persistentId,
                0,
                $this->readTimeout,
            );
        }

        if ($this->auth !== []) {
            $newRedis->auth(\count($this->auth) === 1 ? $this->auth[0] : $this->auth);
        }

        if ($this->dbIndex !== 0) {
            $newRedis->select($this->dbIndex);
        }

        $this->redis = $newRedis;
    }

    public function getName(?string $key = null): string
    {
        return 'redis';
    }
}
