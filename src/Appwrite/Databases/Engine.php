<?php

namespace Appwrite\Databases;

use Swoole\Database\PDOProxy;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\OperatorType;
use Utopia\Database\PDO;
use Utopia\Mongo\Client;

/**
 * The engine behind a database, for the arithmetic main (database 7.4.1) left to it.
 */
enum Engine
{
    case MariaDB;
    case PostgreSQL;
    case MongoDB;

    public static function of(Database|Adapter $database): self
    {
        $driver = ($database instanceof Database ? $database->getAdapter() : $database)->getDriver();

        return match (true) {
            $driver instanceof Client => self::MongoDB,
            ($driver instanceof \PDO || $driver instanceof PDO || $driver instanceof PDOProxy) && $driver->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' => self::PostgreSQL,
            default => self::MariaDB,
        };
    }

    /**
     * MariaDB and MySQL computed a fractional operand in DOUBLE, and a division by a whole number in exact
     * DECIMAL; PostgreSQL in NUMERIC, except POWER() in double precision; MongoDB stored the double and every
     * read cast it to an integer.
     */
    /**
     * A fractional counter change: MariaDB stored it rounded half to even and MongoDB cut toward zero. PostgreSQL
     * refused it on main; it rounds half to even here, as appwrite has answered it since.
     */
    public function counterRounding(): Rounding
    {
        return $this === self::MongoDB ? Rounding::TowardZero : Rounding::HalfEven;
    }

    public function rounding(OperatorType $method, bool $wholeOperand): Rounding
    {
        return match ($this) {
            self::MongoDB => Rounding::TowardZero,
            self::PostgreSQL => $method === OperatorType::Power ? Rounding::HalfEven : Rounding::HalfAwayFromZero,
            self::MariaDB => $method === OperatorType::Divide && $wholeOperand ? Rounding::HalfAwayFromZero : Rounding::HalfEven,
        };
    }
}
