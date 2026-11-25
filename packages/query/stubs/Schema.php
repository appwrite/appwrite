<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query;

use Closure;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Table;

/**
 * DDL takes no bound parameters, so string literals (comments, defaults, enum
 * values) are inlined through the dialect's {@see StringLiteral}.
 */
abstract class Schema
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    abstract public function setExecutor(\Closure $executor): static;
    /**
     * Begin a fluent table builder. Terminal methods on the returned {@see Table}
     * (`create()`, `alter()`, `drop()`, `dropIfExists()`, `truncate()`, `rename()`)
     * compile and return the final {@see Statement}.
     *
     * Each dialect overrides this to return the dialect-specific {@see Table}
     * subclass exposing only methods supported by that dialect.
     */
    abstract public function table(string $name): \Utopia\Query\Schema\Table;
    abstract public function compileCreate(\Utopia\Query\Schema\Table $table, bool $ifNotExists = false): \Utopia\Query\Builder\Statement;
    abstract public function compileAlter(\Utopia\Query\Schema\Table $table): \Utopia\Query\Builder\Statement;
    abstract public function compileDrop(string $name, bool $ifExists): \Utopia\Query\Builder\Statement;
    abstract public function compileRename(string $from, string $to): \Utopia\Query\Builder\Statement;
    abstract public function compileTruncate(string $name): \Utopia\Query\Builder\Statement;
    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns  Raw SQL expressions appended to column list (bypass quoting)
     */
    abstract public function createIndex(string $table, string $name, array $columns, bool $unique = false, string $type = '', string $method = '', string $operatorClass = '', array $lengths = [], array $orders = [], array $collations = [], array $rawColumns = []): \Utopia\Query\Builder\Statement;
    abstract public function dropIndex(string $table, string $name): \Utopia\Query\Builder\Statement;
}
