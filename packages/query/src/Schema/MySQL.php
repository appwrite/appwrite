<?php

namespace Utopia\Query\Schema;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\UnsupportedException;
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

class MySQL extends SQL implements
    ForeignKeys,
    Procedures,
    Triggers,
    TableComments,
    CreatePartition,
    DropPartition,
    Views,
    ReplaceView,
    Databases,
    RenameIndex,
    AnalyzeTable,
    Partitioning
{
    use Trait\AnalyzeTable;
    use Trait\Databases;
    use Trait\ForeignKeys;
    use Trait\Partitioning;
    use Trait\Procedures;
    use Trait\RenameIndex;
    use Trait\ReplaceView;
    use Trait\Triggers;
    use Trait\Views;

    #[\Override]
    public function table(string $name): Table\MySQL
    {
        return new Table\MySQL($this, $name);
    }

    protected function compileColumnType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::String, ColumnType::Varchar, ColumnType::Relationship => 'VARCHAR(' . ($column->length ?? 255) . ')',
            ColumnType::Text => 'TEXT',
            ColumnType::MediumText => 'MEDIUMTEXT',
            ColumnType::LongText => 'LONGTEXT',
            ColumnType::TinyInteger => 'TINYINT',
            ColumnType::SmallInteger, ColumnType::SmallSerial => 'SMALLINT',
            ColumnType::Integer, ColumnType::Serial => 'INT',
            ColumnType::BigInteger, ColumnType::Id, ColumnType::BigSerial => 'BIGINT',
            ColumnType::Float, ColumnType::Double => 'DOUBLE',
            ColumnType::Decimal => 'DECIMAL(' . ($column->precision ?? 10) . ', ' . ($column->scale ?? 0) . ')',
            ColumnType::Boolean => 'TINYINT(1)',
            ColumnType::Datetime => $column->precision ? 'DATETIME(' . $column->precision . ')' : 'DATETIME',
            ColumnType::Timestamp => $column->precision ? 'TIMESTAMP(' . $column->precision . ')' : 'TIMESTAMP',
            ColumnType::Json, ColumnType::Object => 'JSON',
            ColumnType::Binary => 'BLOB',
            ColumnType::Enum => "ENUM('" . \implode("','", \array_map(fn ($v) => \str_replace(['\\', "'"], ['\\\\', "''"], $v), $column->enumValues)) . "')",
            ColumnType::Point => 'POINT' . ($column->srid !== null ? ' SRID ' . $column->srid : ''),
            ColumnType::Linestring => 'LINESTRING' . ($column->srid !== null ? ' SRID ' . $column->srid : ''),
            ColumnType::Polygon => 'POLYGON' . ($column->srid !== null ? ' SRID ' . $column->srid : ''),
            ColumnType::Uuid => 'CHAR(36)',
            ColumnType::Uuid7 => 'VARCHAR(36)',
            ColumnType::Vector => throw new UnsupportedException('Vector type is not supported in MySQL.'),
            ColumnType::Array, ColumnType::Tuple => throw new UnsupportedException('Array/Tuple column types are not supported in MySQL.'),
        };
    }

    protected function compileAutoIncrement(): string
    {
        return 'AUTO_INCREMENT';
    }

    public function createDatabase(string $name): Statement
    {
        return new Statement(
            'CREATE DATABASE ' . $this->quote($name) . ' /*!40100 DEFAULT CHARACTER SET utf8mb4 */',
            [],
            executor: $this->executor,
        );
    }

    /**
     * MySQL CHANGE COLUMN: rename and/or retype a column in one statement.
     */
    public function changeColumn(string $table, string $oldName, string $newName, string $type): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table)
            . ' CHANGE COLUMN ' . $this->quoteLiteral($oldName) . ' ' . $this->quoteLiteral($newName) . ' ' . $type,
            [],
            executor: $this->executor,
        );
    }

    /**
     * MySQL MODIFY COLUMN: retype a column without renaming.
     */
    public function modifyColumn(string $table, string $name, string $type): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table)
            . ' MODIFY ' . $this->quoteLiteral($name) . ' ' . $type,
            [],
            executor: $this->executor,
        );
    }

    public function commentOnTable(string $table, string $comment): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table) . " COMMENT = '" . str_replace(['\\', "'"], ['\\\\', "''"], $comment) . "'",
            [],
            executor: $this->executor,
        );
    }

    public function createPartition(string $parent, string $name, string $expression): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($parent) . ' ADD PARTITION (PARTITION ' . $this->quote($name) . ' ' . $expression . ')',
            [],
            executor: $this->executor,
        );
    }

    public function dropPartition(string $table, string $name): Statement
    {
        return new Statement(
            'ALTER TABLE ' . $this->quote($table) . ' DROP PARTITION ' . $this->quote($name),
            [],
            executor: $this->executor,
        );
    }
}
