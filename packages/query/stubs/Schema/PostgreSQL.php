<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\Sequences;

class PostgreSQL extends \Utopia\Query\Schema\SQL implements \Utopia\Query\Schema\Feature\ForeignKeys, \Utopia\Query\Schema\Feature\Procedures, \Utopia\Query\Schema\Feature\Triggers, \Utopia\Query\Schema\Feature\Types, \Utopia\Query\Schema\Feature\Sequences, \Utopia\Query\Schema\Feature\TableComments, \Utopia\Query\Schema\Feature\ColumnComments, \Utopia\Query\Schema\Feature\CreatePartition, \Utopia\Query\Schema\Feature\DropPartition, \Utopia\Query\Schema\Feature\Views, \Utopia\Query\Schema\Feature\ReplaceView, \Utopia\Query\Schema\Feature\Databases, \Utopia\Query\Schema\Feature\RenameIndex, \Utopia\Query\Schema\Feature\AnalyzeTable, \Utopia\Query\Schema\Feature\Partitioning
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(\Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): \Utopia\Query\Schema\Table\PostgreSQL
    {
    }
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
    #[\Override]
    public function compileRename(string $from, string $to): \Utopia\Query\Builder\Statement
    {
    }
    public function compileTruncate(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function dropIndex(string $table, string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function addForeignKey(string $table, string $name, string $column, string $refTable, string $refColumn, ?\Utopia\Query\Schema\ForeignKeyAction $onDelete = null, ?\Utopia\Query\Schema\ForeignKeyAction $onUpdate = null): \Utopia\Query\Builder\Statement
    {
    }
    public function dropForeignKey(string $table, string $name): \Utopia\Query\Builder\Statement
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
    public function createProcedure(string $name, array $params, string $body): \Utopia\Query\Builder\Statement
    {
    }
    public function dropProcedure(string $name): \Utopia\Query\Builder\Statement
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
    public function createTrigger(string $name, string $table, \Utopia\Query\Schema\TriggerTiming $timing, \Utopia\Query\Schema\TriggerEvent $event, string $body): \Utopia\Query\Builder\Statement
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
    public function dropTrigger(string $name, ?string $table = null): \Utopia\Query\Builder\Statement
    {
    }
    public function commentOnTable(string $table, string $comment): \Utopia\Query\Builder\Statement
    {
    }
    public function commentOnColumn(string $table, string $column, string $comment): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * @throws ValidationException if $expression is too long or contains disallowed sequences.
     */
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
    /**
     * PostgreSQL uses schemas instead of databases for namespace isolation.
     */
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
    /**
     * PostgreSQL accepts `PARTITION BY {RANGE|LIST|HASH} (expr)` but not the
     * `PARTITIONS N` count modifier — that is MySQL-only. Override the shared
     * trait to omit the count.
     */
    #[\Override]
    public function compileCreatePartitioning(\Utopia\Query\Schema\Table $table): string
    {
    }
    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false, string $type = '', string $method = '', string $operatorClass = '', array $lengths = [], array $orders = [], array $collations = [], array $rawColumns = [], bool $concurrently = false): \Utopia\Query\Builder\Statement
    {
    }
    public function createExtension(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function dropExtension(string $name): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * Create a collation.
     *
     * @param  array<string, string>  $options  Key-value pairs (e.g. ['provider' => 'icu', 'locale' => 'und-u-ks-level1'])
     */
    public function createCollation(string $name, array $options, bool $deterministic = true): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * Alter a column's type with an optional USING expression for type casting.
     *
     * @throws ValidationException if $type or $using contains disallowed characters.
     */
    public function alterColumnType(string $table, string $column, string $type, string $using = ''): \Utopia\Query\Builder\Statement
    {
    }
    /**
     * Alter a column's nullability.
     *
     * Postgres carries NOT NULL through an ALTER COLUMN ... TYPE, so a column
     * that changes between required and optional has to be altered separately.
     */
    public function alterColumnNullable(string $table, string $column, bool $nullable): \Utopia\Query\Builder\Statement
    {
    }
    public function dropIndexConcurrently(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function createType(string $name, array $values): \Utopia\Query\Builder\Statement
    {
    }
    public function dropType(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function createSequence(string $name, int $start = 1, int $incrementBy = 1): \Utopia\Query\Builder\Statement
    {
    }
    public function dropSequence(string $name): \Utopia\Query\Builder\Statement
    {
    }
    public function nextVal(string $name): \Utopia\Query\Builder\Statement
    {
    }
}
