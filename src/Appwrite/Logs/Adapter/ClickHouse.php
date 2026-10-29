<?php

namespace Appwrite\Logs\Adapter;

use Appwrite\Logs\Adapter;
use Psr\Http\Client\ClientInterface;
use Throwable;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\ClickHouse\Format;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Method as QueryMethod;

/**
 * ClickHouse persistence for log lines.
 *
 * Rows are append-only line snapshots written by a collector such as Alloy
 * (or tests). Unlike executions there is no versioning — MergeTree + TTL on
 * expiresAt is enough.
 */
class ClickHouse extends Adapter
{
    private const array COLUMNS = [
        'projectId',
        'id',
        'resourceType',
        'resourceId',
        'resourceInternalId',
        'deploymentId',
        'region',
        'timestamp',
        'stream',
        'message',
        'expiresAt',
    ];

    private const array QUERY_COLUMNS = [
        '$id' => ['id', 'String'],
        'resourceType' => ['resourceType', 'String'],
        'resourceId' => ['resourceId', 'String'],
        'resourceInternalId' => ['resourceInternalId', 'String'],
        'deploymentId' => ['deploymentId', 'String'],
        'region' => ['region', 'String'],
        'timestamp' => ['timestamp', 'DateTime'],
        'stream' => ['stream', 'String'],
        'message' => ['message', 'String'],
    ];

    private readonly RequestFactory $requestFactory;

    private ?string $host = null;

    private int $port = 8123;

    private string $database = 'default';

    private string $username = 'default';

    private string $password = '';

    private bool $secure = false;

    public function __construct(
        private readonly string $dsn,
        private readonly ?ClientInterface $client,
        private readonly string $table = 'logs',
        private readonly int $retention = 1_209_600,
    ) {
        $this->requestFactory = new RequestFactory();
        $this->identifier($this->table);
    }

    public function setup(): void
    {
        $this->connect();
        $database = $this->identifier($this->database);
        $table = $this->quotedTable();

        $this->query("CREATE DATABASE IF NOT EXISTS {$database}");
        $this->query(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                projectId String,
                id String,
                resourceType LowCardinality(String),
                resourceId String,
                resourceInternalId String,
                deploymentId String,
                region LowCardinality(String),
                timestamp DateTime64(6),
                stream LowCardinality(String),
                message String CODEC(ZSTD(3)),
                expiresAt DateTime64(6)
            )
            ENGINE = MergeTree
            PARTITION BY cityHash64(projectId) % 32
            ORDER BY (projectId, deploymentId, timestamp, id)
            SQL);

        $this->query(<<<SQL
            ALTER TABLE {$table}
            ADD COLUMN IF NOT EXISTS region LowCardinality(String) DEFAULT ''
            AFTER deploymentId
            SQL);

        $retention = \max(0, $this->retention);
        $this->query(<<<SQL
            ALTER TABLE {$table}
            ADD COLUMN IF NOT EXISTS expiresAt DateTime64(6)
            DEFAULT timestamp + INTERVAL {$retention} SECOND
            AFTER message
            SQL);
        if ($retention > 0) {
            $this->query(<<<SQL
                ALTER TABLE {$table}
                MODIFY TTL expiresAt DELETE
                SQL);
        }
    }

    /** @return array<string, mixed> */
    public function healthCheck(): array
    {
        try {
            $rows = $this->rows($this->query(
                'SELECT count() AS tables FROM system.tables WHERE database = {database:String} AND name = {table:String} FORMAT JSON',
                [
                    'database' => $this->database(),
                    'table' => $this->table,
                ]
            ));
            $ready = (int) ($rows[0]['tables'] ?? 0) === 1;
            if ($ready) {
                $this->query('SELECT projectId, id, deploymentId, region, timestamp, stream, expiresAt FROM ' . $this->quotedTable() . ' LIMIT 0 FORMAT JSON');
            }

            return [
                'healthy' => true,
                'schemaReady' => $ready,
                'database' => $this->database,
            ];
        } catch (Throwable $th) {
            return [
                'healthy' => false,
                'schemaReady' => false,
                'error' => $th->getMessage(),
            ];
        }
    }

    /** @param array<Document> $logs */
    public function upsert(string $projectId, array $logs): void
    {
        if ($logs === []) {
            return;
        }

        $rows = [];
        foreach ($logs as $log) {
            $rows[] = $this->snapshot($projectId, $log);
        }

        $this->insert($rows);
    }

    public function delete(
        string $projectId,
        ?string $resourceType = null,
        ?string $resourceInternalId = null,
    ): void {
        $params = ['projectId' => $projectId];
        $conditions = ['projectId = {projectId:String}'];
        if ($resourceType !== null) {
            $params['resourceType'] = $resourceType;
            $conditions[] = 'resourceType = {resourceType:String}';
        }
        if ($resourceInternalId !== null) {
            $params['resourceInternalId'] = $resourceInternalId;
            $conditions[] = 'resourceInternalId = {resourceInternalId:String}';
        }

        $where = \implode(' AND ', $conditions);
        // Wait for the rows to be masked, whatever the server profile defaults to.
        $this->query(
            'DELETE FROM ' . $this->quotedTable() . " WHERE {$where} SETTINGS lightweight_deletes_sync = 2",
            $params
        );
    }

    public function get(string $projectId, string $logId): Document
    {
        $params = [
            'projectId' => $projectId,
            'logId' => $logId,
        ];
        $builder = $this->builder()
            ->from($this->relation())
            ->select(self::COLUMNS)
            ->whereRaw('projectId = {projectId:String} AND id = {logId:String}')
            ->limit(1);
        $rows = $this->rows($this->select($builder->build(), $params));

        return $this->document($rows[0] ?? null);
    }

    /**
     * @param array<Query> $queries
     * @return array<Document>
     */
    public function find(string $projectId, array $queries): array
    {
        $params = ['projectId' => $projectId];
        [$filters, $order, $limit, $offset, $cursor] = $this->compileQueries($queries, $params);
        $filters[] = 'projectId = {projectId:String}';

        if ($cursor instanceof Query) {
            $cursorSql = $this->cursorSql($cursor, $order, $params);
            if ($cursorSql !== '') {
                $filters[] = $cursorSql;
            }
        }

        $before = $cursor?->getMethod() === QueryMethod::CursorBefore;
        $orderSql = $this->orderSql($order, $before);
        $builder = $this->builder()
            ->from($this->relation())
            ->select(self::COLUMNS)
            ->whereRaw(\implode(' AND ', $filters))
            ->orderByRaw(\substr($orderSql, 9))
            ->limit($limit)
            ->offset($offset);
        $response = $this->select($builder->build(), $params);

        $documents = [];
        foreach ($this->rows($response) as $row) {
            $document = $this->document($row);
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
     */
    public function count(string $projectId, array $queries, int $max): int
    {
        $params = ['projectId' => $projectId, 'max' => $max];
        [$filters] = $this->compileQueries($queries, $params);
        $filters[] = 'projectId = {projectId:String}';

        $builder = $this->builder()
            ->from($this->relation())
            ->selectRaw('least(count(), {max:UInt64}) AS total')
            ->whereRaw(\implode(' AND ', $filters));
        $rows = $this->rows($this->select($builder->build(), $params));

        return (int) ($rows[0]['total'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $projectId, Document $log): array
    {
        $id = $log->getId();
        if ($id === '') {
            throw new \InvalidArgumentException('Log id is required');
        }

        $timestamp = (string) $log->getAttribute('timestamp', '');
        if ($timestamp === '') {
            $timestamp = DateTime::now();
            $log->setAttribute('timestamp', $timestamp);
        }

        $stream = (string) $log->getAttribute('stream', 'stdout');
        if ($stream !== 'stdout' && $stream !== 'stderr') {
            throw new \InvalidArgumentException('Log stream must be stdout or stderr');
        }

        return [
            'projectId' => $projectId,
            'id' => $id,
            'resourceType' => (string) $log->getAttribute('resourceType', ''),
            'resourceId' => (string) $log->getAttribute('resourceId', ''),
            'resourceInternalId' => (string) $log->getAttribute('resourceInternalId', ''),
            'deploymentId' => (string) $log->getAttribute('deploymentId', ''),
            'region' => (string) $log->getAttribute('region', ''),
            'timestamp' => $this->date($timestamp),
            'stream' => $stream,
            'message' => (string) $log->getAttribute('message', ''),
            'expiresAt' => $this->expiresAt($timestamp),
        ];
    }

    /** @param array<array<string, mixed>> $rows */
    private function insert(array $rows): void
    {
        $this->insertRows($this->relation(), self::COLUMNS, $rows);
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
            throw new \RuntimeException('ClickHouse log insert failed: ' . $th->getMessage(), previous: $th);
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ClickHouse log insert failed with HTTP ' . $response->getStatusCode() . ': ' . (string) $response->getBody());
        }
    }

    /**
     * @param array<Query> $queries
     * @param array<string, mixed> $params
     * @return array{0: list<string>, 1: list<Query>, 2: int, 3: int, 4: ?Query}
     */
    private function compileQueries(array $queries, array &$params): array
    {
        $filters = [];
        $order = [];
        $limit = 25;
        $offset = 0;
        $cursor = null;

        foreach ($queries as $query) {
            switch ($query->getMethod()) {
                case QueryMethod::OrderAsc:
                case QueryMethod::OrderDesc:
                case QueryMethod::OrderRandom:
                    $order[] = $query;
                    break;
                case QueryMethod::Limit:
                    $limit = \max(1, (int) $query->getValue(25));
                    break;
                case QueryMethod::Offset:
                    $offset = \max(0, (int) $query->getValue(0));
                    break;
                case QueryMethod::CursorAfter:
                case QueryMethod::CursorBefore:
                    $cursor = $query;
                    break;
                case QueryMethod::Select:
                    break;
                default:
                    $filters[] = $this->filterSql($query, $params);
                    break;
            }
        }

        if ($order === []) {
            $order[] = Query::orderDesc('timestamp');
        }

        $hasId = false;
        foreach ($order as $query) {
            if ($query->getAttribute() === '$id') {
                $hasId = true;
                break;
            }
        }
        if (!$hasId) {
            $last = $order[\array_key_last($order)];
            $order[] = $last->getMethod() === QueryMethod::OrderAsc
                ? Query::orderAsc('$id')
                : Query::orderDesc('$id');
        }

        return [$filters, $order, $limit, $offset, $cursor];
    }

    /** @param array<string, mixed> $params */
    private function filterSql(Query $query, array &$params): string
    {
        $method = $query->getMethod();
        if ($method === QueryMethod::And || $method === QueryMethod::Or) {
            $parts = [];
            foreach ($query->getValues() as $nested) {
                if (!$nested instanceof Query) {
                    throw new \InvalidArgumentException('Invalid nested log query');
                }
                $parts[] = $this->filterSql($nested, $params);
            }
            if ($parts === []) {
                throw new \InvalidArgumentException('Empty logical log query');
            }

            return '(' . \implode($method === QueryMethod::And ? ' AND ' : ' OR ', $parts) . ')';
        }

        [$column, $type] = $this->column($query->getAttribute());
        $values = $query->getValues();
        $parameters = [];
        foreach ($values as $value) {
            $parameters[] = $this->parameter($type, $value, $params);
        }

        return match ($method) {
            QueryMethod::Equal => "{$column} IN (" . \implode(', ', $parameters) . ')',
            QueryMethod::NotEqual => "{$column} NOT IN (" . \implode(', ', $parameters) . ')',
            QueryMethod::LessThan => "{$column} < {$parameters[0]}",
            QueryMethod::LessThanEqual => "{$column} <= {$parameters[0]}",
            QueryMethod::GreaterThan => "{$column} > {$parameters[0]}",
            QueryMethod::GreaterThanEqual => "{$column} >= {$parameters[0]}",
            QueryMethod::Between => "{$column} BETWEEN {$parameters[0]} AND {$parameters[1]}",
            QueryMethod::NotBetween => "{$column} NOT BETWEEN {$parameters[0]} AND {$parameters[1]}",
            QueryMethod::Search => "positionCaseInsensitive({$column}, {$parameters[0]}) > 0",
            QueryMethod::NotSearch => "positionCaseInsensitive({$column}, {$parameters[0]}) = 0",
            QueryMethod::StartsWith => "startsWith({$column}, {$parameters[0]})",
            QueryMethod::NotStartsWith => "NOT startsWith({$column}, {$parameters[0]})",
            QueryMethod::EndsWith => "endsWith({$column}, {$parameters[0]})",
            QueryMethod::NotEndsWith => "NOT endsWith({$column}, {$parameters[0]})",
            QueryMethod::Contains => $this->containsSql($column, $parameters, false),
            QueryMethod::ContainsAny => $this->containsSql($column, $parameters, false),
            QueryMethod::ContainsAll => $this->containsAllSql($column, $parameters),
            QueryMethod::NotContains => $this->containsSql($column, $parameters, true),
            QueryMethod::IsNull => "{$column} = ''",
            QueryMethod::IsNotNull => "{$column} != ''",
            default => throw new \InvalidArgumentException("Unsupported log query method: {$method->value}"),
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
            if ($query->getMethod() === QueryMethod::OrderRandom) {
                $parts[] = 'rand()';
                continue;
            }
            [$column] = $this->column($query->getAttribute());
            $ascending = $query->getMethod() === QueryMethod::OrderAsc;
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

        if ($order[0]->getMethod() === QueryMethod::OrderRandom) {
            return '';
        }

        $after = $cursor->getMethod() === QueryMethod::CursorAfter;
        $branches = [];
        $equal = [];

        foreach ($order as $query) {
            [$column, $type] = $this->column($query->getAttribute());
            $attribute = $query->getAttribute();
            $value = match ($attribute) {
                '$id' => $document->getId(),
                default => $document->getAttribute($attribute),
            };
            if ($value === null) {
                throw new OrderException(
                    message: "Order attribute '{$attribute}' is empty",
                    attribute: $attribute,
                );
            }

            $parameter = $this->parameter($type, $value, $params);
            $ascending = $query->getMethod() === QueryMethod::OrderAsc;
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
            throw new \InvalidArgumentException("Unsupported log query attribute: {$attribute}");
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

    /** @param array<string, mixed>|null $row */
    private function document(?array $row): Document
    {
        if ($row === null || $row === []) {
            return new Document();
        }

        $timestamp = isset($row['timestamp']) ? (string) $row['timestamp'] : '';
        if ($timestamp !== '') {
            $formatted = DateTime::formatTz($this->date($timestamp));
            if (\is_string($formatted)) {
                $timestamp = $formatted;
            }
        }

        return new Document([
            '$id' => (string) ($row['id'] ?? ''),
            'resourceType' => (string) ($row['resourceType'] ?? ''),
            'resourceId' => (string) ($row['resourceId'] ?? ''),
            'resourceInternalId' => (string) ($row['resourceInternalId'] ?? ''),
            'deploymentId' => (string) ($row['deploymentId'] ?? ''),
            'region' => (string) ($row['region'] ?? ''),
            'timestamp' => $timestamp,
            'stream' => (string) ($row['stream'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
        ]);
    }

    private function expiresAt(string $timestamp): string
    {
        try {
            $date = new \DateTime($timestamp);
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

    private function builder(): ClickHouseBuilder
    {
        return (new ClickHouseBuilder())->useNamedBindings();
    }

    /** @param array<string, mixed> $params */
    private function select(Statement $statement, array $params): string
    {
        $sql = $statement->query . ' FORMAT JSON';

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
            throw new \RuntimeException('ClickHouse log query failed: ' . $th->getMessage(), previous: $th);
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ClickHouse log query failed with HTTP ' . $response->getStatusCode() . ': ' . $body);
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
            throw new \RuntimeException('Log ClickHouse connection is not configured');
        }
        if ($this->client === null) {
            throw new \RuntimeException('Log ClickHouse HTTP client is not configured');
        }

        try {
            $dsn = new DSN($this->dsn);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Invalid log ClickHouse DSN: ' . $exception->getMessage(), previous: $exception);
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

    private function quotedTable(): string
    {
        return $this->identifier($this->database()) . '.' . $this->identifier($this->table);
    }

    private function relation(): string
    {
        return $this->database() . '.' . $this->table;
    }

    private function identifier(string $value): string
    {
        if ($value === '' || \str_contains($value, '`') || \str_contains($value, '.') || \str_contains($value, ' ')) {
            throw new \RuntimeException("Invalid ClickHouse identifier: {$value}");
        }

        $first = $value[0];
        if (!(($first >= 'A' && $first <= 'Z') || ($first >= 'a' && $first <= 'z') || $first === '_')) {
            throw new \RuntimeException("Invalid ClickHouse identifier: {$value}");
        }

        $length = \strlen($value);
        for ($i = 1; $i < $length; $i++) {
            $char = $value[$i];
            if (!(($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9') || $char === '_')) {
                throw new \RuntimeException("Invalid ClickHouse identifier: {$value}");
            }
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
        return $this->client ?? throw new \RuntimeException('Log ClickHouse HTTP client is not configured');
    }
}
