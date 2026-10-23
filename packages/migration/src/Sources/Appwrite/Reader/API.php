<?php

namespace Utopia\Migration\Sources\Appwrite\Reader;

use Appwrite\AppwriteException;
use Appwrite\Query;
use Appwrite\Services\TablesDB;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Sources\Appwrite\Reader;

/**
 * Adapts Appwrite SDK typed models to the Reader's plain-array contract so that
 * downstream callers can use array-offset syntax interchangeably with the
 * Database reader (which returns utopia-php Document objects).
 *
 * @implements Reader<Query>
 */
class API implements Reader
{
    public function __construct(
        private readonly TablesDB $database,
    ) {
    }

    /**
     * @throws AppwriteException
     */
    public function report(array $resources, array &$report, array $resourceIds = []): mixed
    {
        $relevantResources = [
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_ROW,
            Resource::TYPE_COLUMN,
            Resource::TYPE_INDEX
        ];

        if (!Resource::isSupported($relevantResources, $resources)) {
            return null;
        }

        foreach ($relevantResources as $resourceType) {
            if (Resource::isSupported($resourceType, $resources)) {
                $report[$resourceType] = 0;
            }
        }

        $databaseQueries = [];
        if (!empty($resourceIds[Resource::TYPE_DATABASE])) {
            $databaseIds = (array) $resourceIds[Resource::TYPE_DATABASE];

            $databaseQueries[] = Query::equal('$id', $databaseIds);
        }

        $databasesResponse = $this->database->list($databaseQueries);
        $databases = \array_map(fn ($database) => $database->toArray(), $databasesResponse->databases);

        if (in_array(Resource::TYPE_DATABASE, $resources)) {
            $report[Resource::TYPE_DATABASE] = $databasesResponse->total;
        }

        if (count(array_intersect($resources, $relevantResources)) === 1 &&
            in_array(Resource::TYPE_DATABASE, $resources)) {
            return null;
        }

        // Process each database
        foreach ($databases as $database) {
            $databaseId = $database['$id'];

            $tables = [];
            $pageLimit = 25;
            $lastTable = null;

            while (true) {
                $tablesResponse = $this->database->listTables(
                    $databaseId,
                    $lastTable
                        ? [Query::cursorAfter($lastTable)]
                        : [Query::limit($pageLimit)]
                );
                $currentTables = \array_map(fn ($table) => $table->toArray(), $tablesResponse->tables);

                $tables = \array_merge($tables, $currentTables);
                $lastTable = $tables[count($tables) - 1]['$id'] ?? null;

                if (\count($currentTables) < $pageLimit) {
                    break;
                }
            }

            if (Resource::isSupported(Resource::TYPE_TABLE, $resources)) {
                $report[Resource::TYPE_TABLE] += \count($tables);
            }

            if (Resource::isSupported([Resource::TYPE_ROW, Resource::TYPE_COLUMN, Resource::TYPE_INDEX], $resources)) {
                foreach ($tables as $table) {
                    $tableId = $table['$id'];

                    if (Resource::isSupported(Resource::TYPE_COLUMN, $resources)) {
                        // a table already returns a list of attributes
                        $report[Resource::TYPE_COLUMN] += count($table['columns'] ?? $table['attributes'] ?? []);
                    }

                    if (\in_array(Resource::TYPE_INDEX, $resources)) {
                        // A table already returns a list of indexes
                        $report[Resource::TYPE_INDEX] += \count($table['indexes'] ?? []);
                    }

                    if (Resource::isSupported(Resource::TYPE_ROW, $resources)) {
                        $rowsResponse = $this->database->listRows(
                            $databaseId,
                            $tableId,
                            [Query::limit(1)]
                        );

                        $report[Resource::TYPE_ROW] += $rowsResponse->total;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<array<string, mixed>>
     * @throws AppwriteException
     */
    public function listDatabases(array $queries = []): array
    {
        return \array_map(
            fn ($database) => $database->toArray(),
            $this->database->list($queries)->databases
        );
    }

    /**
     * @return array<array<string, mixed>>
     * @throws AppwriteException
     */
    public function listTables(Database $resource, array $queries = []): array
    {
        return \array_map(
            fn ($table) => $table->toArray(),
            $this->database->listTables($resource->getId(), $queries)->tables
        );
    }

    /**
     * @return array<array<string, mixed>>
     * @throws AppwriteException
     */
    public function listColumns(Table $resource, array $queries = []): array
    {
        return $this->database->listColumns(
            $resource->getDatabase()->getId(),
            $resource->getId(),
            $queries
        )->toArray()['columns'];
    }

    /**
     * @return array<array<string, mixed>>
     * @throws AppwriteException
     */
    public function listIndexes(Table $resource, array $queries = []): array
    {
        return \array_map(
            fn ($index) => $index->toArray(),
            $this->database->listIndexes(
                $resource->getDatabase()->getId(),
                $resource->getId(),
                $queries
            )->indexes
        );
    }

    /**
     * @return array<array<string, mixed>>
     * @throws AppwriteException
     */
    public function listRows(Table $resource, array $queries = []): array
    {
        return \array_map(
            fn ($row) => $row->toArray(),
            $this->database->listRows(
                $resource->getDatabase()->getId(),
                $resource->getId(),
                $queries
            )->rows
        );
    }

    /**
     * @return array<string, mixed>
     * @throws AppwriteException
     */
    public function getRow(Table $resource, string $rowId, array $queries = []): array
    {
        return $this->database->getRow(
            $resource->getDatabase()->getId(),
            $resource->getId(),
            $rowId,
            $queries
        )->toArray();
    }

    /**
     * @param array $columns
     * @return string
     */
    public function querySelect(array $columns): string
    {
        return Query::select($columns);
    }

    /**
     * @param string $column
     * @param array $values
     * @return string
     */
    public function queryEqual(string $column, array $values): string
    {
        return Query::equal($column, $values);
    }

    /**
     * @param Resource|string $resource
     * @return string
     */
    public function queryCursorAfter(Resource|string $resource): string
    {
        if ($resource instanceof Resource) {
            $resource = $resource->getId();
        }

        return Query::cursorAfter($resource);
    }

    public function queryLimit(int $limit): string
    {
        return Query::limit($limit);
    }

    public function getSupportForAttributes(): bool
    {
        return true;
    }
}
