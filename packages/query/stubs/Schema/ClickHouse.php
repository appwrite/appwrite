<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\QuotesIdentifiers;
use Utopia\Query\Schema;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Feature\ColumnComments;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\DropPartition;
use Utopia\Query\Schema\Feature\MaterializedViews;
use Utopia\Query\Schema\Feature\TableComments;
use Utopia\Query\Schema\Feature\Views;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\Order;
use Utopia\Query\Schema\Table;
use Utopia\Query\StringLiteral;
class ClickHouse extends \Utopia\Query\Schema implements TableComments, ColumnComments, DropPartition, Views, Databases, MaterializedViews
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): Table\ClickHouse
    {
    }
    #[\Override]
    public function compileCreate(Table $table, bool $ifNotExists = false): Statement
    {
    }
    #[\Override]
    public function compileAlter(Table $table): Statement
    {
    }
    public function compileDrop(string $name, bool $ifExists): Statement
    {
    }
    public function compileRename(string $from, string $to): Statement
    {
    }
    public function compileTruncate(string $name): Statement
    {
    }
    public function dropIndex(string $table, string $name): Statement
    {
    }
    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns  Raw SQL expressions appended to column list (bypass quoting)
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false, string $type = '', string $method = '', string $operatorClass = '', array $lengths = [], array $orders = [], array $collations = [], array $rawColumns = []): Statement
    {
    }
    public function commentOnTable(string $table, string $comment): Statement
    {
    }
    public function commentOnColumn(string $table, string $column, string $comment): Statement
    {
    }
    public function dropPartition(string $table, string $name): Statement
    {
    }
    public function createView(string $name, Builder $query): Statement
    {
    }
    public function dropView(string $name): Statement
    {
    }
    public function createDatabase(string $name): Statement
    {
    }
    public function dropDatabase(string $name): Statement
    {
    }
    public function createMaterializedView(string $name, Builder|string $body, ?string $targetTable = null, bool $ifNotExists = true): Statement
    {
    }
    public function dropMaterializedView(string $name, bool $ifExists = true): Statement
    {
    }
}
