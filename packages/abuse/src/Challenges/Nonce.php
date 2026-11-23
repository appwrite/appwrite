<?php

namespace Utopia\Abuse\Challenges;

/**
 * Single-use nonces: the first claim of a nonce succeeds, every later claim
 * fails until it expires. Keys follow the adapter pattern — a pattern such as
 * "pow:{project}" filled by withParams() — and the nonce is appended.
 */
abstract readonly class Nonce
{
    /**
     * @param  string  $key  Key pattern, e.g. "pow:{project}"; placeholders are filled by withParams()
     * @param  array<string, string>  $params
     */
    public function __construct(
        protected string $key,
        protected array $params = [],
    ) {
    }

    /**
     * @param  array<string, string>  $params
     */
    public function withParams(array $params): static
    {
        return clone($this, ['params' => [...$this->params, ...$params]]);
    }

    public function withParam(string $name, string $value): static
    {
        return $this->withParams([$name => $value]);
    }

    public function key(string $nonce): string
    {
        return \strtr($this->key, $this->params) . ':' . $nonce;
    }

    /**
     * True on the first claim of this nonce, false on any later claim. An empty
     * nonce never claims. Store errors are thrown, so the caller decides what an
     * outage means.
     *
     * @param  int  $ttl  How long the nonce stays claimed, in seconds
     *
     * @phpstan-impure
     */
    final public function consume(string $nonce, int $ttl): bool
    {
        if ($nonce === '') {
            return false;
        }

        return $this->claim($this->key($nonce), \max(1, $ttl));
    }

    /**
     * Atomically mark the key claimed for $ttl seconds; true when it was not already.
     */
    abstract protected function claim(string $key, int $ttl): bool;
}
