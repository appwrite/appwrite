<?php

namespace Utopia\Query\Schema;

enum ColumnType: string
{
    case String = 'string';
    case Varchar = 'varchar';
    case Text = 'text';
    case MediumText = 'mediumtext';
    case LongText = 'longtext';
    case TinyInteger = 'tinyinteger';
    case SmallInteger = 'smallinteger';
    case Integer = 'integer';
    case BigInteger = 'biginteger';
    case Float = 'float';
    case Double = 'double';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Datetime = 'datetime';
    case Timestamp = 'timestamp';
    case Json = 'json';
    case Binary = 'binary';
    case Enum = 'enum';
    case Point = 'point';
    case Linestring = 'linestring';
    case Polygon = 'polygon';
    case Vector = 'vector';
    case Id = 'id';
    case Uuid = 'uuid';
    case Uuid7 = 'uuid7';
    case Object = 'object';
    case Relationship = 'relationship';
    case Serial = 'serial';
    case BigSerial = 'bigserial';
    case SmallSerial = 'smallserial';
    case Array = 'array';
    case Tuple = 'tuple';
}
