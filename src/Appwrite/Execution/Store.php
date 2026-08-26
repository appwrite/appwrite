<?php

namespace Appwrite\Execution;

use Psr\Http\Client\ClientInterface;
use Throwable;
use Utopia\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\ClickHouse\Format;
use Utopia\Query\Builder\Statement;
use Utopia\System\System;

/**
 * ClickHouse persistence for function and site executions.
 *
 * Rows are immutable snapshots. ReplacingMergeTree and the version column
 * make updates and deletes append-only, while reads resolve the latest
 * snapshot synchronously with argMax instead of waiting for background merges.
 */
class Store
{
    private const string TABLE = 'executions';

    private const int READY_TTL_SECONDS = 15;

    private const int REPORT_TTL_SECONDS = 60;

    private const int VERSION_SHIFT = 60;

    private const int VERSION_DELETE_RANK = 7;

    private const array VERSION_STATUS_RANKS = [
        'scheduled' => 1,
        'waiting' => 1,
        'processing' => 2,
        'completed' => 3,
        'failed' => 3,
    ];

    private const array COLUMNS = [
        'projectId',
        'id',
        'createdAt',
        'updatedAt',
        'sequence',
        'permissions',
        'readRoles',
        'resourceInternalId',
        'resourceId',
        'resourceType',
        'deploymentInternalId',
        'deploymentId',
        'trigger',
        'status',
        'responseStatusCode',
        'duration',
        'requestMethod',
        'requestPath',
        'document',
        'expiresAt',
        'deleted',
        'version',
    ];

    private const array QUERY_COLUMNS = [
        '$id' => ['id', 'String'],
        '$createdAt' => ['createdAt', 'DateTime'],
        '$updatedAt' => ['updatedAt', 'DateTime'],
        '$sequence' => ['sequence', 'UInt64'],
        'resourceInternalId' => ['resourceInternalId', 'String'],
        'resourceId' => ['resourceId', 'String'],
        'resourceType' => ['resourceType', 'String'],
        'deploymentId' => ['deploymentId', 'String'],
        'trigger' => ['trigger', 'String'],
        'status' => ['status', 'String'],
        'responseStatusCode' => ['responseStatusCode', 'Int32'],
        'duration' => ['duration', 'Float64'],
        'requestMethod' => ['requestMethod', 'String'],
        'requestPath' => ['requestPath', 'String'],
    ];

    private readonly RequestFactory $requestFactory;

    private ?string $host = null;

    private int $port = 8123;

    private string $database = 'default';

    private string $username = 'default';

    private string $password = '';

    private bool $secure = false;

    private bool $ready = false;

    private float $checkedAt = 0.0;

    /** @var array<int, int> */
    private array $lastVersions = [];

    /** @var array<string, int> */
    private static array $lastReports = [];

    public function __construct(
        private readonly bool $enabled,
        private readonly string $dsn,
        private readonly ?ClientInterface $client,
        private readonly int $retention = 1_209_600,
        private readonly ?Logger $logger = null,
    ) {
        $this->requestFactory = new RequestFactory();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setup(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->connect();
        $database = $this->identifier($this->database);
        $table = $this->table();

        $this->query("CREATE DATABASE IF NOT EXISTS {$database}");
        $this->query(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                projectId String,
                id String,
                createdAt String,
                updatedAt String,
                sequence UInt64,
                permissions Array(String),
                readRoles Array(String),
                resourceInternalId String,
                resourceId String,
                resourceType LowCardinality(String),
                deploymentInternalId String,
                deploymentId String,
                trigger LowCardinality(String),
                status LowCardinality(String),
                responseStatusCode Int32,
                duration Float64,
                requestMethod LowCardinality(String),
                requestPath String,
                document String CODEC(ZSTD(3)),
                expiresAt DateTime64(6),
                deleted UInt8,
                version UInt64
            )
            ENGINE = ReplacingMergeTree(version)
            PARTITION BY cityHash64(projectId) % 32
            ORDER BY (projectId, resourceType, resourceInternalId, id)
            SQL);

        $retention = \max(0, $this->retention);
        $this->query(<<<SQL
            ALTER TABLE {$table}
            ADD COLUMN IF NOT EXISTS expiresAt DateTime64(6)
            DEFAULT parseDateTime64BestEffort(createdAt) + INTERVAL {$retention} SECOND
            AFTER document
            SQL);
        if ($retention > 0) {
            $this->query(<<<SQL
                ALTER TABLE {$table}
                MODIFY TTL expiresAt DELETE
                SQL);
        }

        $this->ready = true;
        $this->checkedAt = \microtime(true);
    }

    /** @return array<string, mixed> */
    public function healthCheck(): array
    {
        if (!$this->enabled) {
            return ['healthy' => true, 'enabled' => false, 'schemaReady' => false];
        }

        try {
            $rows = $this->rows($this->query(
                'SELECT count() AS tables FROM system.tables WHERE database = {database:String} AND name = {table:String} FORMAT JSON',
                [
                    'database' => $this->database(),
                    'table' => self::TABLE,
                ]
            ));
            $ready = (int) ($rows[0]['tables'] ?? 0) === 1;
            if ($ready) {
                $this->query('SELECT projectId, id, resourceType, resourceInternalId, expiresAt, deleted, version FROM ' . $this->table() . ' LIMIT 0 FORMAT JSON');
            }

            return [
                'healthy' => true,
                'enabled' => true,
                'schemaReady' => $ready,
                'database' => $this->database,
            ];
        } catch (Throwable $th) {
            return [
                'healthy' => false,
                'enabled' => true,
                'schemaReady' => false,
                'error' => $th->getMessage(),
            ];
        }
    }

    public function isReady(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->ready && (\microtime(true) - $this->checkedAt) < self::READY_TTL_SECONDS) {
            return true;
        }

        $this->ready = ($this->healthCheck()['schemaReady'] ?? false) === true;
        $this->checkedAt = \microtime(true);

        return $this->ready;
    }

    public function create(string $projectId, Document $execution): void
    {
        $this->upsert($projectId, $execution);
    }

    public function update(string $projectId, Document $execution): void
    {
        $this->upsert($projectId, $execution);
    }

    public function upsert(string $projectId, Document $execution): void
    {
        $this->upsertMany($projectId, [$execution]);
    }

    /** @param array<Document> $executions */
    public function upsertMany(string $projectId, array $executions): void
    {
        if (!$this->enabled || $executions === []) {
            return;
        }

        $this->mirror('upsert', function () use ($projectId, $executions): void {
            $rows = [];
            foreach ($executions as $execution) {
                $rows[] = $this->snapshot($projectId, $execution, false);
            }

            $this->insert($rows);
        });
    }

    public function delete(string $projectId, Document $execution): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->mirror('delete', fn () => $this->insert([$this->snapshot($projectId, $execution, true)]));
    }

    public function deleteProject(string $projectId): void
    {
        $this->deleteWhere($projectId);
    }

    public function deleteByResource(
        string $projectId,
        string $resourceInternalId,
        string $resourceType,
        ?string $createdBefore = null,
    ): void {
        $this->deleteWhere($projectId, $resourceInternalId, $resourceType, $createdBefore);
    }

    public function deleteBefore(string $projectId, string $createdBefore): void
    {
        $this->deleteWhere($projectId, createdBefore: $createdBefore);
    }

    /**
     * @param list<string>|null $roles Null skips document authorization.
     */
    public function get(string $projectId, string $executionId, ?array $roles = null): Document
    {
        if (!$this->enabled) {
            return new Document();
        }

        $params = [
            'projectId' => $projectId,
            'executionId' => $executionId,
        ];
        $permission = $this->permissionSql($roles, $params);
        $latest = $this->latestSql('source.projectId = {projectId:String} AND source.id = {executionId:String}');
        $builder = $this->builder()
            ->from('__latest__')
            ->select(['document'])
            ->whereRaw("deleted = 0{$permission}")
            ->limit(1);
        $rows = $this->rows($this->select($builder->build(), $latest, $params));

        return $this->document($rows[0]['document'] ?? null);
    }

    /**
     * @param array<Query> $queries
     * @param list<string>|null $roles Null skips document authorization.
     * @return array<Document>
     */
    public function find(string $projectId, array $queries, ?array $roles = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        $params = ['projectId' => $projectId];
        [$filters, $order, $limit, $offset, $cursor] = $this->compileQueries($queries, $params);
        $permission = $this->permissionSql($roles, $params);
        if ($permission !== '') {
            $filters[] = \substr($permission, 5);
        }

        if ($cursor instanceof Query) {
            $cursorSql = $this->cursorSql($cursor, $order, $params);
            if ($cursorSql !== '') {
                $filters[] = $cursorSql;
            }
        }

        $where = $filters === [] ? '' : ' WHERE ' . \implode(' AND ', $filters);
        $before = $cursor?->getMethod() === Query::TYPE_CURSOR_BEFORE;
        $orderSql = $this->orderSql($order, $before);
        $latest = $this->latestSql($this->latestWhere($queries, $params));
        $builder = $this->builder()
            ->from('__latest__')
            ->select(['document']);
        if ($where !== '') {
            $builder->whereRaw(\substr($where, 7));
        }
        $builder->orderByRaw(\substr($orderSql, 9))
            ->limit($limit)
            ->offset($offset);
        $response = $this->select($builder->build(), $latest, $params);

        $documents = [];
        foreach ($this->rows($response) as $row) {
            $document = $this->document($row['document'] ?? null);
            if (!$document->isEmpty()) {
                $documents[] = $document;
            }
        }

        if ($before) {
            $documents = \array_reverse($documents);
        }

        return $documents;
    }

    /**
     * @param array<Query> $queries
     * @param list<string>|null $roles Null skips document authorization.
     */
    public function count(string $projectId, array $queries, int $max, ?array $roles = null): int
    {
        if (!$this->enabled) {
            return 0;
        }

        $params = ['projectId' => $projectId, 'max' => $max];
        [$filters] = $this->compileQueries($queries, $params);
        $permission = $this->permissionSql($roles, $params);
        if ($permission !== '') {
            $filters[] = \substr($permission, 5);
        }

        $where = $filters === [] ? '' : ' WHERE ' . \implode(' AND ', $filters);
        $latest = $this->latestSql($this->latestWhere($queries, $params));
        $builder = $this->builder()
            ->from('__latest__')
            ->selectRaw('least(count(), {max:UInt64}) AS total');
        if ($where !== '') {
            $builder->whereRaw(\substr($where, 7));
        }
        $rows = $this->rows($this->select($builder->build(), $latest, $params));

        return (int) ($rows[0]['total'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $projectId, Document $execution, bool $deleted): array
    {
        $createdAt = $execution->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            $createdAt = DateTime::now();
            $execution->setAttribute('$createdAt', $createdAt);
        }

        $updatedAt = $execution->getUpdatedAt();
        if ($updatedAt === null || $updatedAt === '') {
            $updatedAt = $createdAt;
            $execution->setAttribute('$updatedAt', $updatedAt);
        }

        $permissions = \array_values($execution->getPermissions());

        $readRoles = [];
        foreach ($permissions as $permission) {
            if (\preg_match('/^read\("(.+)"\)$/', $permission, $matches) === 1) {
                $readRoles[] = $matches[1];
            }
        }

        return [
            'projectId' => $projectId,
            'id' => $execution->getId(),
            'createdAt' => $this->date($createdAt),
            'updatedAt' => $this->date($updatedAt),
            'sequence' => (int) $execution->getSequence(),
            'permissions' => $permissions,
            'readRoles' => \array_values(\array_unique($readRoles)),
            'resourceInternalId' => (string) $execution->getAttribute('resourceInternalId', ''),
            'resourceId' => (string) $execution->getAttribute('resourceId', ''),
            'resourceType' => (string) $execution->getAttribute('resourceType', ''),
            'deploymentInternalId' => (string) $execution->getAttribute('deploymentInternalId', ''),
            'deploymentId' => (string) $execution->getAttribute('deploymentId', ''),
            'trigger' => (string) $execution->getAttribute('trigger', ''),
            'status' => (string) $execution->getAttribute('status', ''),
            'responseStatusCode' => (int) $execution->getAttribute('responseStatusCode', 0),
            'duration' => (float) $execution->getAttribute('duration', 0),
            'requestMethod' => (string) $execution->getAttribute('requestMethod', ''),
            'requestPath' => (string) $execution->getAttribute('requestPath', ''),
            'document' => \json_encode($execution->getArrayCopy(), JSON_THROW_ON_ERROR),
            'expiresAt' => $this->expiresAt($createdAt, $deleted),
            'deleted' => $deleted ? 1 : 0,
            'version' => $this->executionVersion($execution, $deleted),
        ];
    }

    /** @param array<array<string, mixed>> $rows */
    private function insert(array $rows): void
    {
        $this->insertRows($this->database() . '.' . self::TABLE, self::COLUMNS, $rows);
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function insertRows(string $table, array $columns, array $rows): void
    {
        $statement = (new ClickHouseBuilder())
            ->from($table)
            ->bulkInsert(Format::JSONEachRow, $rows, $columns);
        $url = $this->url() . '?' . \http_build_query(['query' => $statement->query]);
        $body = $statement->body;
        $request = $this->requestFactory->body(Method::POST, $url, $body, 'application/x-ndjson', $this->headers());

        try {
            $response = $this->client()->sendRequest($request);
        } catch (Throwable $th) {
            throw new \RuntimeException('ClickHouse execution insert failed: ' . $th->getMessage(), previous: $th);
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ClickHouse execution insert failed with HTTP ' . $response->getStatusCode() . ': ' . (string) $response->getBody());
        }
    }

    private function deleteWhere(
        string $projectId,
        ?string $resourceInternalId = null,
        ?string $resourceType = null,
        ?string $createdBefore = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->mirror('delete', function () use ($projectId, $resourceInternalId, $resourceType, $createdBefore): void {
            $params = ['projectId' => $projectId];
            $conditions = ['source.projectId = {projectId:String}'];
            if ($resourceInternalId !== null) {
                $params['resourceInternalId'] = $resourceInternalId;
                $conditions[] = 'source.resourceInternalId = {resourceInternalId:String}';
            }
            if ($resourceType !== null) {
                $params['resourceType'] = $resourceType;
                $conditions[] = 'source.resourceType = {resourceType:String}';
            }
            if ($createdBefore !== null) {
                $params['createdBefore'] = $this->date($createdBefore);
                $conditions[] = 'source.createdAt < {createdBefore:String}';
            }

            $latest = $this->latestSql(\implode(' AND ', $conditions));
            $columns = \implode(', ', \array_filter(
                self::COLUMNS,
                fn (string $column) => !\in_array($column, ['expiresAt', 'deleted', 'version'], true)
            ));
            $deleteVersionBase = $this->versionBase(self::VERSION_DELETE_RANK);
            $retention = \max(0, $this->retention);
            $this->query(<<<SQL
                INSERT INTO {$this->table()} ({$columns}, expiresAt, deleted, version)
                SELECT {$columns}, now64(6) + INTERVAL {$retention} SECOND, 1, toUInt64({$deleteVersionBase}) + toUInt64(toUnixTimestamp64Micro(now64(6)))
                FROM ({$latest})
                WHERE deleted = 0
                SQL, $params);
        });
    }

    private function latestSql(string $where): string
    {
        $columns = [];
        foreach (self::COLUMNS as $column) {
            if (\in_array($column, ['projectId', 'id'], true)) {
                $columns[] = "source.{$column} AS {$column}";
                continue;
            }
            if ($column === 'version') {
                continue;
            }
            $columns[] = "argMax(source.{$column}, source.version) AS {$column}";
        }

        return 'SELECT ' . \implode(', ', $columns)
            . ' FROM ' . $this->table()
            . " AS source WHERE {$where} GROUP BY source.projectId, source.id";
    }

    /**
     * Resource identity is immutable and part of the sorting key, so applying
     * the route's internal resource filters before aggregation avoids scanning
     * and grouping every execution in a large project.
     *
     * @param array<Query> $queries
     * @param array<string, mixed> $params
     */
    private function latestWhere(array $queries, array &$params): string
    {
        $conditions = ['source.projectId = {projectId:String}'];
        foreach ($queries as $query) {
            if ($query->getMethod() !== Query::TYPE_EQUAL
                || !\in_array($query->getAttribute(), ['resourceInternalId', 'resourceType'], true)) {
                continue;
            }

            [$column, $type] = $this->column($query->getAttribute());
            $parameters = [];
            foreach ($query->getValues() as $value) {
                $parameters[] = $this->parameter($type, $value, $params);
            }
            if ($parameters !== []) {
                $conditions[] = "source.{$column} IN (" . \implode(', ', $parameters) . ')';
            }
        }

        return \implode(' AND ', $conditions);
    }

    /**
     * @param array<Query> $queries
     * @param array<string, mixed> $params
     * @return array{0: list<string>, 1: list<Query>, 2: int, 3: int, 4: ?Query}
     */
    private function compileQueries(array $queries, array &$params): array
    {
        $filters = ['deleted = 0'];
        $order = [];
        $limit = 25;
        $offset = 0;
        $cursor = null;

        foreach ($queries as $query) {
            switch ($query->getMethod()) {
                case Query::TYPE_ORDER_ASC:
                case Query::TYPE_ORDER_DESC:
                case Query::TYPE_ORDER_RANDOM:
                    $order[] = $query;
                    break;
                case Query::TYPE_LIMIT:
                    $limit = \max(1, (int) $query->getValue(25));
                    break;
                case Query::TYPE_OFFSET:
                    $offset = \max(0, (int) $query->getValue(0));
                    break;
                case Query::TYPE_CURSOR_AFTER:
                case Query::TYPE_CURSOR_BEFORE:
                    $cursor = $query;
                    break;
                case Query::TYPE_SELECT:
                    break;
                default:
                    $filters[] = $this->filterSql($query, $params);
                    break;
            }
        }

        if ($order === []) {
            $order[] = Query::orderDesc('$sequence');
        }

        $unique = \array_filter(
            $order,
            fn (Query $query) => \in_array($query->getAttribute(), ['$id', '$sequence'], true)
        ) !== [];
        if (!$unique) {
            $first = $order[0];
            $method = \in_array($first->getAttribute(), ['$createdAt', '$updatedAt'], true)
                ? $first->getMethod()
                : Query::TYPE_ORDER_ASC;
            $order[] = $method === Query::TYPE_ORDER_DESC
                ? Query::orderDesc('$sequence')
                : Query::orderAsc('$sequence');
        }

        return [$filters, $order, $limit, $offset, $cursor];
    }

    /** @param array<string, mixed> $params */
    private function filterSql(Query $query, array &$params): string
    {
        $method = $query->getMethod();
        if ($method === Query::TYPE_AND || $method === Query::TYPE_OR) {
            $parts = [];
            foreach ($query->getValues() as $nested) {
                if (!$nested instanceof Query) {
                    throw new \InvalidArgumentException('Invalid nested execution query');
                }
                $parts[] = $this->filterSql($nested, $params);
            }
            if ($parts === []) {
                throw new \InvalidArgumentException('Empty logical execution query');
            }
            return '(' . \implode($method === Query::TYPE_AND ? ' AND ' : ' OR ', $parts) . ')';
        }
        if ($method === Query::TYPE_EXISTS || $method === Query::TYPE_NOT_EXISTS) {
            $parts = [];
            foreach ($query->getValues() as $attribute) {
                [$column] = $this->column((string) $attribute);
                $parts[] = $column . ($method === Query::TYPE_EXISTS ? " != ''" : " = ''");
            }
            if ($parts === []) {
                throw new \InvalidArgumentException('Empty execution exists query');
            }

            return '(' . \implode(' AND ', $parts) . ')';
        }

        [$column, $type] = $this->column($query->getAttribute());
        $values = $query->getValues();
        $parameters = [];
        foreach ($values as $value) {
            $parameters[] = $this->parameter($type, $value, $params);
        }

        return match ($method) {
            Query::TYPE_EQUAL => "{$column} IN (" . \implode(', ', $parameters) . ')',
            Query::TYPE_NOT_EQUAL => "{$column} NOT IN (" . \implode(', ', $parameters) . ')',
            Query::TYPE_LESSER => "{$column} < {$parameters[0]}",
            Query::TYPE_LESSER_EQUAL => "{$column} <= {$parameters[0]}",
            Query::TYPE_GREATER => "{$column} > {$parameters[0]}",
            Query::TYPE_GREATER_EQUAL => "{$column} >= {$parameters[0]}",
            Query::TYPE_BETWEEN => "{$column} BETWEEN {$parameters[0]} AND {$parameters[1]}",
            Query::TYPE_NOT_BETWEEN => "{$column} NOT BETWEEN {$parameters[0]} AND {$parameters[1]}",
            Query::TYPE_CONTAINS => $this->containsSql($column, $parameters, false),
            Query::TYPE_CONTAINS_ANY => $this->containsSql($column, $parameters, false),
            Query::TYPE_CONTAINS_ALL => $this->containsAllSql($column, $parameters),
            Query::TYPE_NOT_CONTAINS => $this->containsSql($column, $parameters, true),
            Query::TYPE_SEARCH => "positionCaseInsensitive({$column}, {$parameters[0]}) > 0",
            Query::TYPE_NOT_SEARCH => "positionCaseInsensitive({$column}, {$parameters[0]}) = 0",
            Query::TYPE_STARTS_WITH => "startsWith({$column}, {$parameters[0]})",
            Query::TYPE_NOT_STARTS_WITH => "NOT startsWith({$column}, {$parameters[0]})",
            Query::TYPE_ENDS_WITH => "endsWith({$column}, {$parameters[0]})",
            Query::TYPE_NOT_ENDS_WITH => "NOT endsWith({$column}, {$parameters[0]})",
            Query::TYPE_REGEX => "match({$column}, {$parameters[0]})",
            Query::TYPE_IS_NULL => "{$column} = ''",
            Query::TYPE_IS_NOT_NULL => "{$column} != ''",
            default => throw new \InvalidArgumentException("Unsupported execution query method: {$method}"),
        };
    }

    /** @param list<string> $parameters */
    private function containsSql(string $column, array $parameters, bool $negated): string
    {
        $parts = \array_map(
            fn (string $parameter) => "positionCaseSensitive({$column}, {$parameter}) " . ($negated ? '= 0' : '> 0'),
            $parameters
        );

        return '(' . \implode($negated ? ' AND ' : ' OR ', $parts) . ')';
    }

    /** @param list<string> $parameters */
    private function containsAllSql(string $column, array $parameters): string
    {
        $parts = \array_map(
            fn (string $parameter) => "positionCaseSensitive({$column}, {$parameter}) > 0",
            $parameters
        );

        return '(' . \implode(' AND ', $parts) . ')';
    }

    /** @param list<Query> $order */
    private function orderSql(array $order, bool $before): string
    {
        $parts = [];
        foreach ($order as $query) {
            if ($query->getMethod() === Query::TYPE_ORDER_RANDOM) {
                $parts[] = 'rand()';
                continue;
            }
            [$column] = $this->column($query->getAttribute());
            $ascending = $query->getMethod() === Query::TYPE_ORDER_ASC;
            if ($before) {
                $ascending = !$ascending;
            }
            $parts[] = $column . ($ascending ? ' ASC' : ' DESC');
        }

        return 'ORDER BY ' . \implode(', ', $parts);
    }

    /**
     * @param list<Query> $order
     * @param array<string, mixed> $params
     */
    private function cursorSql(Query $cursor, array $order, array &$params): string
    {
        $document = $cursor->getValue();
        if (!$document instanceof Document || $document->isEmpty()) {
            return '';
        }

        if ($order[0]->getMethod() === Query::TYPE_ORDER_RANDOM) {
            return '';
        }

        $after = $cursor->getMethod() === Query::TYPE_CURSOR_AFTER;
        $branches = [];
        $equal = [];

        foreach ($order as $query) {
            [$column, $type] = $this->column($query->getAttribute());
            $attribute = $query->getAttribute();
            $value = match ($attribute) {
                '$id' => $document->getId(),
                '$createdAt' => $document->getCreatedAt(),
                '$updatedAt' => $document->getUpdatedAt(),
                '$sequence' => $document->getSequence(),
                default => $document->getAttribute($attribute),
            };
            if ($value === null) {
                throw new OrderException(
                    message: "Order attribute '{$attribute}' is empty",
                    attribute: $attribute,
                );
            }

            $parameter = $this->parameter($type, $value, $params);
            $ascending = $query->getMethod() === Query::TYPE_ORDER_ASC;
            $operator = ($ascending === $after) ? '>' : '<';
            $conditions = [...$equal, "{$column} {$operator} {$parameter}"];
            $branches[] = '(' . \implode(' AND ', $conditions) . ')';
            $equal[] = "{$column} = {$parameter}";
        }

        return '(' . \implode(' OR ', $branches) . ')';
    }

    /** @return array{0: string, 1: string} */
    private function column(string $attribute): array
    {
        if (!isset(self::QUERY_COLUMNS[$attribute])) {
            throw new \InvalidArgumentException("Unsupported execution query attribute: {$attribute}");
        }

        return self::QUERY_COLUMNS[$attribute];
    }

    /** @param array<string, mixed> $params */
    private function parameter(string $type, mixed $value, array &$params): string
    {
        $key = 'p' . \count($params);
        $params[$key] = match ($type) {
            'UInt64', 'Int32' => (int) $value,
            'Float64' => (float) $value,
            'DateTime' => $this->date((string) $value),
            default => (string) $value,
        };
        $placeholder = $type === 'DateTime' ? 'String' : $type;

        return "{{$key}:{$placeholder}}";
    }

    /** @param list<string>|null $roles @param array<string, mixed> $params */
    private function permissionSql(?array $roles, array &$params): string
    {
        if ($roles === null) {
            return '';
        }
        if ($roles === []) {
            return ' AND 0';
        }

        $parameters = [];
        foreach ($roles as $role) {
            $parameters[] = $this->parameter('String', $role, $params);
        }

        return ' AND hasAny(readRoles, [' . \implode(', ', $parameters) . '])';
    }

    private function document(mixed $json): Document
    {
        if (!\is_string($json) || $json === '') {
            return new Document();
        }

        $data = \json_decode($json, true);
        return \is_array($data) ? new Document($data) : new Document();
    }

    private function executionVersion(Document $execution, bool $deleted): int
    {
        $rank = $deleted
            ? self::VERSION_DELETE_RANK
            : (self::VERSION_STATUS_RANKS[$execution->getAttribute('status', '')] ?? 2);

        return $this->nextVersion($rank);
    }

    private function expiresAt(string $createdAt, bool $deleted): string
    {
        try {
            $date = new \DateTime($deleted ? 'now' : $createdAt);
        } catch (\Throwable) {
            $date = new \DateTime();
        }
        $date->modify('+' . \max(0, $this->retention) . ' seconds');

        return DateTime::format($date);
    }

    private function date(string $value): string
    {
        return DateTime::setTimezone($value);
    }

    private function nextVersion(int $rank): int
    {
        $version = $this->versionBase($rank) + (int) \floor(\microtime(true) * 1_000_000);
        $this->lastVersions[$rank] = \max($version, ($this->lastVersions[$rank] ?? 0) + 1);

        return $this->lastVersions[$rank];
    }

    private function versionBase(int $rank): int
    {
        return $rank << self::VERSION_SHIFT;
    }

    private function builder(): ClickHouseBuilder
    {
        return (new ClickHouseBuilder())->useNamedBindings();
    }

    private function mirror(string $operation, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $th) {
            Console::warning("ClickHouse execution mirror {$operation} failed: {$th->getMessage()}");
            $this->report($operation, $th);
        }
    }

    private function report(string $operation, Throwable $th): void
    {
        if ($this->logger === null) {
            return;
        }

        $now = \time();
        if ((self::$lastReports[$operation] ?? 0) + self::REPORT_TTL_SECONDS > $now) {
            return;
        }
        self::$lastReports[$operation] = $now;

        $log = new Log();
        $log->setNamespace('executions');
        $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
        $log->setVersion(System::getEnv('_APP_VERSION', 'UNKNOWN'));
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage("ClickHouse execution mirror {$operation} failed: {$th->getMessage()}");
        $log->setAction("executions.mirror.{$operation}");
        $log->setEnvironment(System::getEnv('_APP_ENV', 'development') === 'production'
            ? Log::ENVIRONMENT_PRODUCTION
            : Log::ENVIRONMENT_STAGING);
        $log->addTag('operation', $operation);
        $log->addTag('exception', $th::class);
        $log->addTag('code', $th->getCode());
        $log->addExtra('file', $th->getFile());
        $log->addExtra('line', $th->getLine());
        $log->addExtra('trace', $th->getTraceAsString());

        try {
            $this->logger->addLog($log);
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $params */
    private function select(Statement $statement, string $source, array $params): string
    {
        $sql = \str_replace('FROM `__latest__`', "FROM ({$source})", $statement->query) . ' FORMAT JSON';

        return $this->query($sql, \array_merge($params, $statement->namedBindings ?? []));
    }

    /** @param array<string, mixed> $params */
    private function query(string $sql, array $params = []): string
    {
        $this->connect();
        $parts = ['query' => $sql];
        foreach ($params as $key => $value) {
            $parts['param_' . $key] = (string) $value;
        }
        $request = $this->requestFactory->multipart(Method::POST, $this->url(), $parts, $this->headers());

        try {
            $response = $this->client()->sendRequest($request);
        } catch (Throwable $th) {
            throw new \RuntimeException('ClickHouse execution query failed: ' . $th->getMessage(), previous: $th);
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ClickHouse execution query failed with HTTP ' . $response->getStatusCode() . ': ' . $body);
        }

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $response): array
    {
        $data = \json_decode($response, true);
        return \is_array($data) && \is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    private function connect(): void
    {
        if ($this->host !== null) {
            return;
        }
        if ($this->dsn === '') {
            throw new \RuntimeException('Execution ClickHouse connection is not configured');
        }
        if ($this->client === null) {
            throw new \RuntimeException('Execution ClickHouse HTTP client is not configured');
        }

        try {
            $dsn = new DSN($this->dsn);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Invalid execution ClickHouse DSN: ' . $exception->getMessage(), previous: $exception);
        }

        $this->host = $dsn->getHost();
        $this->port = (int) ($dsn->getPort() ?: 8123);
        $this->database = \ltrim($dsn->getPath(), '/') ?: 'default';
        $this->username = $dsn->getUser() ?: 'default';
        $this->password = $dsn->getPassword();
        $this->secure = \strtolower((string) $dsn->getParam('secure', '')) === 'true';

        $this->identifier($this->database);
    }

    private function database(): string
    {
        $this->connect();
        return $this->database;
    }

    private function table(): string
    {
        return $this->identifier($this->database()) . '.' . $this->identifier(self::TABLE);
    }

    private function identifier(string $value): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \RuntimeException("Invalid ClickHouse identifier: {$value}");
        }

        return '`' . $value . '`';
    }

    private function url(): string
    {
        $this->connect();
        return ($this->secure ? 'https' : 'http') . "://{$this->host}:{$this->port}/";
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-ClickHouse-User' => $this->username,
            'X-ClickHouse-Key' => $this->password,
            'X-ClickHouse-Database' => $this->database,
        ];
    }

    private function client(): ClientInterface
    {
        return $this->client ?? throw new \RuntimeException('Execution ClickHouse HTTP client is not configured');
    }
}
