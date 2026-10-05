<?php

namespace Utopia\Abuse\Adapter\SlidingWindow;

use Utopia\Abuse\Adapter\SlidingWindow;

abstract readonly class RedisBase extends SlidingWindow
{
    public const string NAMESPACE = 'abuse';

    protected const string LIMIT_CHECK_SCRIPT = <<<'LUA'
        local current_key = KEYS[1]
        local previous_key = KEYS[2]
        local max_requests = tonumber(ARGV[1])
        local elapsed = tonumber(ARGV[2])
        local ttl = tonumber(ARGV[3])

        local previous_count = tonumber(redis.call('GET', previous_key) or '0') or 0
        local current_count = tonumber(redis.call('GET', current_key) or '0') or 0

        local estimated = previous_count * (1 - elapsed) + current_count

        if estimated < max_requests then
          redis.call('INCR', current_key)
          redis.call('EXPIRE', current_key, ttl)
        end

        return math.floor(estimated)
        LUA;

    /**
     * @param  int  $ttl  Bucket lifetime in seconds; at least twice the window so the previous bucket outlives the current window
     */
    public function __construct(
        string $key,
        int $limit,
        int $windowSize,
        protected int $ttl,
    ) {
        parent::__construct($key, $limit, $windowSize);

        if ($ttl < $windowSize * 2) {
            throw new \InvalidArgumentException('ttl must be at least twice the windowSize so the previous window bucket outlives the current window');
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     */
    abstract protected function eval(string $script, array $keys, array $argv): mixed;

    abstract protected function get(string $key): mixed;

    abstract protected function delete(string ...$keys): void;

    protected function bucketKey(string $key, int $window): string
    {
        return self::NAMESPACE . '__{' . $key . '}__' . $window;
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    protected function hit(string $key, int $window, float $elapsed): int
    {
        $used = $this->eval(
            self::LIMIT_CHECK_SCRIPT,
            [
                $this->bucketKey($key, $window),
                $this->bucketKey($key, $window - $this->windowSize),
            ],
            [
                $this->limit,
                $elapsed,
                $this->ttl,
            ],
        );

        if (!\is_numeric($used)) {
            throw new \RuntimeException('Redis script failed.');
        }

        return (int) $used;
    }

    #[\Override]
    protected function count(string $key, int $window, float $elapsed): int
    {
        $current = $this->get($this->bucketKey($key, $window));
        $previous = $this->get($this->bucketKey($key, $window - $this->windowSize));

        $current = \is_numeric($current) ? (int) $current : 0;
        $previous = \is_numeric($previous) ? (int) $previous : 0;

        return (int) \floor($current + $previous * (1 - $elapsed));
    }

    #[\Override]
    protected function clear(string $key, int $window): void
    {
        $this->delete(
            $this->bucketKey($key, $window),
            $this->bucketKey($key, $window - $this->windowSize),
        );
    }

    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
