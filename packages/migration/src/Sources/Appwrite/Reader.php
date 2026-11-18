<?php

namespace Utopia\Migration\Sources\Appwrite;

use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Table;

/**
 * @template QueryType
 */
interface Reader
{
    /**
     * Get information about the resources currently on the source
     *
     * @param array $resources
     * @param array $report
     * @param array<string, array<string>> $resourceIds
     * @return mixed
     */
    public function report(array $resources, array &$report, array $resourceIds = []): mixed;

    /**
     * List databases that match the given queries
     *
     * @param array<QueryType> $queries
     * @return array
     */
    public function listDatabases(array $queries = []): array;

    /**
     * List collections that match the given queries
     *
     * @param Database $resource
     * @param array $queries
     * @return array
     */
    public function listTables(Database $resource, array $queries = []): array;

    /**
     * List attributes that match the given queries
     *
     * @param Table $resource
     * @param array<QueryType> $queries
     * @return array
     */
    public function listColumns(Table $resource, array $queries = []): array;

    /**
     * List indexes that match the given queries
     *
     * @param Table $resource
     * @param array<QueryType> $queries
     * @return array
     */
    public function listIndexes(Table $resource, array $queries = []): array;

    /**
     * List documents that match the given queries
     *
     * @param Table $resource
     * @param array<QueryType> $queries
     * @return array
     */
    public function listRows(Table $resource, array $queries = []): array;

    /**
     * Get a document by its ID in the given collection
     *
     * @param Table $resource
     * @param string $rowId
     * @param array $queries
     * @return array
     */
    public function getRow(Table $resource, string $rowId, array $queries = []): array;

    /**
     * Return a query to select the given attributes
     *
     * @param array $columns
     * @return QueryType|string
     */
    public function querySelect(array $columns): mixed;

    /**
     * Return a query to filter the given attributes
     *
     * @param string $column
     * @param array $values
     * @return QueryType|string
     */
    public function queryEqual(string $column, array $values): mixed;

    /**
     * Return a query to paginate after the given resource
     *
     * @param Resource|string $resource
     * @return QueryType|string
     */
    public function queryCursorAfter(Resource|string $resource): mixed;

    /**
     * Return a query to limit the number of results
     *
     * @param int $limit
     * @return QueryType|string
     */
    public function queryLimit(int $limit): mixed;
    public function getSupportForAttributes(): bool;
}
