<?php

namespace Utopia\Abuse;

abstract readonly class Adapter
{
    /**
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

    public function key(): string
    {
        return \strtr($this->key, $this->params);
    }

    /** @phpstan-impure */
    abstract public function check(): Result;

    /** @phpstan-impure */
    abstract public function peek(): Result;

    abstract public function reset(): void;

    /**
     * @return array<mixed>
     */
    abstract public function getLogs(?int $offset = null, ?int $limit = 25): array;

    abstract public function cleanup(int $timestamp): bool;
}
