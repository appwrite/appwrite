<?php

namespace Utopia\Query\Builder;

readonly class Statement
{
    /**
     * @param  list<mixed>  $bindings
     * @param  array<string, mixed>|null  $namedBindings
     * @param  (\Closure(Statement): (array<mixed>|int))|null  $executor
     */
    public function __construct(
        public string $query,
        public array $bindings,
        public bool $readOnly = false,
        private ?\Closure $executor = null,
        public ?array $namedBindings = null,
    ) {
    }

    /**
     * @return array<mixed>|int
     */
    public function execute(): array|int
    {
        if ($this->executor === null) {
            throw new \BadMethodCallException('No executor configured on this plan');
        }

        return ($this->executor)($this);
    }

    public function withExecutor(\Closure $executor): self
    {
        return new self($this->query, $this->bindings, $this->readOnly, $executor, $this->namedBindings);
    }
}
