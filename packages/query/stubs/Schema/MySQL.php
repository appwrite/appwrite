<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Feature\AnalyzeTable;
use Utopia\Query\Schema\Feature\CreatePartition;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\DropPartition;
use Utopia\Query\Schema\Feature\ForeignKeys;
use Utopia\Query\Schema\Feature\Partitioning;
use Utopia\Query\Schema\Feature\Procedures;
use Utopia\Query\Schema\Feature\RenameIndex;
use Utopia\Query\Schema\Feature\ReplaceView;
use Utopia\Query\Schema\Feature\TableComments;
use Utopia\Query\Schema\Feature\Triggers;
use Utopia\Query\Schema\Feature\Views;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\Order;
use Utopia\Query\Schema\ParameterDirection;
use Utopia\Query\Schema\Table;
use Utopia\Query\Schema\TriggerEvent;
use Utopia\Query\Schema\TriggerTiming;
class MySQL extends \Utopia\Query\Schema\SQL implements ForeignKeys, Procedures, Triggers, TableComments, CreatePartition, DropPartition, Views, ReplaceView, Databases, RenameIndex, AnalyzeTable, Partitioning
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): Table\MySQL
    {
    }
    public function compileCreate(Table $table, bool $ifNotExists = false): Statement
    {
    }
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
    public function addForeignKey(string $table, string $name, string $column, string $refTable, string $refColumn, ?ForeignKeyAction $onDelete = null, ?ForeignKeyAction $onUpdate = null): Statement
    {
    }
    public function dropForeignKey(string $table, string $name): Statement
    {
    }
    /**
     * Create a stored procedure.
     *
     * $body is emitted verbatim into the generated DDL and must come from
     * trusted (developer-controlled) source — never from untrusted input.
     *
     * @param  list<array{0: ParameterDirection, 1: string, 2: string}>  $params
     */
    public function createProcedure(string $name, array $params, string $body): Statement
    {
    }
    public function dropProcedure(string $name): Statement
    {
    }
    /**
     * Create a trigger.
     *
     * $body is emitted verbatim into the generated DDL and must come from
     * trusted (developer-controlled) source — never from untrusted input.
     */
    public function createTrigger(string $name, string $table, TriggerTiming $timing, TriggerEvent $event, string $body): Statement
    {
    }
    public function dropTrigger(string $name, ?string $table = null): Statement
    {
    }
    public function commentOnTable(string $table, string $comment): Statement
    {
    }
    public function createPartition(string $parent, string $name, string $expression): Statement
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
    public function createOrReplaceView(string $name, Builder $query): Statement
    {
    }
    public function createDatabase(string $name): Statement
    {
    }
    public function dropDatabase(string $name): Statement
    {
    }
    public function renameIndex(string $table, string $from, string $to): Statement
    {
    }
    public function analyzeTable(string $table): Statement
    {
    }
    public function compileCreatePartitioning(Table $table): string
    {
    }
    /**
     * MySQL CHANGE COLUMN: rename and/or retype a column in one statement.
     */
    public function changeColumn(string $table, string $oldName, string $newName, string $type): Statement
    {
    }
    /**
     * MySQL MODIFY COLUMN: retype a column without renaming.
     */
    public function modifyColumn(string $table, string $name, string $type): Statement
    {
    }
}
