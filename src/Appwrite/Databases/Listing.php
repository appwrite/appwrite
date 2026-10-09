<?php

namespace Appwrite\Databases;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * A list request returns either documents or, when it aggregates or groups, one row per group. The database
 * library reads the two through separate methods, so a list routes its queries to the one that answers them.
 */
final class Listing
{
    /**
     * @param array<Query> $queries
     */
    public static function aggregates(array $queries): bool
    {
        $grouped = Query::groupByType($queries);

        return $grouped->aggregations !== [] || $grouped->groupBy !== [];
    }

    /**
     * @param array<Query> $queries
     * @return array<Document>
     */
    public static function read(Database $database, string $collection, array $queries): array
    {
        return self::aggregates($queries)
            ? self::rows($database, $collection, $queries)
            : $database->find($collection, $queries);
    }

    /**
     * @param array<Query> $queries at least one aggregate or groupBy query
     * @return list<Document>
     */
    public static function rows(Database $database, string $collection, array $queries): array
    {
        return \array_map(
            static fn (array $row): Document => new Document($row),
            $database->aggregate($collection, $queries),
        );
    }
}
