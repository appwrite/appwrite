<?php

namespace Utopia\Abuse\Adapter;

use Utopia\Abuse\Adapter;
use Utopia\Abuse\Result;

abstract readonly class TokenBucket extends Adapter
{
    /**
     * @param  int  $tokens  Bucket capacity; 0 means unlimited
     * @param  float  $refillRate  Tokens refilled per second
     */
    public function __construct(
        string $key,
        protected int $tokens,
        protected float $refillRate,
    ) {
        if ($tokens > 0 && $refillRate <= 0) {
            throw new \InvalidArgumentException('refillRate must be greater than 0');
        }

        parent::__construct($key);
    }

    abstract protected function hit(string $key, float $now): int;

    abstract protected function count(string $key, float $now): int;

    abstract protected function clear(string $key): void;

    protected function now(): float
    {
        return \microtime(true);
    }

    #[\Override]
    final public function check(): Result
    {
        $now = $this->now();

        if ($this->tokens === 0) {
            return new Result(false, 0, 0, (int) $now);
        }

        return $this->result($this->hit($this->key(), $now), $now);
    }

    #[\Override]
    final public function peek(): Result
    {
        $now = $this->now();

        if ($this->tokens === 0) {
            return new Result(false, 0, 0, (int) $now);
        }

        return $this->result($this->count($this->key(), $now), $now);
    }

    #[\Override]
    final public function reset(): void
    {
        $this->clear($this->key());
    }

    private function result(int $used, float $now): Result
    {
        $remaining = \max(0, $this->tokens - $used - 1);

        return new Result(
            $used >= $this->tokens,
            $this->tokens,
            $remaining,
            (int) \ceil($now + ($this->tokens - $remaining) / $this->refillRate),
        );
    }
}
