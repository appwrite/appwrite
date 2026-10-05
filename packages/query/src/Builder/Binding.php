<?php

namespace Utopia\Query\Builder;

/**
 * A single parameter binding captured at bind time, carrying its source
 * column hint alongside the value.
 *
 * The base {@see \Utopia\Query\Builder} stores `$bindings` as `list<Binding>`
 * internally; `Statement::$bindings` stays `list<mixed>` for public
 * consumption via `Builder::getBindingValues()`. Dialects that need typed
 * named placeholders — ClickHouse HTTP, where parameters are passed as
 * `{name:Type}` query-string params — read the column hint to look up a
 * registered type without maintaining a separate parallel array.
 */
readonly class Binding
{
    public function __construct(
        public mixed $value,
        public ?string $column = null,
    ) {
    }
}
