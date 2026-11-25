<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder\Statement;

class ClickHouse extends \Utopia\Query\Schema implements \Utopia\Query\Schema\Feature\TableComments, \Utopia\Query\Schema\Feature\ColumnComments, \Utopia\Query\Schema\Feature\DropPartition, \Utopia\Query\Schema\Feature\Views, \Utopia\Query\Schema\Feature\Databases, \Utopia\Query\Schema\Feature\MaterializedViews
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(\Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): \Utopia\Query\Schema\Table\ClickHouse
    {
    }
    #[\Override]
    public function compileCreate(\Utopia\Query\Schema\Table $table, bool $ifNotExists = false): \Utopia\Query\Builder\Statement
    {
    }
    #[\Override]
    public function compileAlter(\Utopia\Query\Schema\Table $table): \Utopia\Query\Builder\Statement
    {
    }
    public function compileDrop(string $name, bool $ifExists): \Utopia\Query\Builder\Statement
    {
    }
    public function compileRename(string $from, string $to): \Utopia\Query\Builder\Statement
    {
    }
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
    public function commentOnTable(string $table, string $comment): \Utopia\Query\Builder\Statement
    {
    }
    public function commentOnColumn(string $table, string $column, string $comment): \Utopia\Query\Builder\Statement
    {
    }
    public function dropPartition(string $table, string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function createView(string $name, \Utopia\Query\Builder $query): \Utopia\Query\Builder\Statement
    {
    }
    public function dropView(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function createDatabase(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function dropDatabase(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function createMaterializedView(string $name, \Utopia\Query\Builder|string $body, ?string $targetTable = null, bool $ifNotExists = true): \Utopia\Query\Builder\Statement
    {
    }
    public function dropMaterializedView(string $name, bool $ifExists = true): \Utopia\Query\Builder\Statement
    {
    }
}
