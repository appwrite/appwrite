<?php

namespace Utopia\Query\Builder\ClickHouse;

use Closure;
use Utopia\Query\Builder\Statement;

readonly class FormattedInsertStatement extends Statement
{
    /**
     * @param  string  $query
     * @param  list<mixed>  $bindings
     * @param  list<string>  $columns
     * @param  string  $format
     * @param  ?string  $body  Serialized payload to ship as the HTTP request body alongside `$query`. Null when only the envelope query was produced (the caller assembles the body separately).
     * @param  bool  $readOnly
     * @param  (Closure(Statement): (array<mixed>|int))|null  $executor
     */
    public function __construct(
        string $query,
        array $bindings,
        public array $columns,
        public string $format,
        public ?string $body = null,
        bool $readOnly = false,
        ?Closure $executor = null,
    ) {
        parent::__construct($query, $bindings, $readOnly, $executor);
    }

    #[\Override]
    public function withExecutor(Closure $executor): self
    {
        return new self(
            $this->query,
            $this->bindings,
            $this->columns,
            $this->format,
            $this->body,
            $this->readOnly,
            $executor,
        );
    }
}
