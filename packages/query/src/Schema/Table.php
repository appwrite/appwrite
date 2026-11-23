<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema;
use Utopia\Query\Schema\ClickHouse\Engine;

/**
 * @template TColumn of Column = Column
 * @template TForeignKey of ForeignKey = ForeignKey
 */
class Table
{
    /** @var list<TColumn> */
    public protected(set) array $columns = [];

    /** @var list<Index> */
    public protected(set) array $indexes = [];

    /** @var list<TForeignKey> */
    public protected(set) array $foreignKeys = [];

    /** @var list<string> */
    public protected(set) array $dropColumns = [];

    /** @var list<RenameColumn> */
    public protected(set) array $renameColumns = [];

    /** @var list<string> */
    public protected(set) array $dropIndexes = [];

    /** @var list<string> */
    public protected(set) array $dropForeignKeys = [];

    /** @var list<string> Raw SQL column definitions (bypass typed Column objects) */
    public protected(set) array $rawColumnDefs = [];

    /** @var list<string> Raw SQL index definitions (bypass typed Index objects) */
    public protected(set) array $rawIndexDefs = [];

    /** @var list<CheckConstraint> */
    public protected(set) array $checks = [];

    /** @var list<string> */
    public protected(set) array $compositePrimaryKey = [];

    /** @var list<string> ClickHouse ORDER BY columns; falls back to primary key when empty */
    public protected(set) array $orderBy = [];

    public protected(set) ?PartitionType $partitionType = null;
    public protected(set) string $partitionExpression = '';
    public protected(set) ?int $partitionCount = null;

    public protected(set) ?Engine $engine = null;

    /** @var list<string> */
    public protected(set) array $engineArgs = [];

    public protected(set) ?string $ttl = null;

    /** @var array<string, string> Table-level engine SETTINGS (ClickHouse only) */
    public protected(set) array $settings = [];

    public function __construct(
        private readonly ?Schema $schema = null,
        public readonly string $name = '',
    ) {
    }

    public function create(bool $ifNotExists = false): Statement
    {
        return $this->requireSchema()->compileCreate($this, $ifNotExists);
    }

    public function createIfNotExists(): Statement
    {
        return $this->create(true);
    }

    public function alter(): Statement
    {
        return $this->requireSchema()->compileAlter($this);
    }

    public function drop(): Statement
    {
        return $this->requireSchema()->compileDrop($this->name, false);
    }

    public function dropIfExists(): Statement
    {
        return $this->requireSchema()->compileDrop($this->name, true);
    }

    public function truncate(): Statement
    {
        return $this->requireSchema()->compileTruncate($this->name);
    }

    public function rename(string $to): Statement
    {
        return $this->requireSchema()->compileRename($this->name, $to);
    }

    private function requireSchema(): Schema
    {
        if ($this->schema === null) {
            throw new ValidationException('Cannot compile a Table without a Schema. Use Schema::table($name) to obtain a builder.');
        }

        return $this->schema;
    }

    /**
     * Construct a Column instance. Dialect Table subclasses override to
     * construct their dialect-specific {@see Column} subclass.
     *
     * @return TColumn
     */
    protected function newColumn(string $name, ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null, ?int $srid = null, ?int $dimensions = null, bool $autoIncrement = false): Column
    {
        /** @var TColumn */
        return new Column($this, $name, $type, $length, $precision, $scale, $srid, $dimensions, $autoIncrement);
    }

    /**
     * Construct a ForeignKey instance. Dialect Table subclasses that support
     * foreign keys override to construct their dialect-specific
     * {@see ForeignKey} subclass.
     *
     * @return TForeignKey
     */
    protected function newForeignKey(string $column): ForeignKey
    {
        /** @var TForeignKey */
        return new ForeignKey($this, $column);
    }

    /** @return TColumn */
    public function id(string $name = 'id'): Column
    {
        $col = $this->newColumn($name, ColumnType::BigInteger, autoIncrement: true);
        $col->unsigned();
        $col->primary();
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function string(string $name, int $length = 255): Column
    {
        $col = $this->newColumn($name, ColumnType::String, $length);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function text(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Text);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function mediumText(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::MediumText);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function longText(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::LongText);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function tinyInteger(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::TinyInteger);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function smallInteger(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::SmallInteger);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function integer(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Integer);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function bigInteger(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::BigInteger);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Add a fixed-point `DECIMAL(precision, scale)` column.
     *
     * Default precision/scale matches MySQL's documented defaults (10, 0). For
     * monetary values prefer an explicit precision and scale, e.g.
     * `decimal('amount', precision: 18, scale: 3)`.
     *
     * @throws ValidationException if precision is less than 1 or scale is negative.
     *
     * @return TColumn
     */
    public function decimal(string $name, int $precision = 10, int $scale = 0): Column
    {
        if ($precision < 1) {
            throw new ValidationException('DECIMAL precision must be at least 1.');
        }

        if ($scale < 0) {
            throw new ValidationException('DECIMAL scale must not be negative.');
        }

        if ($scale > $precision) {
            throw new ValidationException('DECIMAL scale must not exceed precision.');
        }

        $col = $this->newColumn($name, ColumnType::Decimal, precision: $precision, scale: $scale);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Add a UUID column. Compiled to the dialect's native UUID type when one
     * exists (`UUID` on ClickHouse and PostgreSQL) and to a 36-character
     * string column otherwise (`CHAR(36)` on MySQL, `TEXT` on SQLite, `string`
     * on MongoDB).
     *
     * Pair with {@see Column::defaultRaw()} for server-generated defaults such
     * as `generateUUIDv4()` (ClickHouse) or `gen_random_uuid()` (PostgreSQL).
     *
     * @return TColumn
     */
    public function uuid(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Uuid);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function float(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Float);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function boolean(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Boolean);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function datetime(string $name, int $precision = 0): Column
    {
        $col = $this->newColumn($name, ColumnType::Datetime, precision: $precision);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function timestamp(string $name, int $precision = 0): Column
    {
        $col = $this->newColumn($name, ColumnType::Timestamp, precision: $precision);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function json(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Json);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function binary(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Binary);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * @param  string[]  $values
     * @return TColumn
     *
     * @throws ValidationException if the value list is empty.
     */
    public function enum(string $name, array $values): Column
    {
        if ($values === []) {
            throw new ValidationException('enum() requires at least one allowed value.');
        }

        $col = $this->newColumn($name, ColumnType::Enum);
        $col->enum($values);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function point(string $name, int $srid = 4326): Column
    {
        $col = $this->newColumn($name, ColumnType::Point, srid: $srid);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function linestring(string $name, int $srid = 4326): Column
    {
        $col = $this->newColumn($name, ColumnType::Linestring, srid: $srid);
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function polygon(string $name, int $srid = 4326): Column
    {
        $col = $this->newColumn($name, ColumnType::Polygon, srid: $srid);
        $this->columns[] = $col;

        return $col;
    }

    public function timestamps(int $precision = 3): static
    {
        $this->datetime('created_at', $precision);
        $this->datetime('updated_at', $precision);

        return $this;
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string|int|float>  $algorithmArgs  ClickHouse skip-index algorithm args
     */
    public function index(
        array $columns,
        string $name = '',
        string $method = '',
        string $operatorClass = '',
        array $lengths = [],
        array $orders = [],
        array $collations = [],
        ?ClickHouse\IndexAlgorithm $algorithm = null,
        array $algorithmArgs = [],
        ?int $granularity = null,
    ): static {
        if ($name === '') {
            $name = $this->autoIndexName('idx_', $columns);
        }
        $this->indexes[] = new Index(
            $name,
            $columns,
            IndexType::Index,
            $lengths,
            $orders,
            $method,
            $operatorClass,
            $collations,
            algorithm: $algorithm,
            algorithmArgs: $algorithmArgs,
            granularity: $granularity,
        );

        return $this;
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     */
    public function uniqueIndex(
        array $columns,
        string $name = '',
        array $lengths = [],
        array $orders = [],
        array $collations = [],
    ): static {
        if ($name === '') {
            $name = $this->autoIndexName('uniq_', $columns);
        }
        $this->indexes[] = new Index($name, $columns, IndexType::Unique, $lengths, $orders, collations: $collations);

        return $this;
    }

    /** @return TColumn */
    public function addColumn(string $name, ColumnType $type, ?int $lengthOrPrecision = null): Column
    {
        $col = $this->newColumn(
            $name,
            $type,
            $type === ColumnType::String ? $lengthOrPrecision : null,
            $type !== ColumnType::String ? $lengthOrPrecision : null,
        );
        $this->columns[] = $col;

        return $col;
    }

    /** @return TColumn */
    public function modifyColumn(string $name, ColumnType $type, ?int $lengthOrPrecision = null): Column
    {
        $col = $this->newColumn(
            $name,
            $type,
            $type === ColumnType::String ? $lengthOrPrecision : null,
            $type !== ColumnType::String ? $lengthOrPrecision : null,
        );
        $col->modify();
        $this->columns[] = $col;

        return $col;
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string>  $rawColumns  Raw SQL expressions appended to column list (bypass quoting)
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
    ): static {
        $this->indexes[] = new Index($name, $columns, $type, $lengths, $orders, $method, $operatorClass, $collations, $rawColumns);

        return $this;
    }

    public function dropIndex(string $name): static
    {
        $this->dropIndexes[] = $name;

        return $this;
    }

    /**
     * Add a raw SQL column definition (bypass typed Column objects).
     *
     * Example: $table->rawColumn('`my_col` VARCHAR(255) NOT NULL DEFAULT ""')
     */
    public function rawColumn(string $definition): static
    {
        $this->rawColumnDefs[] = $definition;

        return $this;
    }

    /**
     * Add a raw SQL index definition (bypass typed Index objects).
     *
     * Example: $table->rawIndex('INDEX `idx_name` (`col1`, `col2`)')
     */
    public function rawIndex(string $definition): static
    {
        $this->rawIndexDefs[] = $definition;

        return $this;
    }

    /**
     * Build an auto-generated index name with a prefix, sanitising any
     * non-identifier characters in the column names so the result is always a
     * valid SQL identifier.
     *
     * @param  string[]  $columns
     */
    protected function autoIndexName(string $prefix, array $columns): string
    {
        $sanitised = \array_map(
            fn (string $c): string => \preg_replace('/[^A-Za-z0-9_]+/', '_', $c) ?? $c,
            $columns,
        );

        return $prefix . \implode('_', $sanitised);
    }
}
