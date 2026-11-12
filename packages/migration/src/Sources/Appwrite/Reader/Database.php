<?php

namespace Utopia\Migration\Sources\Appwrite\Reader;

use Utopia\Database\Capability;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Query;
use Utopia\Migration\Exception;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Collection as CollectionResource;
use Utopia\Migration\Resources\Database\Column as ColumnResource;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Document as DocumentResource;
use Utopia\Migration\Resources\Database\Index as IndexResource;
use Utopia\Migration\Resources\Database\Row as RowResource;
use Utopia\Migration\Resources\Database\Table as TableResource;
use Utopia\Migration\Sources\Appwrite\Reader;
use Utopia\Query\Schema\ColumnType;

/**
 * @implements Reader<Query>
 */
class Database implements Reader
{
    /**
     * @var callable(UtopiaDocument|null): UtopiaDatabase
    */
    private mixed $getDatabasesDB;

    public function __construct(
        private readonly UtopiaDatabase $dbForProject,
        ?callable $getDatabasesDB = null,
        private readonly ?string $projectId = null
    ) {
        $this->getDatabasesDB = $getDatabasesDB;
    }

    public function report(array $resources, array &$report, array $resourceIds = []): mixed
    {
        $relevantResources = [
            // tablesdb
            Resource::TYPE_DATABASE,
            Resource::TYPE_TABLE,
            Resource::TYPE_ROW,
            Resource::TYPE_COLUMN,
            Resource::TYPE_INDEX,
            // vectorsdb
            Resource::TYPE_DATABASE_VECTORSDB,
            // Documentsdb
            Resource::TYPE_DATABASE_DOCUMENTSDB,
            Resource::TYPE_COLLECTION,
            Resource::TYPE_DOCUMENT,
            Resource::TYPE_ATTRIBUTE,
        ];

        if (!Resource::isSupported($relevantResources, $resources)) {
            return null;
        }

        foreach ($relevantResources as $resourceType) {
            if (Resource::isSupported($resourceType, $resources)) {
                $report[$resourceType] = 0;
            }
        }
        $databaseResources = array_keys(Resource::DATABASE_TYPE_RESOURCE_MAP);
        $databaseQueries = [];
        foreach ($databaseResources as $databaseResourceType) {
            if (!empty($resourceIds[$databaseResourceType])) {
                $databaseIds = (array) $resourceIds[$databaseResourceType];

                $databaseQueries[] = Query::equal('$id', $databaseIds);
            }
        }

        if (
            count(array_intersect($resources, $relevantResources)) === 1 &&
            Resource::isSupported(array_keys(Resource::DATABASE_TYPE_RESOURCE_MAP), $resources)
        ) {
            return null;
        }

        $dbResources = [];
        $databases = $this->listDatabases($databaseQueries);
        $selectedDatabaseTypes = \array_intersect($resources, array_keys(Resource::DATABASE_TYPE_RESOURCE_MAP));

        foreach ($databases as $database) {
            $databaseType = $this->getDatabaseType($database);

            if (
                !empty($selectedDatabaseTypes) &&
                !Resource::isSupported($databaseType, $selectedDatabaseTypes)
            ) {
                continue;
            }

            if (Resource::isSupported($databaseType, $resources)) {
                $report[$databaseType] += 1;
            }
        }

        // Process each database
        foreach ($databases as $database) {
            $databaseType = $this->getDatabaseType($database);

            if (
                !empty($selectedDatabaseTypes) &&
                !Resource::isSupported($databaseType, $selectedDatabaseTypes)
            ) {
                continue;
            }

            $databaseSpecificResources = Resource::DATABASE_TYPE_RESOURCE_MAP[$databaseType];

            $databaseSequence = $database->getSequence();

            if (!isset($dbResources[$database->getId()])) {
                $dbResources[$database->getId()] = new DatabaseResource(
                    $database->getId(),
                    $database->getAttribute('name'),
                    $database->getCreatedAt(),
                    $database->getUpdatedAt(),
                    $database->getAttribute('enabled', true),
                    $database->getAttribute('originalId', ''),
                    $database->getAttribute('type', ''),
                    $database->getAttribute('database', '')
                );
            }

            $dbResource = $dbResources[$database->getId()];

            $tables = $this->listTables($dbResource);
            $count = count($tables);

            if (Resource::isSupported($databaseSpecificResources['entity'], $resources)) {
                $report[$databaseSpecificResources['entity']] += $count;
            }

            foreach ($tables as $table) {
                $tableSequence = $table->getSequence();

                if (Resource::isSupported($databaseSpecificResources['record'], $resources)) {
                    $rowTableId = "database_{$databaseSequence}_collection_{$tableSequence}";
                    $count = $this->countResources($rowTableId, [], $database);
                    $report[$databaseSpecificResources['record']] += $count;
                }

                $commonQueries = [
                    Query::equal('databaseInternalId', [$databaseSequence]),
                    Query::equal('collectionInternalId', [$tableSequence]),
                ];

                if (
                    isset($databaseSpecificResources['field']) &&
                    Resource::isSupported($databaseSpecificResources['field'], $resources)
                ) {
                    $count = $this->countResources('attributes', $commonQueries);
                    $report[$databaseSpecificResources['field']] += $count;
                }

                if (in_array(Resource::TYPE_INDEX, $resources)) {
                    $report[Resource::TYPE_INDEX] += $this->countResources('indexes', $commonQueries);
                }
            }
        }

        return null;
    }

    public function listDatabases(array $queries = []): array
    {
        return $this->dbForProject->find('databases', $queries);
    }

    public function listTables(DatabaseResource $resource, array $queries = []): array
    {
        try {
            $database = $this->dbForProject->getDocument('databases', $resource->getId());
        } catch (DatabaseException $e) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            );
        }

        if ($database->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            );
        }

        try {
            return $this->dbForProject->find(
                'database_' . $database->getSequence(),
                $queries
            );
        } catch (DatabaseException $e) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            );
        }
    }

    public function listColumns(TableResource $resource, array $queries = []): array
    {
        $database = $this->dbForProject->getDocument(
            'databases',
            $resource->getDatabase()->getId(),
        );

        if ($database->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            );
        }

        $table = $this->dbForProject->getDocument(
            'database_' . $database->getSequence(),
            $resource->getId(),
        );

        if ($table->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Table not found',
            );
        }

        $queries[] = Query::equal('databaseInternalId', [$database->getSequence()]);
        $queries[] = Query::equal('collectionInternalId', [$table->getSequence()]);

        try {
            $columns = $this->dbForProject->find('attributes', $queries);
        } catch (DatabaseException $e) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            );
        }

        foreach ($columns as $column) {
            if ($column['type'] !== ColumnType::Relationship->value) {
                continue;
            }

            $options = $column['options'];
            if ($options instanceof UtopiaDocument) {
                $options = $options->getArrayCopy();
            }
            if (\is_array($options)) {
                foreach ($options as $key => $value) {
                    $column[$key] = $value;
                }
            }

            unset($column['options']);
        }

        return $columns;
    }

    public function listIndexes(TableResource $resource, array $queries = []): array
    {
        $database = $this->dbForProject->getDocument(
            'databases',
            $resource->getDatabase()->getId(),
        );

        if ($database->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            );
        }

        $table = $this->dbForProject->getDocument(
            'database_' . $database->getSequence(),
            $resource->getId(),
        );

        if ($table->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Table not found',
            );
        }

        $queries[] = Query::equal('databaseInternalId', [$database->getSequence()]);
        $queries[] = Query::equal('collectionInternalId', [$table->getSequence()]);

        try {
            return $this->dbForProject->find('indexes', $queries);
        } catch (DatabaseException $e) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            );
        }
    }

    public function listRows(TableResource $resource, array $queries = []): array
    {
        $database = $this->dbForProject->getDocument(
            'databases',
            $resource->getDatabase()->getId(),
        );

        if ($database->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            );
        }

        $table = $this->dbForProject->getDocument(
            'database_' . $database->getSequence(),
            $resource->getId(),
        );

        if ($table->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Table not found',
            );
        }

        $tableId = "database_{$database->getSequence()}_collection_{$table->getSequence()}";

        // Use the appropriate database instance for this specific database
        $dbInstance = $this->getDatabase($database);

        try {
            $rows = $dbInstance->find($tableId, $queries);
        } catch (DatabaseException $e) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: $e->getMessage(),
                code: (int) $e->getCode() ?: Exception::CODE_INTERNAL,
                previous: $e
            );
        }

        return \array_map(function ($row) {
            return $row->getArrayCopy();
        }, $rows);
    }

    public function getRow(TableResource $resource, string $rowId, array $queries = []): array
    {
        $database = $this->dbForProject->getDocument(
            'databases',
            $resource->getDatabase()->getId(),
        );

        if ($database->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Database not found',
            );
        }

        $table = $this->dbForProject->getDocument(
            'database_' . $database->getSequence(),
            $resource->getId(),
        );

        if ($table->isEmpty()) {
            throw new Exception(
                resourceName: $resource->getName(),
                resourceGroup: $resource->getGroup(),
                resourceId: $resource->getId(),
                message: 'Table not found',
            );
        }

        $tableId = "database_{$database->getSequence()}_collection_{$table->getSequence()}";

        // Use the appropriate database instance for this specific database
        $dbInstance = $this->getDatabase($database);

        return $dbInstance->getDocument(
            $tableId,
            $rowId,
            $queries
        )->getArrayCopy();
    }

    /**
     * @param array $columns
     * @return Query
     */
    public function querySelect(array $columns): Query
    {
        return Query::select($columns);
    }

    /**
     * @param string $column
     * @param array $values
     * @return Query
     */
    public function queryEqual(string $column, array $values): Query
    {
        return Query::equal($column, $values);
    }

    /**
     * @param Resource|string $resource
     * @return Query
     * @throws DatabaseException
     * @throws Exception
     */
    public function queryCursorAfter(mixed $resource): Query
    {
        if (\is_string($resource)) {
            throw new \InvalidArgumentException('Querying with a cursor through the database requires a resource reference');
        }

        switch ($resource::class) {
            case DatabaseResource::class:
                /** @var DatabaseResource $resource */
                // Databases are always in dbForProject metadata
                $document = $this->dbForProject->getDocument('databases', $resource->getId());
                break;
            case TableResource::class:
            case CollectionResource::class:
                /** @var TableResource|CollectionResource $resource */
                // Tables/Collections metadata is in dbForProject
                $database = $this->dbForProject->getDocument('databases', $resource->getDatabase()->getId());
                $document = $this->dbForProject->getDocument('database_' . $database->getSequence(), $resource->getId());
                break;
            case ColumnResource::class:
                /** @var ColumnResource $resource */
                // Columns (attributes) are in dbForProject metadata
                $document = $this->dbForProject->getDocument('attributes', $resource->getId());
                break;
            case IndexResource::class:
                /** @var IndexResource $resource */
                // Indexes are in dbForProject metadata
                $document = $this->dbForProject->getDocument('indexes', $resource->getId());
                break;
            case RowResource::class:
            case DocumentResource::class:
                /** @var RowResource|DocumentResource $resource */
                // Rows/Documents are in the specific database instance
                // getRow() already uses getDatabase() internally
                $document = $this->getRow($resource->getTable(), $resource->getId());
                $document = new UtopiaDocument($document);
                break;
            default:
                throw new \InvalidArgumentException('Unsupported resource type');
        }

        return Query::cursorAfter($document);
    }

    public function queryLimit(int $limit): Query
    {
        return Query::limit($limit);
    }

    public function getSupportForAttributes(): bool
    {
        return $this->dbForProject->getAdapter()->supports(Capability::DefinedAttributes);
    }

    /**
     * @param string $table
     * @param array $queries
     * @param UtopiaDocument|null $database
     * @return int
     */
    private function countResources(string $table, array $queries = [], ?UtopiaDocument $database = null): int
    {
        // Use the appropriate database instance for row data
        if ($database !== null) {
            $dbInstance = $this->getDatabase($database);
            return $dbInstance->count($table, $queries);
        }

        // Use dbForProject for metadata tables
        return $this->dbForProject->count($table, $queries);
    }

    private function getDatabase(?UtopiaDocument $database = null): UtopiaDatabase
    {
        if ($this->getDatabasesDB !== null && $database !== null) {
            return ($this->getDatabasesDB)($database);
        }

        return $this->dbForProject;
    }

    private function getDatabaseType(UtopiaDocument $database): string
    {
        $databaseType = $database->getAttribute('type', Resource::TYPE_DATABASE);

        if (\in_array($databaseType, [Resource::TYPE_DATABASE_LEGACY, Resource::TYPE_DATABASE_TABLESDB], true)) {
            return Resource::TYPE_DATABASE;
        }

        if (!isset(Resource::DATABASE_TYPE_RESOURCE_MAP[$databaseType])) {
            return Resource::TYPE_DATABASE;
        }

        return $databaseType;
    }
}
