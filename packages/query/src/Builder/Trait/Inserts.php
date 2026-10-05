<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;

trait Inserts
{
    /** @var string[] */
    protected array $conflictKeys = [];

    /** @var string[] */
    protected array $conflictUpdateColumns = [];

    /** @var array<string, string> */
    protected array $conflictRawSets = [];

    /** @var array<string, list<mixed>> */
    protected array $conflictRawSetBindings = [];

    #[\Override]
    public function into(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Set an alias for the INSERT target table (e.g. INSERT INTO table AS alias).
     * Used by PostgreSQL ON CONFLICT to reference the existing row.
     */
    public function insertAs(string $alias): static
    {
        $this->insertAlias = $alias;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[\Override]
    public function set(array $row): static
    {
        $this->rows[] = $row;

        return $this;
    }

    /**
     * @param  string[]  $keys
     * @param  string[]  $updateColumns
     */
    #[\Override]
    public function onConflict(array $keys, array $updateColumns): static
    {
        $this->conflictKeys = $keys;
        $this->conflictUpdateColumns = $updateColumns;

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    #[\Override]
    public function fromSelect(array $columns, Builder $source): static
    {
        $this->insertSelectColumns = $columns;
        $this->insertSelectSource = $source;

        return $this;
    }

    #[\Override]
    public function insert(): Statement
    {
        $this->bindings = [];
        [$sql, $bindings] = $this->compileInsertBody();
        $this->addBindings($bindings);

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }

    #[\Override]
    public function insertDefaultValues(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        $sql = 'INSERT INTO ' . $this->quote($this->table) . ' DEFAULT VALUES';

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }

    #[\Override]
    public function insertSelect(): Statement
    {
        $this->bindings = [];
        $this->validateTable();

        if ($this->insertSelectSource === null) {
            throw new ValidationException('No SELECT source specified. Call fromSelect() before insertSelect().');
        }

        if (empty($this->insertSelectColumns)) {
            throw new ValidationException('No columns specified. Call fromSelect() with columns before insertSelect().');
        }

        $wrappedColumns = \array_map(
            fn (string $col): string => $this->resolveAndWrap($col),
            $this->insertSelectColumns
        );

        $sourceResult = $this->insertSelectSource->build();

        $sql = 'INSERT INTO ' . $this->quote($this->table)
            . ' (' . \implode(', ', $wrappedColumns) . ')'
            . ' ' . $sourceResult->query;

        $this->addBindings($sourceResult->bindings);

        return new Statement($sql, $this->getBindingValues(), executor: $this->executor);
    }
}
