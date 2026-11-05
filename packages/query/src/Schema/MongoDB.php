<?php

namespace Utopia\Query\Schema;

use stdClass;
use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema;
use Utopia\Query\Schema\Feature\AnalyzeTable;
use Utopia\Query\Schema\Feature\Databases;
use Utopia\Query\Schema\Feature\Views;

class MongoDB extends Schema implements Views, Databases, AnalyzeTable
{
    #[\Override]
    public function table(string $name): Table\MongoDB
    {
        return new Table\MongoDB($this, $name);
    }

    protected function quote(string $identifier): string
    {
        return $identifier;
    }

    protected function quoteLiteral(string $identifier): string
    {
        return $identifier;
    }

    protected function compileColumnType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::String, ColumnType::Varchar, ColumnType::Relationship => 'string',
            ColumnType::Text, ColumnType::MediumText, ColumnType::LongText => 'string',
            ColumnType::TinyInteger, ColumnType::SmallInteger,
            ColumnType::Integer, ColumnType::BigInteger, ColumnType::Id,
            ColumnType::Serial, ColumnType::BigSerial, ColumnType::SmallSerial => 'int',
            ColumnType::Float, ColumnType::Double => 'double',
            ColumnType::Decimal => 'decimal',
            ColumnType::Boolean => 'bool',
            ColumnType::Datetime, ColumnType::Timestamp => 'date',
            ColumnType::Json, ColumnType::Object => 'object',
            ColumnType::Binary => 'binData',
            ColumnType::Enum => 'string',
            ColumnType::Point => 'object',
            ColumnType::Linestring, ColumnType::Polygon => 'object',
            ColumnType::Uuid, ColumnType::Uuid7 => 'string',
            ColumnType::Vector, ColumnType::Array => 'array',
            ColumnType::Tuple => 'array',
        };
    }

    protected function compileAutoIncrement(): string
    {
        return '';
    }

    #[\Override]
    public function compileCreate(Table $table, bool $ifNotExists = false): Statement
    {
        $properties = [];
        $required = [];

        foreach ($table->columns as $column) {
            $bsonType = $this->compileColumnType($column);

            $prop = ['bsonType' => $bsonType];

            if ($column->comment !== null) {
                $prop['description'] = $column->comment;
            }

            if ($column->type === ColumnType::Enum && ! empty($column->enumValues)) {
                $prop['enum'] = $column->enumValues;
            }

            $properties[$column->name] = $prop;

            if (! $column->isNullable && ! $column->hasDefault) {
                $required[] = $column->name;
            }
        }

        $validator = [];
        if (! empty($properties)) {
            $schema = [
                'bsonType' => 'object',
                'properties' => $properties,
            ];
            if (! empty($required)) {
                $schema['required'] = $required;
            }
            $validator = ['$jsonSchema' => $schema];
        }

        $command = [
            'command' => 'createCollection',
            'collection' => $table->name,
        ];

        if (! empty($validator)) {
            $command['validator'] = $validator;
        }

        return new Statement(
            \json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            [],
            executor: $this->executor,
        );
    }

    #[\Override]
    public function compileAlter(Table $table): Statement
    {
        $properties = [];
        $required = [];

        foreach ($table->columns as $column) {
            $bsonType = $this->compileColumnType($column);
            $prop = ['bsonType' => $bsonType];

            if ($column->comment !== null) {
                $prop['description'] = $column->comment;
            }

            $properties[$column->name] = $prop;

            if (! $column->isNullable && ! $column->hasDefault) {
                $required[] = $column->name;
            }
        }

        $validator = [];
        if (! empty($properties)) {
            $schema = [
                'bsonType' => 'object',
                'properties' => $properties,
            ];
            if (! empty($required)) {
                $schema['required'] = $required;
            }
            $validator = ['$jsonSchema' => $schema];
        }

        $command = [
            'command' => 'collMod',
            'collection' => $table->name,
        ];

        if (! empty($validator)) {
            $command['validator'] = $validator;
        }

        return new Statement(
            \json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            [],
            executor: $this->executor,
        );
    }

    #[\Override]
    public function compileDrop(string $name, bool $ifExists): Statement
    {
        return new Statement(
            \json_encode(['command' => 'drop', 'collection' => $name], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    #[\Override]
    public function compileRename(string $from, string $to): Statement
    {
        return new Statement(
            \json_encode([
                'command' => 'renameCollection',
                'from' => $from,
                'to' => $to,
            ], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    #[\Override]
    public function compileTruncate(string $name): Statement
    {
        return new Statement(
            \json_encode([
                'command' => 'deleteMany',
                'collection' => $name,
                'filter' => new stdClass(),
            ], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    /**
     * @param string[] $columns
     * @param array<string, int> $lengths
     * @param array<string, string> $orders
     * @param array<string, string> $collations
     * @param list<string> $rawColumns
     */
    public function createIndex(
        string $table,
        string $name,
        array $columns,
        bool $unique = false,
        string $type = '',
        string $method = '',
        string $operatorClass = '',
        array $lengths = [],
        array $orders = [],
        array $collations = [],
        array $rawColumns = [],
    ): Statement {
        $keys = [];
        foreach ($columns as $col) {
            $direction = 1;
            if (isset($orders[$col])) {
                $direction = \strtolower($orders[$col]) === 'desc' ? -1 : 1;
            }
            $keys[$col] = $direction;
        }

        $index = [
            'key' => $keys,
            'name' => $name,
        ];

        if ($unique) {
            $index['unique'] = true;
        }

        $command = [
            'command' => 'createIndex',
            'collection' => $table,
            'index' => $index,
        ];

        return new Statement(
            \json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            [],
            executor: $this->executor,
        );
    }

    public function dropIndex(string $table, string $name): Statement
    {
        return new Statement(
            \json_encode([
                'command' => 'dropIndex',
                'collection' => $table,
                'index' => $name,
            ], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    public function createView(string $name, Builder $query): Statement
    {
        $result = $query->build();

        /** @var array<string, mixed>|null $op */
        $op = \json_decode($result->query, true);
        if ($op === null) {
            throw new ValidationException('Cannot parse query for MongoDB view creation.');
        }

        $command = [
            'command' => 'createView',
            'view' => $name,
            'source' => $op['collection'] ?? '',
            'pipeline' => $op['pipeline'] ?? [],
        ];

        return new Statement(
            \json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $result->bindings,
            executor: $this->executor,
        );
    }

    public function dropView(string $name): Statement
    {
        return new Statement(
            \json_encode(['command' => 'drop', 'collection' => $name], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    public function createDatabase(string $name): Statement
    {
        return new Statement(
            \json_encode(['command' => 'createDatabase', 'database' => $name], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    public function dropDatabase(string $name): Statement
    {
        return new Statement(
            \json_encode(['command' => 'dropDatabase', 'database' => $name], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }

    public function analyzeTable(string $table): Statement
    {
        return new Statement(
            \json_encode(['command' => 'collStats', 'collection' => $table], JSON_THROW_ON_ERROR),
            [],
            executor: $this->executor,
        );
    }
}
