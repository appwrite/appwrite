<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder\Statement;

class SQLite extends \Utopia\Query\Schema\SQL implements \Utopia\Query\Schema\Feature\Views
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(\Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): \Utopia\Query\Schema\Table\SQLite
    {
    }
    /**
     * SQLite emits its primary key inline when paired with `AUTOINCREMENT`, so
     * suppress the redundant trailing `PRIMARY KEY (col)` constraint that the
     * base compiler would otherwise add.
     */
    #[\Override]
    public function compileCreate(\Utopia\Query\Schema\Table $table, bool $ifNotExists = false): \Utopia\Query\Builder\Statement
    {
    }
    public function compileAlter(\Utopia\Query\Schema\Table $table): \Utopia\Query\Builder\Statement
    {
    }
    public function compileDrop(string $name, bool $ifExists): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function compileRename(string $from, string $to): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function compileTruncate(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function dropIndex(string $table, string $name): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns  Raw SQL expressions appended to column list (bypass quoting)
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false, string $type = '', string $method = '', string $operatorClass = '', array $lengths = [], array $orders = [], array $collations = [], array $rawColumns = []): \Utopia\Query\Builder\Statement
    {
    }
    public function createView(string $name, \Utopia\Query\Builder $query): \Utopia\Query\Builder\Statement
    {
    }
    public function dropView(string $name): \Utopia\Query\Builder\Statement
    {
    }
}
