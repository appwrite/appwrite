<?php

namespace Utopia\Abuse\Adapter\TimeLimit\Appwrite;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\TablesDBIndexType;
use Appwrite\ID;
use Appwrite\Models\Row;
use Appwrite\Query;
use Appwrite\Services\TablesDB as TablesDBService;
use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Database\Document;

final readonly class TablesDB extends TimeLimit
{
    public const string DATABASE_NAME = 'Utopia';

    public const string TABLE_NAME = 'abuse';

    public const string TABLE_ID = 'Abuse';

    public const string TABLE_LOCK = 'lock'; // Lock table created to allow performant check of setup

    private TablesDBService $tablesDB;

    public function __construct(string $key, int $limit, int $seconds, Client $client, private string $databaseId)
    {
        parent::__construct($key, $limit, $seconds);
        $this->tablesDB = new TablesDBService($client);
    }

    /**
     * @throws \Exception
     */
    public function setup(): void
    {
        if ($this->isSetupComplete()) {
            return;
        }

        $this->createDatabase();

        if (! $this->createTable()) {
            // The table is left over from a setup that did not run to completion,
            // so some of its columns or indexes may be missing. Inline definitions
            // only apply while the table is being created, so add them one by one.
            $this->createColumns();
            $this->waitForResourcesReady('columns');
            $this->createIndexes();
            $this->waitForResourcesReady('indexes');
        }

        $this->createLockTable();
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    protected function hit(string $key, int $window): int
    {
        $time = $this->toDateTime($window);
        $row = $this->find($key, $time);

        if (\is_null($row)) {
            try {
                $this->tablesDB->createRow($this->databaseId, self::TABLE_ID, ID::unique(), [
                    'key' => $key,
                    'time' => $time,
                    'count' => 1,
                ]);

                return 0;
            } catch (AppwriteException $error) {
                if ($error->getType() !== 'row_already_exists') {
                    throw $error;
                }

                $row = $this->find($key, $time) ?? throw new \Exception('Document Not Found');
            }
        }

        $count = $this->parseCount($row->data['count'] ?? 0);

        if ($count >= $this->limit) {
            return $count;
        }

        $this->tablesDB->incrementRowColumn($this->databaseId, self::TABLE_ID, $row->id, 'count', 1);

        return $count;
    }

    /**
     * @throws \Exception
     */
    #[\Override]
    protected function count(string $key, int $window): int
    {
        $row = $this->find($key, $this->toDateTime($window));

        return \is_null($row) ? 0 : $this->parseCount($row->data['count'] ?? 0);
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    protected function set(string $key, int $window, int $value): void
    {
        $time = $this->toDateTime($window);
        $row = $this->find($key, $time);

        if (\is_null($row)) {
            try {
                $this->tablesDB->createRow($this->databaseId, self::TABLE_ID, ID::unique(), [
                    'key' => $key,
                    'time' => $time,
                    'count' => $value,
                ]);

                return;
            } catch (AppwriteException $error) {
                if ($error->getType() !== 'row_already_exists') {
                    throw $error;
                }

                $row = $this->find($key, $time) ?? throw new \Exception('Unable to find abuse tracking row after race condition handling');
            }
        }

        $this->tablesDB->updateRow($this->databaseId, self::TABLE_ID, $row->id, ['count' => $value]);
    }

    /**
     * @return array<Document>
     *
     * @throws \Exception
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $queries = [Query::orderDesc('')];

        if (! \is_null($offset)) {
            $queries[] = Query::offset($offset);
        }
        if (! \is_null($limit)) {
            $queries[] = Query::limit($limit);
        }

        $rows = $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, $queries)->rows;

        return \array_map(fn (Row $row) => new Document($row->toArray()), $rows);
    }

    /**
     * @throws \Exception
     */
    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        $time = $this->toDateTime($timestamp);

        do {
            $response = $this->tablesDB->deleteRows($this->databaseId, self::TABLE_ID, [
                Query::lessThan('time', $time),
            ]);
        } while ($response->total > 0);

        return true;
    }

    private function find(string $key, string $time): ?Row
    {
        return $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, [
            Query::equal('key', [$key]),
            Query::equal('time', [$time]),
        ])->rows[0] ?? null;
    }

    private function parseCount(mixed $count): int
    {
        return \is_numeric($count) ? (int) $count : 0;
    }

    private function toDateTime(int $timestamp): string
    {
        return new \DateTime()->setTimestamp($timestamp)->format('Y-m-d H:i:s.v');
    }

    private function isSetupComplete(): bool
    {
        try {
            $this->tablesDB->getTable($this->databaseId, self::TABLE_LOCK);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createDatabase(): void
    {
        $this->executeWithSilentError(
            fn () => $this->tablesDB->create($this->databaseId, self::DATABASE_NAME),
            'database_already_exists'
        );
    }

    /**
     * Create the abuse table along with its columns and indexes in one request.
     *
     * Inline columns and indexes are created synchronously and come back
     * available, so there is nothing to poll for afterwards.
     *
     * @return bool false when the table already existed
     */
    private function createTable(): bool
    {
        return $this->executeWithSilentError(
            fn () => $this->tablesDB->createTable(
                $this->databaseId,
                self::TABLE_ID,
                self::TABLE_NAME,
                columns: $this->columnDefinitions(),
                indexes: $this->indexDefinitions(),
            ),
            'table_already_exists'
        );
    }

    /**
     * Columns sent inline when the table is created.
     *
     * createColumns() repairs a table that already exists from the same list.
     *
     * @return array<array{key: string, type: string, required: bool, size?: int, min?: int, max?: int}>
     */
    private function columnDefinitions(): array
    {
        return [
            ['key' => 'key', 'type' => 'string', 'size' => 255, 'required' => true],
            ['key' => 'time', 'type' => 'datetime', 'required' => true],
            ['key' => 'count', 'type' => 'integer', 'required' => true, 'min' => 0, 'max' => PHP_INT_MAX],
        ];
    }

    /**
     * Indexes sent inline when the table is created.
     *
     * createIndexes() repairs a table that already exists from the same list.
     *
     * An inline definition names its columns under 'attributes', even though
     * the index that comes back reports them under 'columns'.
     *
     * @return array<array{key: string, type: string, attributes: array<string>}>
     */
    private function indexDefinitions(): array
    {
        return [
            ['key' => 'unique1', 'type' => (string) TablesDBIndexType::UNIQUE(), 'attributes' => ['key', 'time']],
            ['key' => 'index2', 'type' => (string) TablesDBIndexType::KEY(), 'attributes' => ['time']],
        ];
    }

    /**
     * Add the columns to a table that already exists, one endpoint per type.
     */
    private function createColumns(): void
    {
        foreach ($this->columnDefinitions() as $column) {
            $key = $column['key'];
            $required = $column['required'];

            $createColumn = match ($column['type']) {
                'string' => fn () => $this->tablesDB->createStringColumn($this->databaseId, self::TABLE_ID, $key, $column['size'] ?? 0, $required),
                'datetime' => fn () => $this->tablesDB->createDatetimeColumn($this->databaseId, self::TABLE_ID, $key, $required),
                'integer' => fn () => $this->tablesDB->createIntegerColumn($this->databaseId, self::TABLE_ID, $key, $required, $column['min'] ?? null, $column['max'] ?? null),
                default => throw new \Exception("No endpoint for column '{$key}'."),
            };

            $this->executeWithSilentError($createColumn, 'column_already_exists');
        }
    }

    /**
     * Add the indexes to a table that already exists.
     */
    private function createIndexes(): void
    {
        foreach ($this->indexDefinitions() as $index) {
            $this->executeWithSilentError(
                fn () => $this->tablesDB->createIndex(
                    $this->databaseId,
                    self::TABLE_ID,
                    $index['key'],
                    TablesDBIndexType::from($index['type']),
                    $index['attributes'],
                ),
                'index_already_exists'
            );
        }
    }

    private function waitForResourcesReady(string $resourceType): void
    {
        $attempts = 0;
        $maxAttempts = 15;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $resources = $resourceType === 'columns'
                ? $this->tablesDB->listColumns($this->databaseId, self::TABLE_ID, [Query::notEqual('status', 'available'), Query::limit(1)])->columns
                : $this->tablesDB->listIndexes($this->databaseId, self::TABLE_ID, [Query::notEqual('status', 'available'), Query::limit(1)])->indexes;

            $resources = \array_filter($resources, fn (mixed $resource) => $this->resourceStatus($resource) !== 'available');

            if (\count($resources) === 0) {
                return;
            }

            \sleep(1);
        }

        throw new \Exception("Failed to setup {$resourceType}.");
    }

    /**
     * Read the status off a listed column or index.
     *
     * A listed column arrives as the raw payload, since the SDK has no single
     * model to hydrate the union of column types into, while a listed index
     * arrives as a ColumnIndex. Accept either shape.
     */
    private function resourceStatus(mixed $resource): string
    {
        $status = null;

        if (\is_array($resource)) {
            $status = $resource['status'] ?? null;
        } elseif (\is_object($resource) && \property_exists($resource, 'status')) {
            $status = $resource->status;
        }

        return \is_scalar($status) || $status instanceof \Stringable ? (string) $status : '';
    }

    private function createLockTable(): void
    {
        $this->executeWithSilentError(
            fn () => $this->tablesDB->createTable($this->databaseId, self::TABLE_LOCK, name: self::TABLE_LOCK),
            'table_already_exists'
        );
    }

    /**
     * @return bool false when the call failed with the tolerated error
     */
    private function executeWithSilentError(callable $callback, string $allowedErrorType): bool
    {
        try {
            $callback();

            return true;
        } catch (AppwriteException $error) {
            if ($error->getType() !== $allowedErrorType) {
                throw $error;
            }

            return false;
        }
    }
}
