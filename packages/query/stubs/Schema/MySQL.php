<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder\Statement;

class MySQL extends \Utopia\Query\Schema\SQL implements \Utopia\Query\Schema\Feature\ForeignKeys, \Utopia\Query\Schema\Feature\Procedures, \Utopia\Query\Schema\Feature\Triggers, \Utopia\Query\Schema\Feature\TableComments, \Utopia\Query\Schema\Feature\CreatePartition, \Utopia\Query\Schema\Feature\DropPartition, \Utopia\Query\Schema\Feature\Views, \Utopia\Query\Schema\Feature\ReplaceView, \Utopia\Query\Schema\Feature\Databases, \Utopia\Query\Schema\Feature\RenameIndex, \Utopia\Query\Schema\Feature\AnalyzeTable, \Utopia\Query\Schema\Feature\Partitioning
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(\Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): \Utopia\Query\Schema\Table\MySQL
    {
    }
    public function compileCreate(\Utopia\Query\Schema\Table $table, bool $ifNotExists = false): \Utopia\Query\Builder\Statement
    {
    }
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
    public function addForeignKey(string $table, string $name, string $column, string $refTable, string $refColumn, ?\Utopia\Query\Schema\ForeignKeyAction $onDelete = null, ?\Utopia\Query\Schema\ForeignKeyAction $onUpdate = null): \Utopia\Query\Builder\Statement
    {
    }
    public function dropForeignKey(string $table, string $name): \Utopia\Query\Builder\Statement
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
    public function createProcedure(string $name, array $params, string $body): \Utopia\Query\Builder\Statement
    {
    }
    public function dropProcedure(string $name): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * Create a trigger.
     *
     * $body is emitted verbatim into the generated DDL and must come from
     * trusted (developer-controlled) source — never from untrusted input.
     */
    public function createTrigger(string $name, string $table, \Utopia\Query\Schema\TriggerTiming $timing, \Utopia\Query\Schema\TriggerEvent $event, string $body): \Utopia\Query\Builder\Statement
    {
    }
    public function dropTrigger(string $name, ?string $table = null): \Utopia\Query\Builder\Statement
    {
    }
    public function commentOnTable(string $table, string $comment): \Utopia\Query\Builder\Statement
    {
    }
    public function createPartition(string $parent, string $name, string $expression): \Utopia\Query\Builder\Statement
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
    public function createOrReplaceView(string $name, \Utopia\Query\Builder $query): \Utopia\Query\Builder\Statement
    {
    }
    public function createDatabase(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function dropDatabase(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function renameIndex(string $table, string $from, string $to): \Utopia\Query\Builder\Statement
    {
    }
    public function analyzeTable(string $table): \Utopia\Query\Builder\Statement
    {
    }
    public function compileCreatePartitioning(\Utopia\Query\Schema\Table $table): string
    {
    }
    /**
     * MySQL CHANGE COLUMN: rename and/or retype a column in one statement.
     */
    public function changeColumn(string $table, string $oldName, string $newName, string $type): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * MySQL MODIFY COLUMN: retype a column without renaming.
     */
    public function modifyColumn(string $table, string $name, string $type): \Utopia\Query\Builder\Statement
    {
    }
}
