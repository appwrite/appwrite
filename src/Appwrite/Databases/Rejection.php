<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use PDOException;
use Throwable;
use Utopia\Database\Exception\NotFound as NotFoundException;

/**
 * Input the database engine itself refused after every validator accepted it.
 */
enum Rejection
{
    case Column;

    private const int MYSQL_UNKNOWN_COLUMN = 1054;

    private const string POSTGRES_UNDEFINED_COLUMN = '42703';

    public static function fromThrowable(Throwable $error): ?self
    {
        return match (true) {
            $error instanceof PDOException => self::fromDriver($error),
            $error instanceof NotFoundException && $error->getPrevious() instanceof PDOException => self::fromDriver($error->getPrevious()) === self::Column ? self::Column : null,
            default => null,
        };
    }

    /**
     * @param array<mixed> $queries the caller's queries; without any, the fault is the server's
     */
    public static function fromQueries(Throwable $error, array $queries): ?self
    {
        return $queries === [] ? null : self::fromThrowable($error);
    }

    public function getType(): string
    {
        return match ($this) {
            self::Column => Exception::GENERAL_QUERY_INVALID,
        };
    }

    public function getMessage(): string
    {
        return match ($this) {
            self::Column => 'Invalid query: the query references an attribute that does not exist.',
        };
    }

    private static function fromDriver(PDOException $error): ?self
    {
        if ($error->getCode() === self::POSTGRES_UNDEFINED_COLUMN) {
            return self::Column;
        }

        $code = $error->errorInfo[1] ?? null;

        return $code === self::MYSQL_UNKNOWN_COLUMN ? self::Column : null;
    }
}
