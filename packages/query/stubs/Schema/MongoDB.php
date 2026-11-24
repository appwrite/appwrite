<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Schema;

use Closure;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\Feature\AnalyzeTable;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\Views;

class MongoDB extends \Utopia\Query\Schema implements Views, Databases, AnalyzeTable
{
    /**
     * @param  Closure(Statement): (array<mixed>|int)  $executor
     */
    public function setExecutor(Closure $executor): static
    {
    }
    #[\Override]
    public function table(string $name): Table\MongoDB
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
    #[\Override]
    public function compileDrop(string $name, bool $ifExists): Statement
    {
    }
    #[\Override]
    public function compileRename(string $from, string $to): Statement
    {
    }
    #[\Override]
    public function compileTruncate(string $name): Statement
    {
    }
    public function dropIndex(string $table, string $name): Statement
    {
    }
    /**
     * @param string[] $columns
     * @param array<string, int> $lengths
     * @param array<string, string> $orders
     * @param array<string, string> $collations
     * @param list<string> $rawColumns
     */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false, string $type = '', string $method = '', string $operatorClass = '', array $lengths = [], array $orders = [], array $collations = [], array $rawColumns = []): Statement
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
    public function analyzeTable(string $table): Statement
    {
    }
}
