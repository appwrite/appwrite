<?php

namespace Appwrite\Logs;

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

/**
 * ClickHouse persistence for function and site runtime logs.
 *
 * Rows are append-only line snapshots written by Alloy (or tests). Unlike
 * executions there is no versioning — MergeTree + TTL on expiresAt is enough.
 */
class Store
{
    private const string TABLE = 'runtime_logs';

    private const int READY_TTL_SECONDS = 15;

    private const array COLUMNS = [
        'projectId',
        'id',
        'resourceType',
        'resourceId',
        'resourceInternalId',
        'deploymentId',
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

    private bool $ready = false;

    private float $checkedAt = 0.0;

    public function __construct(
        private readonly string $dsn,
        private readonly ?ClientInterface $client,
        private readonly int $retention = 1_209_600,
    ) {
        $this->requestFactory = new RequestFactory();
    }

    public function setup(): void
    {
        $this->connect();
        $database = $this->identifier($this->database);
        $table = $this->table();

        $this->query("CREATE DATABASE IF NOT EXISTS {$database}");
        $this->query(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                projectId String,
                id String,
                resourceType LowCardinality(String),
                resourceId String,
                resourceInternalId String,
                deploymentId String,
                timestamp DateTime64(6),
                stream LowCardinality(String),
                message String CODEC(ZSTD(3)),
                expiresAt DateTime64(6)
            )
            ENGINE = MergeTree
            PARTITION BY cityHash64(projectId) % 32
            ORDER BY (projectId, deploymentId, timestamp, id)
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

        $this->ready = true;
        $this->checkedAt = \microtime(true);
    }

    /** @return array<string, mixed> */
    public function healthCheck(): array
    {
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
                $this->query('SELECT projectId, id, deploymentId, timestamp, stream, expiresAt FROM ' . $this->table() . ' LIMIT 0 FORMAT JSON');
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

    public function isReady(): bool
    {
        if ($this->ready && (\microtime(true) - $this->checkedAt) < self::READY_TTL_SECONDS) {
            return true;
        }

        $this->ready = ($this->healthCheck()['schemaReady'] ?? false) === true;
        $this->checkedAt = \microtime(true);

        return $this->ready;
    }

    public function create(string $projectId, Document $log): void
    {
        $this->upsert($projectId, $log);
    }

    public function upsert(string $projectId, Document $log): void
    {
        $this->upsertMany($projectId, [$log]);
    }

    /** @param array<Document> $logs */
    public function upsertMany(string $projectId, array $logs): void
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

    public function getById(string $projectId, string $logId): Document
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

        $before = $cursor?->getMethod() === Query::TYPE_CURSOR_BEFORE;
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
            throw new \InvalidArgumentException('Runtime log id is required');
        }

        $timestamp = (string) $log->getAttribute('timestamp', '');
        if ($timestamp === '') {
            $timestamp = DateTime::now();
            $log->setAttribute('timestamp', $timestamp);
        }

        $stream = (string) $log->getAttribute('stream', 'stdout');
        if ($stream !== 'stdout' && $stream !== 'stderr') {
            throw new \InvalidArgumentException('Runtime log stream must be stdout or stderr');
        }

        return [
            'projectId' => $projectId,
            'id' => $id,
            'resourceType' => (string) $log->getAttribute('resourceType', ''),
            'resourceId' => (string) $log->getAttribute('resourceId', ''),
            'resourceInternalId' => (string) $log->getAttribute('resourceInternalId', ''),
            'deploymentId' => (string) $log->getAttribute('deploymentId', ''),
            'timestamp' => $this->date($timestamp),
            'stream' => $stream,
            'message' => (string) $log->getAttribute('message', ''),
            'expiresAt' => $this->expiresAt($timestamp),
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
            throw new \RuntimeException('ClickHouse runtime log insert failed: ' . $th->getMessage(), previous: $th);
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ClickHouse runtime log insert failed with HTTP ' . $response->getStatusCode() . ': ' . (string) $response->getBody());
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
            $order[] = $last->getMethod() === Query::TYPE_ORDER_ASC
                ? Query::orderAsc('$id')
                : Query::orderDesc('$id');
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
                    throw new \InvalidArgumentException('Invalid nested runtime log query');
                }
                $parts[] = $this->filterSql($nested, $params);
            }
            if ($parts === []) {
                throw new \InvalidArgumentException('Empty logical runtime log query');
            }

            return '(' . \implode($method === Query::TYPE_AND ? ' AND ' : ' OR ', $parts) . ')';
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
            Query::TYPE_SEARCH => "positionCaseInsensitive({$column}, {$parameters[0]}) > 0",
            Query::TYPE_NOT_SEARCH => "positionCaseInsensitive({$column}, {$parameters[0]}) = 0",
            Query::TYPE_STARTS_WITH => "startsWith({$column}, {$parameters[0]})",
            Query::TYPE_NOT_STARTS_WITH => "NOT startsWith({$column}, {$parameters[0]})",
            Query::TYPE_ENDS_WITH => "endsWith({$column}, {$parameters[0]})",
            Query::TYPE_NOT_ENDS_WITH => "NOT endsWith({$column}, {$parameters[0]})",
            Query::TYPE_IS_NULL => "{$column} = ''",
            Query::TYPE_IS_NOT_NULL => "{$column} != ''",
            default => throw new \InvalidArgumentException("Unsupported runtime log query method: {$method}"),
        };
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
            throw new \InvalidArgumentException("Unsupported runtime log query attribute: {$attribute}");
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
            throw new \RuntimeException('ClickHouse runtime log query failed: ' . $th->getMessage(), previous: $th);
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ClickHouse runtime log query failed with HTTP ' . $response->getStatusCode() . ': ' . $body);
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
            throw new \RuntimeException('Runtime log ClickHouse connection is not configured');
        }
        if ($this->client === null) {
            throw new \RuntimeException('Runtime log ClickHouse HTTP client is not configured');
        }

        try {
            $dsn = new DSN($this->dsn);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Invalid runtime log ClickHouse DSN: ' . $exception->getMessage(), previous: $exception);
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

    private function relation(): string
    {
        return $this->database() . '.' . self::TABLE;
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
        return $this->client ?? throw new \RuntimeException('Runtime log ClickHouse HTTP client is not configured');
    }
}
