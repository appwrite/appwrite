<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\Feature\AnalyzeTable;
use Utopia\Query\Schema\Feature\ColumnComments;
use Utopia\Query\Schema\Feature\CreatePartition;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\DropPartition;
use Utopia\Query\Schema\Feature\ForeignKeys;
use Utopia\Query\Schema\Feature\Partitioning;
use Utopia\Query\Schema\Feature\Procedures;
use Utopia\Query\Schema\Feature\RenameIndex;
use Utopia\Query\Schema\Feature\ReplaceView;
use Utopia\Query\Schema\Feature\Sequences;
use Utopia\Query\Schema\Feature\TableComments;
use Utopia\Query\Schema\Feature\Triggers;
use Utopia\Query\Schema\Feature\Types;
use Utopia\Query\Schema\Feature\Views;

class PostgreSQL extends \Utopia\Query\Schema\SQL implements ForeignKeys, Procedures, Triggers, Types, Sequences, TableComments, ColumnComments, CreatePartition, DropPartition, Views, ReplaceView, Databases, RenameIndex, AnalyzeTable, Partitioning
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): Table\PostgreSQL
    {
    }
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
    #[\Override]
    public function compileRename(string $from, string $to): Statement
    {
    }
    public function compileTruncate(string $name): Statement
    {
    }
    public function dropIndex(string $table, string $name): Statement
    {
    }
    public function addForeignKey(string $table, string $name, string $column, string $refTable, string $refColumn, ?ForeignKeyAction $onDelete = null, ?ForeignKeyAction $onUpdate = null): Statement
    {
    }
    public function dropForeignKey(string $table, string $name): Statement
    {
    }
    /**
     * Create a PL/pgSQL function.
     *
     * $body is emitted verbatim inside a dollar-quoted ($$...$$) block and must
     * come from trusted (developer-controlled) source — never from untrusted
     * input. A literal `$$` inside the body would close the dollar-quoted
     * string early and is rejected.
     *
     * @param  list<array{0: ParameterDirection, 1: string, 2: string}>  $params
     *
     * @throws ValidationException if $body contains a `$$` sequence.
     */
    public function createProcedure(string $name, array $params, string $body): Statement
    {
    }
    public function dropProcedure(string $name): Statement
    {
    }
    /**
     * Create a trigger backed by a PL/pgSQL function.
     *
     * $body is emitted verbatim inside a dollar-quoted ($$...$$) block and must
     * come from trusted (developer-controlled) source — never from untrusted
     * input. A literal `$$` inside the body would close the dollar-quoted
     * string early and is rejected.
     *
     * @throws ValidationException if $body contains a `$$` sequence.
     */
    public function createTrigger(string $name, string $table, TriggerTiming $timing, TriggerEvent $event, string $body): Statement
    {
    }
    /**
     * Drop a trigger and its backing PL/pgSQL function. PostgreSQL requires
     * the table name (`DROP TRIGGER name ON table`); the function created by
     * {@see createTrigger()} is dropped at the same time so callers don't need
     * a separate {@see dropFunction()} call.
     *
     * @throws ValidationException if $table is null.
     */
    #[\Override]
    public function dropTrigger(string $name, ?string $table = null): Statement
    {
    }
    public function commentOnTable(string $table, string $comment): Statement
    {
    }
    public function commentOnColumn(string $table, string $column, string $comment): Statement
    {
    }
    /**
     * @throws ValidationException if $expression is too long or contains disallowed sequences.
     */
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
    /**
     * PostgreSQL uses schemas instead of databases for namespace isolation.
     */
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
    /**
     * PostgreSQL accepts `PARTITION BY {RANGE|LIST|HASH} (expr)` but not the
     * `PARTITIONS N` count modifier — that is MySQL-only. Override the shared
     * trait to omit the count.
     */
    #[\Override]
    public function compileCreatePartitioning(Table $table): string
    {
    }
    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false, string $type = '', string $method = '', string $operatorClass = '', array $lengths = [], array $orders = [], array $collations = [], array $rawColumns = [], bool $concurrently = false): Statement
    {
    }
    public function createExtension(string $name): Statement
    {
    }
    public function dropExtension(string $name): Statement
    {
    }
    /**
     * Create a collation.
     *
     * @param  array<string, string>  $options  Key-value pairs (e.g. ['provider' => 'icu', 'locale' => 'und-u-ks-level1'])
     */
    public function createCollation(string $name, array $options, bool $deterministic = true): Statement
    {
    }
    /**
     * Alter a column's type with an optional USING expression for type casting.
     *
     * @throws ValidationException if $type or $using contains disallowed characters.
     */
    public function alterColumnType(string $table, string $column, string $type, string $using = ''): Statement
    {
    }
    /**
     * Alter a column's nullability.
     *
     * Postgres carries NOT NULL through an ALTER COLUMN ... TYPE, so a column
     * that changes between required and optional has to be altered separately.
     */
    public function alterColumnNullable(string $table, string $column, bool $nullable): Statement
    {
    }
    public function dropIndexConcurrently(string $name): Statement
    {
    }
    public function createType(string $name, array $values): Statement
    {
    }
    public function dropType(string $name): Statement
    {
    }
    public function createSequence(string $name, int $start = 1, int $incrementBy = 1): Statement
    {
    }
    public function dropSequence(string $name): Statement
    {
    }
    public function nextVal(string $name): Statement
    {
    }
}
