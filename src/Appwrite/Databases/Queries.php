<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Query\Exception as QueryLibraryException;

/**
 * Every query the database or query library refuses is the caller's 400, as every query exception was on main.
 */
final class Queries
{
    /**
     * @param array<string> $queries
     * @return array<Query>
     */
    public static function parse(array $queries): array
    {
        try {
            return Query::parseQueries($queries);
        } catch (QueryException|QueryLibraryException $failure) {
            throw self::invalid($failure);
        }
    }

    public static function failure(QueryException|QueryLibraryException $failure): Exception
    {
        return self::invalid($failure);
    }

    private static function invalid(QueryException|QueryLibraryException $failure): Exception
    {
        return new Exception(Exception::GENERAL_QUERY_INVALID, $failure->getMessage());
    }
}
