<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests\TimeLimit;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Utopia\Abuse\Adapter\TimeLimit\Appwrite\TablesDB;

/**
 * Answers the requests setup() makes, so the schema it asks the SDK for can be read
 * off the wire without an Appwrite backend.
 */
final class SetupTablesDBClient extends Client
{
    private const string TIMESTAMP = '2026-01-01T00:00:00.000+00:00';

    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  bool  $tableExists  answer the table creation with table_already_exists, the state a
     *                             setup that stopped halfway leaves behind
     */
    public function __construct(private readonly bool $tableExists)
    {
        parent::__construct();
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function call(string $method, string $path = '', array $headers = [], array $params = [], ?string $responseType = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

        if ($method === self::METHOD_GET) {
            return match (true) {
                \str_ends_with($path, '/columns') => ['total' => 0, 'columns' => []],
                \str_ends_with($path, '/indexes') => ['total' => 0, 'indexes' => []],
                // The lock table is what setup() reads to decide whether it ran to completion.
                default => throw new AppwriteException('Table not found', 404, 'table_not_found'),
            };
        }

        if ($path === '/tablesdb') {
            throw new AppwriteException('Database already exists', 409, 'database_already_exists');
        }

        if (\str_ends_with($path, '/tables')) {
            if ($this->tableExists && ($params['tableId'] ?? null) === TablesDB::TABLE_ID) {
                throw new AppwriteException('Table already exists', 409, 'table_already_exists');
            }

            return $this->table($params);
        }

        return \str_ends_with($path, '/indexes')
            ? $this->index($params)
            : $this->column(\substr($path, \strrpos($path, '/') + 1), $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function table(array $params): array
    {
        return [
            '$id' => $this->string($params['tableId'] ?? ''),
            '$createdAt' => self::TIMESTAMP,
            '$updatedAt' => self::TIMESTAMP,
            '$permissions' => [],
            'databaseId' => $this->string($params['databaseId'] ?? ''),
            'name' => $this->string($params['name'] ?? ''),
            'enabled' => true,
            'rowSecurity' => false,
            'columns' => [],
            'indexes' => [],
            'bytesMax' => 0,
            'bytesUsed' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function index(array $params): array
    {
        return [
            '$id' => $this->string($params['key'] ?? ''),
            '$createdAt' => self::TIMESTAMP,
            '$updatedAt' => self::TIMESTAMP,
            'key' => $this->string($params['key'] ?? ''),
            'type' => $this->string($params['type'] ?? ''),
            'status' => 'available',
            'error' => '',
            'columns' => $params['columns'] ?? [],
            'lengths' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function column(string $type, array $params): array
    {
        return [
            '$createdAt' => self::TIMESTAMP,
            '$updatedAt' => self::TIMESTAMP,
            'key' => $this->string($params['key'] ?? ''),
            'type' => $type,
            'status' => 'available',
            'error' => '',
            'required' => $params['required'] ?? false,
            'size' => $params['size'] ?? 0,
            // Only the datetime column carries a format, and the other columns ignore it.
            'format' => '',
        ];
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
