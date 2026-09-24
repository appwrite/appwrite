<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\ClickHouse\IndexAlgorithm;

/**
 * @template TTable of Table = Table
 */
class Column
{
    public protected(set) bool $isNullable = false;

    public protected(set) mixed $default = null;

    public protected(set) bool $hasDefault = false;

    /**
     * Raw default expression emitted verbatim after `DEFAULT` (e.g. `now()`,
     * `generateUUIDv4()`, `gen_random_uuid()`). Distinct from {@see $default},
     * which is rendered as a quoted literal.
     */
    public protected(set) ?string $defaultRaw = null;

    public protected(set) bool $isUnsigned = false;

    public protected(set) bool $isUnique = false;

    public protected(set) bool $isPrimary = false;

    public protected(set) bool $isAutoIncrement = false;

    public protected(set) ?string $after = null;

    public protected(set) ?string $comment = null;

    /** @var string[] */
    public protected(set) array $enumValues = [];

    public protected(set) ?int $srid = null;

    public protected(set) ?int $dimensions = null;

    public protected(set) bool $isModify = false;

    public protected(set) ?string $collation = null;

    public protected(set) ?string $checkExpression = null;

    public protected(set) ?string $generatedExpression = null;

    /**
     * Null when {@see generatedAs()} has not been called.
     * True = STORED, false = VIRTUAL.
     */
    public protected(set) ?bool $generatedStored = null;

    public protected(set) ?string $ttl = null;

    public protected(set) ?string $userTypeName = null;

    /**
     * $srid, $dimensions and $autoIncrement are intrinsic to the column the way
     * $length is, and are set by the Table factories that create it (point(),
     * vector(), id(), serial()). They are constructor parameters rather than
     * modifier calls so that the factories do not depend on modifier methods
     * that only some dialects expose.
     *
     * @param  TTable  $table
     */
    public function __construct(
        public Table $table,
        public string $name,
        public ColumnType $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        ?int $srid = null,
        ?int $dimensions = null,
        bool $autoIncrement = false,
    ) {
        $this->srid = $srid;
        $this->dimensions = $dimensions;
        $this->isAutoIncrement = $autoIncrement;
    }

    public function nullable(): static
    {
        $this->isNullable = true;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Set a raw default expression rendered verbatim after `DEFAULT`.
     *
     * Use for dialect-specific server-generated defaults that {@see default()}
     * would otherwise quote: `now()`, `CURRENT_TIMESTAMP`, `gen_random_uuid()`,
     * `generateUUIDv4()`, etc. The expression is emitted unquoted and must come
     * from a trusted (developer-controlled) source.
     *
     * @throws ValidationException if the expression is empty or contains ";".
     */
    public function defaultRaw(string $expression): static
    {
        $trimmed = \trim($expression);

        if ($trimmed === '') {
            throw new ValidationException('Raw default expression must not be empty.');
        }

        if (\str_contains($trimmed, ';')) {
            throw new ValidationException('Raw default expression must not contain ";".');
        }

        $this->defaultRaw = $trimmed;

        return $this;
    }

    public function unsigned(): static
    {
        $this->isUnsigned = true;

        return $this;
    }

    /**
     * Mark this column as a primary key. Dialect Column subclasses that
     * support composite primary keys also accept a list of column names to
     * declare a composite key on the parent table.
     */
    public function primary(): static|Table
    {
        $this->isPrimary = true;

        return $this;
    }

    /**
     * Set the allowed values on this enum column (when called with one array
     * argument), or add a new enum column to the parent table (when called
     * with a name and a list of values).
     *
     * @param  string|string[]  $nameOrValues
     * @param  string[]|null    $values
     *
     * @throws ValidationException if the value list is empty.
     */
    public function enum(string|array $nameOrValues, ?array $values = null): static|Column
    {
        if (\is_array($nameOrValues)) {
            if ($nameOrValues === []) {
                throw new ValidationException('enum() requires at least one allowed value.');
            }

            $this->enumValues = $nameOrValues;

            return $this;
        }

        return $this->table->enum($nameOrValues, $values ?? []);
    }

    public function modify(): static
    {
        $this->isModify = true;

        return $this;
    }

    public function id(string $name = 'id'): static
    {
        /** @var static */
        return $this->table->id($name);
    }

    public function string(string $name, int $length = 255): static
    {
        /** @var static */
        return $this->table->string($name, $length);
    }

    public function text(string $name): static
    {
        /** @var static */
        return $this->table->text($name);
    }

    public function mediumText(string $name): static
    {
        /** @var static */
        return $this->table->mediumText($name);
    }

    public function longText(string $name): static
    {
        /** @var static */
        return $this->table->longText($name);
    }

    public function tinyInteger(string $name): static
    {
        /** @var static */
        return $this->table->tinyInteger($name);
    }

    public function smallInteger(string $name): static
    {
        /** @var static */
        return $this->table->smallInteger($name);
    }

    public function integer(string $name): static
    {
        /** @var static */
        return $this->table->integer($name);
    }

    public function bigInteger(string $name): static
    {
        /** @var static */
        return $this->table->bigInteger($name);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 0): static
    {
        /** @var static */
        return $this->table->decimal($name, $precision, $scale);
    }

    public function uuid(string $name): static
    {
        /** @var static */
        return $this->table->uuid($name);
    }

    public function float(string $name): static
    {
        /** @var static */
        return $this->table->float($name);
    }

    public function boolean(string $name): static
    {
        /** @var static */
        return $this->table->boolean($name);
    }

    public function datetime(string $name, int $precision = 0): static
    {
        /** @var static */
        return $this->table->datetime($name, $precision);
    }

    public function timestamp(string $name, int $precision = 0): static
    {
        /** @var static */
        return $this->table->timestamp($name, $precision);
    }

    public function json(string $name): static
    {
        /** @var static */
        return $this->table->json($name);
    }

    public function binary(string $name): static
    {
        /** @var static */
        return $this->table->binary($name);
    }

    public function point(string $name, int $srid = 4326): static
    {
        /** @var static */
        return $this->table->point($name, $srid);
    }

    public function linestring(string $name, int $srid = 4326): static
    {
        /** @var static */
        return $this->table->linestring($name, $srid);
    }

    public function polygon(string $name, int $srid = 4326): static
    {
        /** @var static */
        return $this->table->polygon($name, $srid);
    }

    /** @return TTable */
    public function timestamps(int $precision = 3): Table
    {
        return $this->table->timestamps($precision);
    }

    public function addColumn(string $name, ColumnType $type, ?int $lengthOrPrecision = null): static
    {
        /** @var static */
        return $this->table->addColumn($name, $type, $lengthOrPrecision);
    }

    public function modifyColumn(string $name, ColumnType $type, ?int $lengthOrPrecision = null): static
    {
        /** @var static */
        return $this->table->modifyColumn($name, $type, $lengthOrPrecision);
    }

    /**
     * @param  string[]  $columns
     * @param  array<string, int>  $lengths
     * @param  array<string, string>  $orders
     * @param  array<string, string>  $collations
     * @param  list<string|int|float>  $algorithmArgs  ClickHouse skip-index algorithm args
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
        ?IndexAlgorithm $algorithm = null,
        array $algorithmArgs = [],
        ?int $granularity = null,
    ): Table {
        return $this->table->index(
            $columns,
            $name,
            $method,
            $operatorClass,
            $lengths,
            $orders,
            $collations,
            $algorithm,
            $algorithmArgs,
            $granularity,
        );
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
