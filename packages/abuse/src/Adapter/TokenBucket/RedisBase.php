<?php

namespace Utopia\Abuse\Adapter\TokenBucket;

use Utopia\Abuse\Adapter\TokenBucket;

abstract readonly class RedisBase extends TokenBucket
{
    public const string NAMESPACE = 'abuse';

    protected const string LIMIT_CHECK_SCRIPT = <<<'LUA'
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

        local used = max_tokens - math.floor(tokens)

        if tokens >= 1 then
          tokens = tokens - 1
        end

        redis.call('HSET', key, 'tokens', tostring(tokens), 'last_refill', tostring(now))
        redis.call('EXPIRE', key, math.ceil(max_tokens / refill_rate) + 1)

        return used
        LUA;

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

        return max_tokens - math.floor(tokens)
        LUA;

    /**
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     */
    abstract protected function eval(string $script, array $keys, array $argv): mixed;

    abstract protected function delete(string ...$keys): void;

    #[\Override]
    protected function hit(string $key, float $now): int
    {
        return $this->used($this->eval(self::LIMIT_CHECK_SCRIPT, [$this->bucketKey($key)], [$this->tokens, $this->refillRate, $now]));
    }

    #[\Override]
    protected function count(string $key, float $now): int
    {
        return $this->used($this->eval(self::TOKENS_SCRIPT, [$this->bucketKey($key)], [$this->tokens, $this->refillRate, $now]));
    }

    #[\Override]
    protected function clear(string $key): void
    {
        $this->delete($this->bucketKey($key));
    }

    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        return true;
    }

    protected function bucketKey(string $key): string
    {
        return self::NAMESPACE . '__' . $key;
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function logs(\Redis|\RedisCluster $redis, ?int $offset, ?int $limit): array
    {
        $matches = $this->scan($redis);
        \sort($matches);
        $matches = \array_slice($matches, $offset ?? 0, $limit ?? 25);

        $logs = [];
        foreach ($matches as $key) {
            $hash = $redis->hGetAll($key);
            if (!\is_array($hash)) {
                continue;
            }

            $log = [];
            foreach ($hash as $field => $value) {
                $log[(string) $field] = \is_scalar($value) ? (string) $value : '';
            }
            $logs[$key] = $log;
        }

        return $logs;
    }

    /**
     * @return list<string>
     */
    private function scan(\Redis|\RedisCluster $redis): array
    {
        $pattern = self::NAMESPACE . '__*';
        $matches = [];

        if ($redis instanceof \Redis) {
            $cursor = null;
            do {
                \array_push($matches, ...self::strings($redis->scan($cursor, $pattern, 100)));
            } while ($cursor > 0);

            return $matches;
        }

        foreach ($redis->_masters() as $master) {
            if (!\is_string($master) && !\is_array($master)) {
                continue;
            }

            $cursor = null;
            do {
                \array_push($matches, ...self::strings($redis->scan($cursor, $master, $pattern, 100)));
            } while ($cursor > 0);
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $keys): array
    {
        return \is_array($keys) ? \array_values(\array_filter($keys, \is_string(...))) : [];
    }

    private function used(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }
}
