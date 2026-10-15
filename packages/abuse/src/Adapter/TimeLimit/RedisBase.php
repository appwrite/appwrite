<?php

namespace Utopia\Abuse\Adapter\TimeLimit;

use Utopia\Abuse\Adapter\TimeLimit;

abstract readonly class RedisBase extends TimeLimit
{
    public const string NAMESPACE = 'abuse';

    /**
     * KEYS[1] window counter. ARGV[1] limit, ARGV[2] ttl seconds. Returns the count before this call.
     */
    protected const string HIT_SCRIPT = <<<'LUA'
        local c = tonumber(redis.call('GET', KEYS[1]) or '0') or 0
        if c >= tonumber(ARGV[1]) then return c end
        redis.call('INCR', KEYS[1])
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        return c
        LUA;

    /**
     * KEYS[1] window counter. ARGV[1] value, ARGV[2] ttl seconds.
     */
    protected const string SET_SCRIPT = <<<'LUA'
        redis.call('SET', KEYS[1], ARGV[1])
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        return 1
        LUA;

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $argv
     */
    abstract protected function eval(string $script, array $keys, array $argv): mixed;

    abstract protected function get(string $key): mixed;

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    protected function hit(string $key, int $window): int
    {
        $used = $this->eval(self::HIT_SCRIPT, [$this->windowKey($key, $window)], [$this->limit, $this->seconds]);

        if (!\is_numeric($used)) {
            throw new \RuntimeException('Redis script failed.');
        }

        return (int) $used;
    }

    #[\Override]
    protected function count(string $key, int $window): int
    {
        $count = $this->get($this->windowKey($key, $window));

        return \is_numeric($count) ? (int) $count : 0;
    }

    #[\Override]
    protected function set(string $key, int $window, int $value): void
    {
        $this->eval(self::SET_SCRIPT, [$this->windowKey($key, $window)], [$value, $this->seconds]);
    }

    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        return true;
    }

    final protected function windowKey(string $key, int $window): string
    {
        return self::NAMESPACE . '__' . $key . '__' . $window;
    }
}
