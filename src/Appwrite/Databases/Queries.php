<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Query\Exception as QueryLibraryException;
use Utopia\Query\Exception\ValidationException;

/**
 * Only a rejected query is the caller's 400; a query-library fault stays a 5xx so it reaches error reporting.
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

    public static function failure(QueryException|QueryLibraryException $failure): Exception|QueryLibraryException
    {
        if ($failure instanceof QueryException || $failure instanceof ValidationException) {
            return self::invalid($failure);
        }

        return $failure;
    }

    private static function invalid(QueryException|QueryLibraryException $failure): Exception
    {
        return new Exception(Exception::GENERAL_QUERY_INVALID, $failure->getMessage());
    }
}
