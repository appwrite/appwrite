<?php

namespace Utopia\Abuse\Adapters\TokenBucket;

use Utopia\Abuse\Adapters\TokenBucket;

/**
 * Shared implementation for the Redis-family token-bucket adapters
 * (Redis, RedisCluster, RedisPool). Owns the atomic Lua script, the refill
 * math and the check/tokens/reset flow. Subclasses only implement how they
 * talk to storage via the eval()/delete() seams, plus their own getLogs().
 *
 * The bucket state (tokens + last refill time) lives in a single Redis hash and
 * is refilled from the clock on every operation, so the limiter stays correct
 * regardless of how long an adapter instance is reused.
 */
abstract class RedisBase extends TokenBucket
{
    public const NAMESPACE = 'abuse';

    /**
     * Atomic token-bucket refill-and-consume.
     *
     * KEYS[1] bucket hash key.
     * ARGV[1] max_tokens (capacity), ARGV[2] refill_rate (tokens/sec), ARGV[3] now (fractional seconds).
     *
     * Returns { allowed (0|1), remaining_tokens } where remaining_tokens is a
     * string so the fractional token balance survives the round trip.
     */
    protected const string LIMIT_CHECK_SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local max_tokens = tonumber(ARGV[1])
        local refill_rate = tonumber(ARGV[2])
        local now = tonumber(ARGV[3])

        local data = redis.call('HMGET', key, 'tokens', 'last_refill')
        local tokens = tonumber(data[1]) or max_tokens
        local last_refill = tonumber(data[2]) or now

        -- Refill based on elapsed time, capped at the capacity.
        local elapsed = now - last_refill
        if elapsed < 0 then elapsed = 0 end
        tokens = math.min(max_tokens, tokens + elapsed * refill_rate)

        local allowed = 0
        if tokens >= 1 then
          tokens = tokens - 1
          allowed = 1
        end

        redis.call('HSET', key, 'tokens', tostring(tokens), 'last_refill', tostring(now))
        redis.call('EXPIRE', key, math.ceil(max_tokens / refill_rate) + 1)

        return { allowed, tostring(tokens) }
        LUA;

    /**
     * Read-only token estimate: refills the bucket for the elapsed time without
     * consuming anything or writing back. Used by remaining().
     *
     * KEYS[1] bucket hash key.
     * ARGV[1] max_tokens, ARGV[2] refill_rate, ARGV[3] now.
     *
     * Returns the available token balance as a string.
     */
    protected const string TOKENS_SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local max_tokens = tonumber(ARGV[1])
        local refill_rate = tonumber(ARGV[2])
        local now = tonumber(ARGV[3])

        local data = redis.call('HMGET', key, 'tokens', 'last_refill')
        local tokens = tonumber(data[1]) or max_tokens
        local last_refill = tonumber(data[2]) or now

        local elapsed = now - last_refill
        if elapsed < 0 then elapsed = 0 end
        tokens = math.min(max_tokens, tokens + elapsed * refill_rate)

        return tostring(tokens)
        LUA;

    /**
     * Tokens refilled per second.
     *
     * @var float
     */
    protected float $refillRate;

    /**
     * Run a Lua script against the storage backend.
     *
     * @param  string  $script
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     * @return mixed  the raw script result
     */
    abstract protected function eval(string $script, array $keys, array $argv): mixed;

    /**
     * Delete the given keys.
     *
     * @param  string  ...$keys
     * @return void
     */
    abstract protected function delete(string ...$keys): void;

    /**
     * Validate and store the bucket configuration.
     *
     * @param  float  $refillRate
     * @return void
     */
    protected function initBucket(float $refillRate): void
    {
        if ($refillRate <= 0) {
            throw new \InvalidArgumentException('refillRate must be greater than 0');
        }

        $this->refillRate = $refillRate;
        $this->timestamp = \time();
    }

    /**
     * Build the bucket hash key for a given abuse key.
     *
     * @param  string  $key
     * @return string
     */
    protected function bucketKey(string $key): string
    {
        return self::NAMESPACE . '__' . $key;
    }

    /**
     * Check
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function check(): bool
    {
        if ($this->tokens === 0) {
            return false;
        }

        $key = $this->parseKey();
        $this->timestamp = \time();

        /** @var array{0:int,1:string} $result */
        $result = $this->eval(
            self::LIMIT_CHECK_SCRIPT,
            [
                $this->bucketKey($key), // KEYS[1] bucket hash
            ],
            [
                $this->tokens,      // ARGV[1] max_tokens
                $this->refillRate,  // ARGV[2] refill_rate
                \microtime(true),   // ARGV[3] now (fractional seconds)
            ],
        );

        [$allowed] = $result;

        return (int) $allowed === 0;
    }

    /**
     * Count
     *
     * Read-only estimate of the tokens already consumed from the bucket
     * (capacity minus the tokens available after refilling). Used by remaining().
     * The bucket refills continuously, so this always reads a fresh estimate
     * rather than reusing a cached value that would go stale as tokens refill.
     *
     * @param  string  $key
     * @param  int  $timestamp
     * @return int
     */
    protected function count(string $key, int $timestamp): int
    {
        if ($this->tokens === 0) {
            return 0;
        }

        $this->timestamp = \time();

        $raw = $this->eval(
            self::TOKENS_SCRIPT,
            [
                $this->bucketKey($key),
            ],
            [
                $this->tokens,
                $this->refillRate,
                \microtime(true),
            ],
        );

        $balance = \is_numeric($raw) ? (float) $raw : (float) $this->tokens;

        return $this->tokens - (int) \floor($balance);
    }

    /**
     * Reset
     *
     * Drop the bucket state so the next request sees a full bucket.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->delete($this->bucketKey($this->parseKey()));
    }

    /**
     * No need for manual cleanup - Redis TTL handles this automatically
     *
     * @param  int  $timestamp
     * @return bool
     */
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
