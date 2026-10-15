<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Builder\Statement;

/**
 * @template TColumn of Column = Column
 * @template TTable of Table<TColumn, covariant ForeignKey> = Table
 */
class ForeignKey
{
    public protected(set) string $refTable = '';

    public protected(set) string $refColumn = '';

    public protected(set) ?ForeignKeyAction $onDelete = null;

    public protected(set) ?ForeignKeyAction $onUpdate = null;

    /**
     * @param  TTable  $table
     */
    public function __construct(
        public readonly Table $table,
        public readonly string $column,
    ) {
    }

    public function references(string $column): static
    {
        $this->refColumn = $column;

        return $this;
    }

    public function on(string $table): static
    {
        $this->refTable = $table;

        return $this;
    }

    public function onDelete(ForeignKeyAction $action): static
    {
        $this->onDelete = $action;

        return $this;
    }

    public function onUpdate(ForeignKeyAction $action): static
    {
        $this->onUpdate = $action;

        return $this;
    }

    /** @return TColumn */
    public function id(string $name = 'id'): Column
    {
        return $this->table->id($name);
    }

    /** @return TColumn */
    public function string(string $name, int $length = 255): Column
    {
        return $this->table->string($name, $length);
    }

    /** @return TColumn */
    public function text(string $name): Column
    {
        return $this->table->text($name);
    }

    /** @return TColumn */
    public function mediumText(string $name): Column
    {
        return $this->table->mediumText($name);
    }

    /** @return TColumn */
    public function longText(string $name): Column
    {
        return $this->table->longText($name);
    }

    /** @return TColumn */
    public function integer(string $name): Column
    {
        return $this->table->integer($name);
    }

    /** @return TColumn */
    public function bigInteger(string $name): Column
    {
        return $this->table->bigInteger($name);
    }

    /** @return TColumn */
    public function float(string $name): Column
    {
        return $this->table->float($name);
    }

    /** @return TColumn */
    public function boolean(string $name): Column
    {
        return $this->table->boolean($name);
    }

    /** @return TColumn */
    public function datetime(string $name, int $precision = 0): Column
    {
        return $this->table->datetime($name, $precision);
    }

    /** @return TColumn */
    public function timestamp(string $name, int $precision = 0): Column
    {
        return $this->table->timestamp($name, $precision);
    }

    /** @return TColumn */
    public function json(string $name): Column
    {
        return $this->table->json($name);
    }

    /** @return TColumn */
    public function binary(string $name): Column
    {
        return $this->table->binary($name);
    }

    /**
     * @param  string[]  $values
     * @return TColumn
     */
    public function enum(string $name, array $values): Column
    {
        return $this->table->enum($name, $values);
    }

    /** @return TColumn */
    public function point(string $name, int $srid = 4326): Column
    {
        return $this->table->point($name, $srid);
    }

    /** @return TColumn */
    public function linestring(string $name, int $srid = 4326): Column
    {
        return $this->table->linestring($name, $srid);
    }

    /** @return TColumn */
    public function polygon(string $name, int $srid = 4326): Column
    {
        return $this->table->polygon($name, $srid);
    }

    /** @return TTable */
    public function timestamps(int $precision = 3): Table
    {
        return $this->table->timestamps($precision);
    }

    /** @return TColumn */
    public function addColumn(string $name, ColumnType $type, ?int $lengthOrPrecision = null): Column
    {
        return $this->table->addColumn($name, $type, $lengthOrPrecision);
    }

    /** @return TColumn */
    public function modifyColumn(string $name, ColumnType $type, ?int $lengthOrPrecision = null): Column
    {
        return $this->table->modifyColumn($name, $type, $lengthOrPrecision);
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @return TTable
     */
    public function index(
        array $columns,
        string $name = '',
        string $method = '',
        string $operatorClass = '',
        array $lengths = [],
        array $orders = [],
        array $collations = [],
    ): Table {
        return $this->table->index($columns, $name, $method, $operatorClass, $lengths, $orders, $collations);
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @return TTable
     */
    public function uniqueIndex(
        array $columns,
        string $name = '',
        array $lengths = [],
        array $orders = [],
        array $collations = [],
    ): Table {
        return $this->table->uniqueIndex($columns, $name, $lengths, $orders, $collations);
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns
     * @return TTable
     */
    public function addIndex(
        string $name,
        array $columns,
        IndexType $type = IndexType::Index,
        array $lengths = [],
        array $orders = [],
        string $method = '',
        string $operatorClass = '',
        array $collations = [],
        array $rawColumns = [],
    ): Table {
        return $this->table->addIndex($name, $columns, $type, $lengths, $orders, $method, $operatorClass, $collations, $rawColumns);
    }

    /** @return TTable */
    public function dropIndex(string $name): Table
    {
        return $this->table->dropIndex($name);
    }

    /** @return TTable */
    public function rawColumn(string $definition): Table
    {
        return $this->table->rawColumn($definition);
    }

    /** @return TTable */
    public function rawIndex(string $definition): Table
    {
        return $this->table->rawIndex($definition);
    }

    public function create(bool $ifNotExists = false): Statement
    {
        return $this->table->create($ifNotExists);
    }

    public function createIfNotExists(): Statement
    {
        return $this->table->createIfNotExists();
    }

    public function alter(): Statement
    {
        return $this->table->alter();
    }

    public function drop(): Statement
    {
        return $this->table->drop();
    }

    public function dropIfExists(): Statement
    {
        return $this->table->dropIfExists();
    }

    public function truncate(): Statement
    {
        return $this->table->truncate();
    }

    public function rename(string $to): Statement
    {
        return $this->table->rename($to);
    }
}
