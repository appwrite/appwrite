<?php

namespace Utopia\Abuse\Adapters\TimeLimit\Appwrite;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Appwrite\Enums\TablesDBIndexType;
use Appwrite\ID;
use Appwrite\Models\Row;
use Appwrite\Query;
use Appwrite\Services\TablesDB as TablesDBService;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Document;

class TablesDB extends TimeLimit
{
    public const DATABASE_NAME = 'Utopia';
    // Datbaase ID configurable in constructor
    public const TABLE_NAME = 'abuse';
    public const TABLE_ID = 'Abuse';
    public const TABLE_LOCK = 'lock'; // Lock table created to allow performant check of setup

    protected TablesDBService $tablesDB;
    protected string $databaseId;
    protected ?int $count = null;

    public function __construct(string $key, int $limit, int $seconds, Client $client, string $databaseId)
    {
        $this->key = $key;
        $now = \time();
        $this->timestamp = (int)($now - ($now % $seconds));
        $this->limit = $limit;
        $this->tablesDB = new TablesDBService($client);
        $this->databaseId = $databaseId;
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

    protected function isSetupComplete(): bool
    {
        try {
            $this->tablesDB->getTable($this->databaseId, self::TABLE_LOCK);
            return true;
        } catch (\Throwable $err) {
            return false;
        }
    }

    protected function createDatabase(): void
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
    protected function createTable(): bool
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
    protected function columnDefinitions(): array
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
    protected function indexDefinitions(): array
    {
        return [
            ['key' => 'unique1', 'type' => (string) TablesDBIndexType::UNIQUE(), 'attributes' => ['key', 'time']],
            ['key' => 'index2', 'type' => (string) TablesDBIndexType::KEY(), 'attributes' => ['time']],
        ];
    }

    /**
     * Add the columns to a table that already exists, one endpoint per type.
     */
    protected function createColumns(): void
    {
        foreach ($this->columnDefinitions() as $column) {
            $key = $column['key'];
            $required = $column['required'];

            $createColumnFunction = match ($column['type']) {
                'string' => fn () => $this->tablesDB->createStringColumn($this->databaseId, self::TABLE_ID, $key, $column['size'] ?? 0, $required),
                'datetime' => fn () => $this->tablesDB->createDatetimeColumn($this->databaseId, self::TABLE_ID, $key, $required),
                'integer' => fn () => $this->tablesDB->createIntegerColumn($this->databaseId, self::TABLE_ID, $key, $required, $column['min'] ?? null, $column['max'] ?? null),
                default => throw new \Exception("No endpoint for column '{$key}'."),
            };

            $this->executeWithSilentError($createColumnFunction, 'column_already_exists');
        }
    }

    /**
     * Add the indexes to a table that already exists.
     */
    protected function createIndexes(): void
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

    protected function waitForResourcesReady(string $resourceType): void
    {
        $attempts = 0;
        $maxAttempts = 15;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $resources = $resourceType === 'columns'
                ? $this->tablesDB->listColumns($this->databaseId, self::TABLE_ID, [Query::notEqual('status', 'available'), Query::limit(1)])->columns
                : $this->tablesDB->listIndexes($this->databaseId, self::TABLE_ID, [Query::notEqual('status', 'available'), Query::limit(1)])->indexes;

            $resources = \array_filter($resources, fn ($resource) => $this->resourceStatus($resource) !== 'available');

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
    protected function resourceStatus(mixed $resource): string
    {
        $status = null;

        if (\is_array($resource)) {
            $status = $resource['status'] ?? null;
        } elseif (\is_object($resource) && \property_exists($resource, 'status')) {
            $status = $resource->status;
        }

        return \is_scalar($status) || $status instanceof \Stringable ? (string) $status : '';
    }

    protected function createLockTable(): void
    {
        $this->executeWithSilentError(
            fn () => $this->tablesDB->createTable($this->databaseId, self::TABLE_LOCK, name: self::TABLE_LOCK),
            'table_already_exists'
        );
    }

    /**
     * @return bool false when the call failed with the tolerated error
     */
    protected function executeWithSilentError(callable $callback, string $allowedErrorType): bool
    {
        try {
            $callback();

            return true;
        } catch (AppwriteException $err) {
            if ($err->getType() !== $allowedErrorType) {
                throw $err;
            }

            return false;
        }
    }

    /**
     * Check
     *
     * Checks if number of counts is bigger or smaller than current limit
     *
     * @param  string  $key
     * @param  int  $timestamp
     * @return int
     *
     * @throws \Exception
     */
    protected function count(string $key, int $timestamp): int
    {
        if (0 == $this->limit) { // No limit no point for counting
            return 0;
        }

        if (! \is_null($this->count)) { // Get fetched result
            return $this->count;
        }

        $timestamp = $this->toDateTime($timestamp);

        $rows = $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, [
            Query::equal('key', [$key]),
            Query::equal('time', [$timestamp]),
        ])->rows;

        $this->count = 0;

        if (\count($rows) === 1) { // Unique Index
            $count = $rows[0]->data['count'] ?? 0;
            if (\is_numeric($count)) {
                $this->count = intval($count);
            }
        }

        return $this->count;
    }

    /**
     * @param  string  $key
     * @param  int  $timestamp
     * @return void
     *
     * @throws \Throwable
     */
    protected function hit(string $key, int $timestamp): void
    {
        if (0 == $this->limit) { // No limit no point for counting
            return;
        }

        $timestamp = $this->toDateTime($timestamp);

        $rows = $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, [
            Query::equal('key', [$key]),
            Query::equal('time', [$timestamp]),
        ])->rows;
        $row = $rows[0] ?? null;

        if (\is_null($row)) {
            $data = [
                'key' => $key,
                'time' => $timestamp,
                'count' => 1,
            ];

            try {
                $this->tablesDB->createRow($this->databaseId, self::TABLE_ID, ID::unique(), $data);
            } catch (AppwriteException $err) {
                if ($err->getType() !== 'row_already_exists') {
                    throw $err;
                }

                $rows = $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, [
                    Query::equal('key', [$key]),
                    Query::equal('time', [$timestamp]),
                ])->rows;

                $row = $rows[0] ?? null;

                if (!is_null($row)) {
                    $count = $row->data['count'] ?? 0;
                    if (\is_numeric($count)) {
                        $this->count = intval($count);
                    }

                    $this->tablesDB->incrementRowColumn($this->databaseId, self::TABLE_ID, $row->id, 'count', 1);
                } else {
                    throw new \Exception('Document Not Found');
                }
            }
        } else {
            $this->tablesDB->incrementRowColumn($this->databaseId, self::TABLE_ID, $row->id, 'count', 1);
        }

        $this->count++;
    }

    /**
     * @param  string  $key
     * @param  int  $timestamp
     * @param  int  $value
     * @return void
     *
     * @throws \Throwable
     */
    protected function set(string $key, int $timestamp, int $value): void
    {
        $timestamp = $this->toDateTime($timestamp);

        $rows = $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, [
            Query::equal('key', [$key]),
            Query::equal('time', [$timestamp]),
        ])->rows;
        $row = $rows[0] ?? null;

        if (\is_null($row)) {
            $data = [
                'key' => $key,
                'time' => $timestamp,
                'count' => $value,
            ];

            try {
                $this->tablesDB->createRow($this->databaseId, self::TABLE_ID, ID::unique(), $data);
            } catch (AppwriteException $err) {
                if ($err->getType() !== 'row_already_exists') {
                    throw $err;
                }

                $rows = $this->tablesDB->listRows($this->databaseId, self::TABLE_ID, [
                    Query::equal('key', [$key]),
                    Query::equal('time', [$timestamp]),
                ])->rows;

                $row = $rows[0] ?? null;

                if (!is_null($row)) {
                    $this->tablesDB->updateRow($this->databaseId, self::TABLE_ID, $row->id, ['count' => $value]);
                } else {
                    throw new \Exception('Unable to find abuse tracking row after race condition handling');
                }
            }
        } else {
            $this->tablesDB->updateRow($this->databaseId, self::TABLE_ID, $row->id, ['count' => $value]);
        }

        $this->count = $value;
    }

    /**
     * Get abuse logs
     *
     * Return logs with an optional offset and limit
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<Document>
     *
     * @throws \Exception
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $queries = [];

        $queries[] = Query::orderDesc('');

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
     * Delete logs older than $timestamp seconds
     *
     * @param  int  $timestamp
     * @return bool
     *
     * @throws \Exception
     */
    public function cleanup(int $timestamp): bool
    {
        $timestamp = $this->toDateTime($timestamp);

        do {
            $response = $this->tablesDB->deleteRows($this->databaseId, self::TABLE_ID, [
                Query::lessThan('time', $timestamp),
            ]);
        } while ($response->total > 0);

        return true;
    }

    protected function toDateTime(int $timestamp): string
    {
        return (new \DateTime())->setTimestamp($timestamp)->format('Y-m-d H:i:s.v');
    }
}
