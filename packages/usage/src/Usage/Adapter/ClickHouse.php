<?php

namespace Utopia\Usage\Adapter;

use ArrayObject;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Http\Client\ClientInterface;
use Throwable;
use Utopia\Client\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Method as HttpMethod;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Table\ClickHouse as ClickHouseTable;
use Utopia\Usage\Metric;
use Utopia\Usage\Sample;
use Utopia\Usage\SampleGap;
use Utopia\Usage\SampleRange;
use Utopia\Usage\SampleResult;
use Utopia\Usage\SampleWatermark;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;
use Utopia\Validator\Hostname;

/**
 * ClickHouse Adapter for Usage
 *
 * This adapter stores usage metrics in ClickHouse using HTTP interface.
 * Uses two separate tables:
 * - Events table (MergeTree): raw request events with metadata columns
 *   (path, method, status, resourceType, resourceId)
 * - Gauges table (MergeTree): simple resource snapshots (metric, value, time, tags)
 *
 * A SummingMergeTree materialized view pre-aggregates events by day for fast
 * billing/analytics queries.
 *
 * Features:
 * - Two-table architecture (events + gauges)
 * - Event-specific columns extracted from tags
 * - SUM aggregation for events, argMax for gauges
 * - Safe SQL injection prevention using ClickHouse parameter binding
 * - Multi-tenant support with optional shared tables
 * - Namespace support for table name prefixes
 * - Bloom filter indexes for efficient filtering
 * - Monthly partitioning by time
 */
class ClickHouse extends SQL
{
    private const DEFAULT_PORT = 8123;

    private const DEFAULT_DATABASE = 'default';

    private const DEFAULT_TABLE = self::COLLECTION;

    private const INSERT_BATCH_SIZE = 1_000;

    private const int SAMPLE_BATCH_SIZE = 1_000;

    private const ROUTE_LOG_MAX = 1_000;

    /** @var array<string, string> Maps interval strings to ClickHouse time functions */
    private const INTERVAL_FUNCTIONS = [
        '1h' => 'toStartOfHour',
        '1d' => 'toStartOfDay',
    ];

    /**
     * Filter methods that must be supplied at least one value. Empty `values`
     * arrays for these methods are rejected up front so they can't silently
     * compile into a "no filter applied" WHERE clause.
     *
     * @var list<Method>
     */
    private const VALUE_REQUIRED_METHODS = [
        Method::Equal,
        Method::NotEqual,
        Method::LessThan,
        Method::LessThanEqual,
        Method::GreaterThan,
        Method::GreaterThanEqual,
        Method::Between,
        Method::NotBetween,
        Method::Contains,
        Method::ContainsAny,
        Method::NotContains,
        Method::StartsWith,
        Method::EndsWith,
    ];

    private readonly string $host;

    private readonly int $port;

    private readonly string $database;

    private string $table = self::DEFAULT_TABLE;

    private readonly string $username;

    private readonly string $password;

    /** @var bool Whether to use HTTPS for ClickHouse HTTP interface */
    private readonly bool $secure;

    private readonly ClientInterface $client;

    private readonly RequestFactory $requestFactory;

    protected readonly bool $sharedTables;

    protected readonly string $namespace;

    /** @var int Number of requests made using this adapter instance */
    private int $requestCount = 0;

    /** @var string|null Current operation context for better error messages */
    private ?string $operationContext = null;

    /** @var bool Whether to enable ClickHouse async inserts (server-side batching) */
    private readonly bool $asyncInserts;

    /** @var bool Whether to wait for async insert confirmation before returning */
    private readonly bool $asyncInsertWait;

    /**
     * Opt-in query_id forwarded to ClickHouse on the next query() call only.
     * Cleared after a single use so callers must set it explicitly per query.
     */
    private ?string $nextQueryId = null;

    /**
     * Structured log entries recorded for each routing decision. Ops
     * dashboards read these to confirm rollup hit-rate.
     *
     * @var array<array{operation: string, metric: ?string, route: string, start: ?string, end: ?string, dimensions: array<int, string>, interval: ?string}>
     */
    private array $routeLog = [];

    /**
     * Probability (0.0 - 1.0) that the next eligible read also runs against
     * the raw events table and logs the delta. Recommended 0.01 for
     * progressive rollout; default 0.0 (off).
     */
    private readonly float $dualReadSampleRate;

    /**
     * Retention window in days. When set, setup() applies a TTL to the raw
     * events and aggregated events_daily tables so rows older than the window
     * are dropped by background merges. Gauges are left untouched. Null
     * disables TTL (the default).
     */
    private readonly ?int $retention;

    /**
     * @param  string  $host  ClickHouse host
     * @param  string  $username  ClickHouse username (default: 'default')
     * @param  string  $password  ClickHouse password (default: '')
     * @param  int  $port  ClickHouse HTTP port (default: 8123)
     * @param  bool  $secure  Whether to use HTTPS (default: false)
     * @param  ClientInterface|null  $client  PSR-18 HTTP transport. Defaults to a
     *   cURL client with persistent connection reuse. Inject your own to control
     *   timeouts, TLS, or retries — e.g. wrap an adapter in
     *   `Utopia\Client\Decorator\Retry`, or pass a `Utopia\Client\Pool` to share a
     *   bounded set of connections across concurrent (coroutine) callers.
     * @param  string  $namespace  Table name prefix for multi-project support
     * @param  string  $database  ClickHouse database name (default: 'default')
     * @param  bool  $sharedTables  Whether tables are shared across tenants
     * @param  bool  $asyncInserts  Whether to use ClickHouse async inserts (server-side batching)
     * @param  bool  $asyncInsertWait  Whether to wait for async insert confirmation before returning
     * @param  float  $dualReadSampleRate  Parity-sampling rate, clamped to
     *   0.0 (off) … 1.0 (every read). When > 0 each eligible routed read is
     *   re-executed against the raw events table and logs a `warning` route
     *   entry if the totals diverge by >1%. Use 0.01 for a production canary
     *   or 1.0 in CI.
     * @param  int|null  $retention  Retention window in days for the events and
     *   events_daily tables. When set, setup() applies a TTL that drops rows
     *   older than the window; gauges are left untouched. Null disables TTL
     *   (default). Must be positive.
     */
    public function __construct(
        string $host,
        string $username = 'default',
        string $password = '',
        int $port = self::DEFAULT_PORT,
        bool $secure = false,
        ?ClientInterface $client = null,
        string $namespace = '',
        string $database = self::DEFAULT_DATABASE,
        bool $sharedTables = false,
        bool $asyncInserts = false,
        bool $asyncInsertWait = true,
        float $dualReadSampleRate = 0.0,
        ?int $retention = null
    ) {
        $this->validateHost($host);
        $this->validatePort($port);
        if ($retention !== null && $retention < 1) {
            throw new Exception('Retention must be a positive number of days');
        }
        if (!empty($namespace)) {
            $this->validateIdentifier($namespace, 'Namespace');
        }
        $this->validateIdentifier($database, 'Database');

        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->secure = $secure;
        $this->namespace = $namespace;
        $this->database = $database;
        $this->sharedTables = $sharedTables;
        $this->asyncInserts = $asyncInserts;
        $this->asyncInsertWait = $asyncInsertWait;
        // Clamp to [0.0, 1.0] so out-of-range rates can't disable or
        // over-trigger the parity sampler.
        $this->dualReadSampleRate = max(0.0, min(1.0, $dualReadSampleRate));
        $this->retention = $retention;

        // `withConnectionReuse()` keeps the underlying cURL handle alive across
        // requests so the TCP/TLS handshake is paid once. Auth and database are
        // layered on each request via the factory, so an injected client stays
        // a pure transport.
        $this->client = $client ?? new Client((new CurlAdapter())->withConnectionReuse());
        $this->requestFactory = new RequestFactory();
    }

    /**
     * Forward a single-use query_id to ClickHouse on the next query() call.
     * Cleared after one dispatch.
     *
     * @internal
     */
    public function setNextQueryId(?string $queryId): self
    {
        $this->nextQueryId = $queryId;
        return $this;
    }

    /**
     * Return the in-memory route-decision log (operation, metric,
     * route, window, dimensions, interval). Cleared by
     * clearRouteLog().
     *
     * @return array<array{operation: string, metric: ?string, route: string, start: ?string, end: ?string, dimensions: array<int, string>, interval: ?string}>
     */
    public function getRouteLog(): array
    {
        return $this->routeLog;
    }

    public function clearRouteLog(): self
    {
        $this->routeLog = [];
        return $this;
    }

    /**
     * Get connection statistics for monitoring.
     *
     * @return array{request_count: int, async_inserts: bool, async_insert_wait: bool}
     */
    public function getConnectionStats(): array
    {
        return [
            'request_count' => $this->requestCount,
            'async_inserts' => $this->asyncInserts,
            'async_insert_wait' => $this->asyncInsertWait,
        ];
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return 'ClickHouse';
    }

    /**
     * Check ClickHouse connection health and get server information.
     *
     * @return array{healthy: bool, host: string, port: int, database: string, secure: bool, version?: string, uptime?: int, error?: string, response_time?: float}
     */
    public function healthCheck(): array
    {
        $this->setOperationContext('healthCheck()');

        $startTime = microtime(true);
        $result = [
            'healthy' => false,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'secure' => $this->secure,
        ];

        try {
            // Simple connectivity test
            $response = $this->query('SELECT 1 as ping FORMAT JSON');
            $rows = $this->decodeRows($response);

            if (!isset($rows[0]['ping'])) {
                $result['error'] = 'Invalid response format';
                return $result;
            }

            // Get server version and uptime
            try {
                $versionResponse = $this->query('SELECT version() as version, uptime() as uptime FORMAT JSON');
                $versionRows = $this->decodeRows($versionResponse);

                if (isset($versionRows[0])) {
                    $result['version'] = self::toStr($versionRows[0]['version'] ?? null);
                    $result['uptime'] = self::toInt($versionRows[0]['uptime'] ?? null);
                }
            } catch (Exception $e) {
                // Version info is optional, don't fail health check
            }

            $result['healthy'] = true;
            $result['response_time'] = round(microtime(true) - $startTime, 3);

            return $result;
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $result['response_time'] = round(microtime(true) - $startTime, 3);
            return $result;
        }
    }

    /**
     * Validate host parameter.
     *
     * @param string $host
     * @throws Exception
     */
    private function validateHost(string $host): void
    {
        $validator = new Hostname();
        if (!$validator->isValid($host)) {
            throw new Exception('ClickHouse host is not a valid hostname or IP address');
        }
    }

    /**
     * Validate port parameter.
     *
     * @param int $port
     * @throws Exception
     */
    private function validatePort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new Exception('ClickHouse port must be between 1 and 65535');
        }
    }

    /**
     * Validate identifier (database, table, namespace).
     *
     * @param string $identifier
     * @param string $type Name of the identifier type for error messages
     * @throws Exception
     */
    private function validateIdentifier(string $identifier, string $type = 'Identifier'): void
    {
        if (empty($identifier)) {
            throw new Exception("{$type} cannot be empty");
        }

        if (strlen($identifier) > 255) {
            throw new Exception("{$type} cannot exceed 255 characters");
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new Exception("{$type} must start with a letter or underscore and contain only alphanumeric characters and underscores");
        }

        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TABLE', 'DATABASE'];
        if (in_array(strtoupper($identifier), $keywords, true)) {
            throw new Exception("{$type} cannot be a reserved SQL keyword");
        }
    }

    /**
     * Escape an identifier for safe use in SQL.
     *
     * @param string $identifier
     * @return string
     */
    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Get the base table name with namespace prefix.
     *
     * @return string
     */
    private function getTableName(): string
    {
        return !empty($this->namespace) ? $this->namespace . '_' . $this->table : $this->table;
    }

    /**
     * Get the events table name.
     *
     * @return string
     */
    private function getEventsTableName(): string
    {
        return $this->getTableName() . '_events';
    }

    /**
     * Get the gauges table name.
     *
     * @return string
     */
    private function getGaugesTableName(): string
    {
        return $this->getTableName() . '_gauges';
    }

    /**
     * Get the events daily table name.
     *
     * @return string
     */
    private function getEventsDailyTableName(): string
    {
        return $this->getTableName() . '_events_daily';
    }

    private function getSamplesTableName(): string
    {
        return $this->getTableName() . '_samples';
    }

    /**
     * Get the appropriate table name for a given type.
     *
     * @param string $type 'event' or 'gauge'
     * @return string
     */
    private function getTableForType(string $type): string
    {
        return $type === Usage::TYPE_GAUGE ? $this->getGaugesTableName() : $this->getEventsTableName();
    }

    /**
     * Build a fully qualified table reference with database and escaping.
     *
     * @param string $tableName The table name (with namespace already applied)
     * @return string Fully qualified table reference
     */
    private function buildTableReference(string $tableName): string
    {
        return $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
    }

    /**
     * Set the current operation context for better error messages.
     *
     * @param string|null $context
     * @return void
     */
    private function setOperationContext(?string $context): void
    {
        $this->operationContext = $context;
    }

    /**
     * Build a contextual error message.
     *
     * @param string $baseMessage
     * @param string|null $table
     * @param string|null $sql
     * @return string
     */
    private function buildErrorMessage(string $baseMessage, ?string $table = null, ?string $sql = null): string
    {
        $parts = [];

        if ($this->operationContext !== null) {
            $parts[] = "Operation: {$this->operationContext}";
        }

        if ($table !== null) {
            $parts[] = "Table: {$table}";
        }

        if ($sql !== null) {
            $truncatedSql = strlen($sql) > 200 ? substr($sql, 0, 200) . '...' : $sql;
            $truncatedSql = preg_replace('/\s+/', ' ', $truncatedSql);
            $parts[] = "Query: {$truncatedSql}";
        }

        $context = !empty($parts) ? ' [' . implode(', ', $parts) . ']' : '';
        return $baseMessage . $context;
    }

    /**
     * Execute a ClickHouse query via HTTP interface.
     *
     * @param string $sql
     * @param array<string, mixed> $params
     * @return string
     * @throws Exception
     */
    private function query(string $sql, array $params = []): string
    {
        $queryId = $this->nextQueryId;
        $this->nextQueryId = null;

        $scheme = $this->secure ? 'https' : 'http';

        // ClickHouse reads `query` and `param_*` from a multipart/form-data
        // body (the pattern the pre-migration cURL transport used). Keeping the
        // SQL and bound parameters in the body — rather than the URL query
        // string — avoids request-line length limits (HTTP 414) on large
        // `equal`/tag filters. ClickHouse does NOT parse
        // application/x-www-form-urlencoded bodies, so multipart is required.
        // Only the tiny query_id, which has no size concern, stays in the URL.
        $parts = ['query' => $sql];
        foreach ($params as $key => $value) {
            $parts['param_' . $key] = $this->formatParamValue($value);
        }
        $url = "{$scheme}://{$this->host}:{$this->port}/";
        if ($queryId !== null) {
            $url .= '?' . http_build_query(['query_id' => $queryId]);
        }

        $this->requestCount++;

        $request = $this->requestFactory->multipart(HttpMethod::POST, $url, $parts, $this->buildHeaders());

        try {
            $response = $this->client->sendRequest($request);
        } catch (Throwable $e) {
            $errorMsg = $this->buildErrorMessage("ClickHouse query failed: {$e->getMessage()}", null, $sql);
            throw new Exception($errorMsg, 0, $e);
        }

        $httpCode = $response->getStatusCode();
        $bodyStr = (string) $response->getBody();

        if ($httpCode !== 200) {
            $errorMsg = $this->buildErrorMessage("ClickHouse query failed with HTTP {$httpCode}: {$bodyStr}", null, $sql);
            throw new Exception($errorMsg);
        }

        return $bodyStr;
    }

    /**
     * Decode a ClickHouse `FORMAT JSON` response body into its data rows.
     * Returns an empty list when the body is not the expected envelope.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeRows(string $response): array
    {
        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return [];
        }

        $rows = [];
        foreach ($json['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function toStr(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Build the per-request headers ClickHouse expects: credentials and target
     * database. Applied to every request so the injected transport client stays
     * auth-agnostic.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'X-ClickHouse-User' => $this->username,
            'X-ClickHouse-Key' => $this->password,
            'X-ClickHouse-Database' => $this->database,
        ];
    }

    /**
     * Execute a ClickHouse INSERT using a FORMAT JSONEachRow envelope.
     *
     * @param string $table Table name (for error messages)
     * @param string $sql INSERT envelope compiled by the builder
     * @param array<string> $data Array of JSON strings (one per row)
     * @throws Exception
     */
    private function insert(string $table, string $sql, array $data, bool $durable = false): void
    {
        if (empty($data)) {
            return;
        }

        // Inserts are not idempotent: the MergeTree engine has no row-level
        // deduplication, so a retried insert that reaches the server twice
        // leaves duplicate rows behind. The default transport does not retry
        // POST; any injected retry strategy must keep it that way.
        $scheme = $this->secure ? 'https' : 'http';
        $rowCount = count($data);

        $queryParams = ['query' => $sql];
        if ($this->asyncInserts) {
            $queryParams['async_insert'] = '1';
            $queryParams['wait_for_async_insert'] = ($durable || $this->asyncInsertWait) ? '1' : '0';
        }
        $url = "{$scheme}://{$this->host}:{$this->port}/?" . http_build_query($queryParams);

        $this->requestCount++;

        $body = implode("\n", $data);

        $request = $this->requestFactory->body(HttpMethod::POST, $url, $body, 'application/x-ndjson', $this->buildHeaders());

        try {
            $response = $this->client->sendRequest($request);
        } catch (Throwable $e) {
            $errorMsg = $this->buildErrorMessage("ClickHouse insert failed: {$e->getMessage()}", $table, "INSERT INTO {$table} ({$rowCount} rows)");
            throw new Exception($errorMsg, 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            $bodyStr = (string) $response->getBody();
            $errorMsg = $this->buildErrorMessage("ClickHouse insert failed with HTTP {$response->getStatusCode()}: {$bodyStr}", $table, "INSERT INTO {$table} ({$rowCount} rows)");
            throw new Exception($errorMsg);
        }
    }

    /**
     * Format a parameter value for safe transmission to ClickHouse.
     *
     * @param mixed $value
     * @return string
     */
    private function formatParamValue(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $encoded = json_encode($value);
            return is_string($encoded) ? $encoded : '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Column-to-ClickHouse-type map used for the builder's typed named
     * bindings. Every schema column is pinned so placeholder types never
     * fall back to PHP value inference — binding an int against a String
     * column (or vice versa) would produce a ClickHouse type mismatch.
     *
     * @return array<string, string>
     */
    private function getParamTypeMap(string $type): array
    {
        $map = [
            'id' => 'String',
            'metric' => 'String',
        ];

        foreach ($this->getAttributes($type) as $attribute) {
            $id = $attribute['$id'];
            if (!is_string($id)) {
                continue;
            }
            $map[$id] = $this->getParamType($id);
        }

        if ($this->sharedTables) {
            $map['tenant'] = 'String';
        }

        return $map;
    }

    private function newBuilder(string $type = Usage::TYPE_EVENT): ClickHouseBuilder
    {
        $builder = new ClickHouseBuilder();
        $builder->useNamedBindings()->withParamTypes($this->getParamTypeMap($type));

        return $builder;
    }

    private function newSchema(): ClickHouseSchema
    {
        return new ClickHouseSchema();
    }

    /**
     * The schema layer and builder emit bare table identifiers (`name`).
     * The runtime adapter operates against a specific database, so emitted
     * SQL is rewritten with the qualified `db`.`name` form.
     */
    private function qualifyDdl(string $sql, string ...$tables): string
    {
        foreach ($tables as $table) {
            $bare = $this->escapeIdentifier($table);
            $qualified = $this->buildTableReference($table);
            $sql = str_replace($bare, $qualified, $sql);
        }

        return $sql;
    }

    /**
     * Rename the builder's named bindings with a prefix so two compiled
     * statements can be merged into one SQL string (e.g. UNION ALL) without
     * `param0`, `param1`, … colliding between the two sides.
     *
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function prefixNamedBindings(string $sql, array $bindings, string $prefix): array
    {
        $renamed = [];
        foreach ($bindings as $key => $value) {
            $newKey = $prefix . $key;
            $pattern = '/\{' . preg_quote($key, '/') . '(:[^}]+)\}/';
            $sql = preg_replace($pattern, '{' . $newKey . '$1}', $sql) ?? $sql;
            $renamed[$newKey] = $value;
        }

        return [$sql, $renamed];
    }

    /**
     * Bake the tenant scope into the builder's WHERE chain. In shared-tables
     * mode an empty tenant would compile to `tenant = ''` and silently read
     * an empty scope — fail fast instead, like the write side. ("0" is a
     * valid tenant id, so check for '' specifically.)
     *
     * A null tenant is the deliberate cross-tenant read behind
     * {@see findAcrossTenants()}: no tenant predicate is added at all. That is
     * why this is `?string` and not `string` — passing null must widen the
     * scope, never narrow it to the empty one.
     *
     * @throws Exception
     */
    private function applyTenantFilter(ClickHouseBuilder $builder, ?string $tenant): void
    {
        if (!$this->sharedTables || $tenant === null) {
            return;
        }

        if ($tenant === '') {
            throw new Exception('Tenant cannot be empty in shared-tables mode');
        }

        $builder->filter([Query::equal('tenant', [$tenant])]);
    }

    /**
     * Push the parsed filter queries plus tenant scope through the builder.
     *
     * @param array{filters: array<Query>} $parsed
     */
    private function applyFilters(ClickHouseBuilder $builder, ?string $tenant, array $parsed): void
    {
        $this->applyTenantFilter($builder, $tenant);

        if (!empty($parsed['filters'])) {
            $builder->filter($parsed['filters']);
        }
    }

    /**
     * Re-express `time` filters on the hourly bucket so a grouped read can route.
     * Only hour-aligned bounds are exactly expressible; anything else returns null
     * and leaves the caller to split the window or keep the raw predicate, so no
     * caller's window shifts.
     *
     * @param array<Query> $filters
     * @return array{filters: array<int, Query>, conditions: array<int, string>, bindings: array<string, string>}|null
     */
    private function bucketAlignedFilters(array $filters): ?array
    {
        $window = $this->timeWindowBounds($filters);
        if ($window === null) {
            return null;
        }

        $conditions = [];
        $bindings = [];
        foreach ([['bucketFrom', '>=', $window['lower']], ['bucketTo', '<', $window['upper']]] as [$name, $operator, $bound]) {
            if ($bound === null) {
                continue;
            }
            if ($bound % self::HOUR_MILLIS !== 0) {
                return null;
            }
            $conditions[] = self::EVENT_TIME_BUCKET . " {$operator} {{$name}:" . $this->getParamType('time') . '}';
            $bindings[$name] = $this->epochMillisToWire($bound);
        }

        return ['filters' => $window['filters'], 'conditions' => $conditions, 'bindings' => $bindings];
    }

    /**
     * Whether some projection can serve this shape: it has to hold the grouped
     * dims and every filtered column, or the optimizer falls back to the base
     * table. Splitting such a read would buy three base-table branches instead
     * of one, so the caller keeps its single query.
     *
     * @param array{filters: array<Query>, groupBy?: array<int, string>} $parsed
     */
    private function eventProjectionCovers(array $parsed): bool
    {
        $dims = array_values($parsed['groupBy'] ?? []);

        $filtered = [];
        foreach ($parsed['filters'] as $filter) {
            $filtered[] = $filter->getAttribute();
        }

        foreach (self::EVENT_PROJECTIONS as $projection) {
            if ($dims !== [] && $dims !== $projection['dims']) {
                continue;
            }
            if (array_diff($filtered, array_merge(['tenant', 'metric', 'time'], $projection['dims'])) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a window whose edges fall mid-hour into an hour-aligned interior —
     * the only part expressible on the projection's key — plus the one or two
     * partial hours either side, which stay on raw `time`. Null when there is no
     * whole hour to gain, leaving the caller on its single unsplit query.
     *
     * @param array<Query> $filters
     * @return array{filters: array<int, Query>, branches: array<int, array{timeExpr: string, conditions: array<int, string>, bindings: array<string, string>}>}|null
     */
    private function splitEventWindow(array $filters): ?array
    {
        $window = $this->timeWindowBounds($filters);
        if ($window === null) {
            return null;
        }

        $lower = $window['lower'];
        $upper = $window['upper'];

        // intdiv truncates toward zero, so a pre-epoch bound would round the wrong way.
        if (($lower ?? 0) < 0 || ($upper ?? 0) < 0) {
            return null;
        }

        $interiorFrom = $lower === null ? null : \intdiv($lower + self::HOUR_MILLIS - 1, self::HOUR_MILLIS) * self::HOUR_MILLIS;
        $interiorTo = $upper === null ? null : \intdiv($upper, self::HOUR_MILLIS) * self::HOUR_MILLIS;

        // No whole hour between the edges — a single partial hour is cheaper unsplit.
        if ($interiorFrom !== null && $interiorTo !== null && $interiorFrom >= $interiorTo) {
            return null;
        }

        $edges = [];
        if ($lower !== null && $lower !== $interiorFrom) {
            $edges[] = ['head', $lower, $interiorFrom];
        }
        if ($upper !== null && $upper !== $interiorTo) {
            $edges[] = ['tail', $interiorTo, $upper];
        }
        if ($edges === []) {
            return null;
        }

        $branches = [$this->windowBranch(self::EVENT_TIME_BUCKET, 'bucket', $interiorFrom, $interiorTo)];
        foreach ($edges as [$name, $from, $to]) {
            $branches[] = $this->windowBranch('`time`', $name, $from, $to);
        }

        return ['filters' => $window['filters'], 'branches' => $branches];
    }

    /**
     * Half-open bound predicates for one branch of a split window. Placeholders
     * are named per branch so the merged statement has no collisions.
     *
     * @return array{timeExpr: string, conditions: array<int, string>, bindings: array<string, string>}
     */
    private function windowBranch(string $timeExpr, string $name, ?int $from, ?int $to): array
    {
        $conditions = [];
        $bindings = [];
        foreach ([[$name . 'From', '>=', $from], [$name . 'To', '<', $to]] as [$param, $operator, $bound]) {
            if ($bound === null) {
                continue;
            }
            $conditions[] = "{$timeExpr} {$operator} {{$param}:" . $this->getParamType('time') . '}';
            $bindings[$param] = $this->epochMillisToWire($bound);
        }

        return ['timeExpr' => $timeExpr, 'conditions' => $conditions, 'bindings' => $bindings];
    }

    /** Wire format for an epoch-ms instant, keeping the millisecond part an edge bound carries. */
    private function epochMillisToWire(int $millis): string
    {
        return (new DateTimeImmutable('@' . \intdiv($millis, 1000)))->format('Y-m-d H:i:s')
            . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Reduce the `time` filters to one half-open [lower, upper) window in epoch
     * ms and hand back the remaining filters. Null for anything unreadable —
     * dropping a bound would silently widen the window.
     *
     * @param array<Query> $filters
     * @return array{filters: array<int, Query>, lower: ?int, upper: ?int}|null
     */
    private function timeWindowBounds(array $filters): ?array
    {
        $kept = [];
        $lower = null;
        $upper = null;

        foreach ($filters as $query) {
            if ($query->getAttribute() !== 'time') {
                $kept[] = $query;
                continue;
            }

            $values = $query->getValues();

            // Half-open [lower, upper) in epoch ms. An unreadable bound aborts rather
            // than being dropped, which would silently widen the window.
            $from = null;
            $to = null;
            switch ($query->getMethod()) {
                case Method::GreaterThanEqual:
                    $from = $this->toEpochMillis($values[0] ?? null);
                    break;
                case Method::GreaterThan:
                    $from = $this->toEpochMillis($values[0] ?? null);
                    $from = $from === null ? null : $from + 1;
                    break;
                case Method::LessThan:
                    $to = $this->toEpochMillis($values[0] ?? null);
                    break;
                case Method::LessThanEqual:
                    $to = $this->toEpochMillis($values[0] ?? null);
                    $to = $to === null ? null : $to + 1;
                    break;
                case Method::Between:
                    $from = $this->toEpochMillis($values[0] ?? null);
                    $to = $this->toEpochMillis($values[1] ?? null);
                    if ($from === null || $to === null) {
                        return null;
                    }
                    $to++;
                    break;
                default:
                    return null;
            }

            if ($from === null && $to === null) {
                return null;
            }

            $lower = $from === null ? $lower : ($lower === null ? $from : max($lower, $from));
            $upper = $to === null ? $upper : ($upper === null ? $to : min($upper, $to));
        }

        return ['filters' => $kept, 'lower' => $lower, 'upper' => $upper];
    }

    /**
     * Null for anything unreadable, which aborts the rewrite rather than guessing.
     */
    private function toEpochMillis(mixed $value): ?int
    {
        $text = $this->stringifyTime($value);
        if ($text === null) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($text, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }

        return $dt->getTimestamp() * 1000 + (int) $dt->format('v');
    }

    /**
     * Walk an array of Query objects and rewrite `time` values into ClickHouse
     * wire format (`Y-m-d H:i:s.v`). The builder forwards values verbatim, so
     * datetime normalisation must happen up front before the values reach the
     * `{paramN:DateTime64(3, 'UTC')}` placeholder slot.
     *
     * @param array<Query> $queries
     * @return array<Query>
     *
     * @throws Exception
     */
    private function normalizeTimeValues(array $queries): array
    {
        $normalized = [];
        foreach ($queries as $query) {
            if ($query->getAttribute() !== 'time' || !$query->getMethod()->isFilter()) {
                $normalized[] = $query;
                continue;
            }

            $values = $query->getValues();
            $rewritten = [];
            foreach ($values as $value) {
                if ($value === null) {
                    $rewritten[] = null;
                    continue;
                }
                if ($value instanceof DateTime || is_string($value)) {
                    $rewritten[] = $this->formatDateTime($value);
                    continue;
                }
                $rewritten[] = $value;
            }

            $clone = clone $query;
            $clone->setValues($rewritten);
            $normalized[] = $clone;
        }

        return $normalized;
    }

    /**
     * Decode a single integer aggregate (`data[0].total`) from a ClickHouse
     * `FORMAT JSON` response. Returns 0 when the payload is absent.
     */
    private function decodeTotal(string $result): int
    {
        $rows = $this->decodeRows($result);
        if (!isset($rows[0]['total'])) {
            return 0;
        }

        return self::toInt($rows[0]['total']);
    }

    /** Reads must emit this verbatim to route; the optimizer matches on the exact expression. */
    private const EVENT_TIME_BUCKET = "toStartOfHour(`time`, 'UTC')";

    /** Width of that bucket, in the epoch milliseconds window bounds are reduced to. */
    private const HOUR_MILLIS = 3_600_000;

    /** Sub-hour intervals need detail the bucket summed away. @var list<string> */
    private const BUCKET_ROUTABLE_INTERVALS = ['1h', '1d', '1w', '1M'];

    /**
     * Per-dim projection slate. Each entry declares an `ADD PROJECTION` on
     * the base events table. The ClickHouse optimizer transparently routes
     * grouped reads whose GROUP BY shape matches.
     *
     * Single-dim throughout: the console asks for these as separate breakdowns,
     * and a shared (method, status) projection keys the cross-product — 19.2M
     * rows on a 100M-row fixture against 1.15M + 2.55M for the two alone.
     * ip, hostname, city and resourceId are left off as too high-cardinality.
     *
     * `path` is the outlier already here: it holds resource ids rather than
     * route templates, so its key count tracks a customer's resource count and
     * can approach the base table (48% of the rows at 30k paths per tenant),
     * which buys the split little. Measure before assuming it still earns its
     * ingest cost.
     *
     * @var array<array{name: string, dims: array<int, string>}>
     */
    private const EVENT_PROJECTIONS = [
        ['name' => 'p_by_path', 'dims' => ['path']],
        ['name' => 'p_by_country', 'dims' => ['country']],
        ['name' => 'p_by_service', 'dims' => ['service']],
        ['name' => 'p_by_method', 'dims' => ['method']],
        ['name' => 'p_by_status', 'dims' => ['status']],
        ['name' => 'p_by_clientType', 'dims' => ['clientType']],
        ['name' => 'p_by_clientName', 'dims' => ['clientName']],
        ['name' => 'p_by_deviceName', 'dims' => ['deviceName']],
        ['name' => 'p_by_osName', 'dims' => ['osName']],
        ['name' => 'p_by_sdk', 'dims' => ['sdk']],
    ];

    /**
     * Per-dim projection slate for gauges. The projection stores
     * argMax(value, time) per (metric, time, [tenant,] dims) tuple; the
     * ClickHouse optimizer rewrites grouped argMax reads against the base
     * table to read from the projection.
     *
     * @var array<array{name: string, dims: array<int, string>}>
     */
    /**
     * Latest-value slate: keyed (tenant, metric, dims) with NO time key, one
     * argMax state per series. Serves unwindowed grouped latest-value reads
     * (the billing prefetch shape) at one row per group — production
     * measurement without it: ~300k rows read per grouped gauge read, and
     * force_optimize_projection refuses the time-keyed slate for these
     * queries (PROJECTION_NOT_USED). Existing installs must
     * ALTER TABLE ... MATERIALIZE PROJECTION each p_latest_* once (setup()
     * only attaches them for parts written afterwards).
     */
    private const GAUGE_LATEST_PROJECTIONS = [
        ['name' => 'p_latest_by_service', 'dims' => ['service']],
        ['name' => 'p_latest_by_resourceType', 'dims' => ['resourceType']],
        ['name' => 'p_latest_by_resourceId', 'dims' => ['resourceId']],
        ['name' => 'p_latest_by_resourceType_resourceId', 'dims' => ['resourceType', 'resourceId']],
    ];

    private const GAUGE_PROJECTIONS = [
        ['name' => 'p_by_service', 'dims' => ['service']],
        ['name' => 'p_by_resourceType', 'dims' => ['resourceType']],
        ['name' => 'p_by_resourceId', 'dims' => ['resourceId']],
        ['name' => 'p_by_resourceType_resourceId', 'dims' => ['resourceType', 'resourceId']],
    ];

    /**
     * Setup ClickHouse table structure.
     *
     * Creates:
     * 1. Events table (MergeTree) with event-specific columns
     * 2. Events daily table (SummingMergeTree) for pre-aggregation
     * 3. Events daily materialized view
     * 4. Gauges table (MergeTree) with simple schema
     * 5. Per-dim projections on the events / gauges base tables
     *
     * @throws Exception
     */
    public function setup(): void
    {
        $this->setOperationContext('setup()');

        // Create database if not exists
        $escapedDatabase = $this->escapeIdentifier($this->database);
        $createDbSql = "CREATE DATABASE IF NOT EXISTS {$escapedDatabase}";
        $this->query($createDbSql);

        $this->createTable(
            $this->getEventsTableName(),
            'event',
            $this->getEventIndexes()
        );

        $this->ensureEventDimColumns();

        $this->applyRetention($this->getEventsTableName());

        $this->createDailyTable();

        $this->applyRetention($this->getEventsDailyTableName());

        $this->createDailyMaterializedView();

        $this->createTable(
            $this->getGaugesTableName(),
            'gauge',
            $this->getGaugeIndexes()
        );

        $this->ensureGaugeDimColumns();

        $this->createSamplesTable();
        $this->ensureSampleColumns();

        $this->setLightweightMutationProjectionMode($this->getEventsTableName());
        foreach (self::EVENT_PROJECTIONS as $projection) {
            $this->addEventProjection(
                $this->getEventsTableName(),
                $projection['name'],
                $projection['dims']
            );
        }
        $this->setLightweightMutationProjectionMode($this->getGaugesTableName());
        foreach (self::GAUGE_PROJECTIONS as $projection) {
            $this->addProjection(
                $this->getGaugesTableName(),
                $projection['name'],
                $projection['dims'],
                'argMax(value, time) AS value'
            );
        }
        foreach (self::GAUGE_LATEST_PROJECTIONS as $projection) {
            $this->addProjection(
                $this->getGaugesTableName(),
                $projection['name'],
                $projection['dims'],
                'argMax(value, time) AS value',
                includeTimeKey: false,
            );
        }
    }

    /**
     * Create the immutable canonical-sample ledger. Retries remain as raw
     * physical rows; findSamples() groups by the canonical identity and
     * exposes conflicting payloads instead of allowing them to affect totals.
     */
    private function createSamplesTable(): void
    {
        $tableName = $this->getSamplesTableName();
        $table = $this->newSchema()->table($tableName);

        $table->rawColumn('`id` String CODEC(ZSTD(3))');
        $table->rawColumn('`payloadHash` String CODEC(ZSTD(3))');
        $table->rawColumn('`ingestId` String CODEC(ZSTD(3))');
        $table->rawColumn('`environment` LowCardinality(String)');
        $table->rawColumn('`region` LowCardinality(String)');
        $table->rawColumn('`projectInternalId` String CODEC(ZSTD(3))');
        $table->rawColumn('`databaseInternalId` String CODEC(ZSTD(3))');
        $table->rawColumn('`member` String CODEC(ZSTD(3))');
        $table->rawColumn('`generation` String CODEC(ZSTD(3))');
        $table->rawColumn('`sequence` UInt64');
        $table->rawColumn('`metric` LowCardinality(String)');
        $table->rawColumn("`intervalStart` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)");
        $table->rawColumn("`intervalEnd` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)");
        $table->rawColumn('`value` Int64');
        $table->rawColumn('`eventVersion` UInt32');

        $table->engine(Engine::MergeTree)
            ->orderBy([
                'environment',
                'region',
                'projectInternalId',
                'databaseInternalId',
                'member',
                'generation',
                'metric',
                'sequence',
                'id',
                'payloadHash',
                'ingestId',
            ])
            ->partitionBy('toYYYYMM(intervalStart)')
            ->settings(['index_granularity' => 8192]);

        $statement = $table->createIfNotExists();

        $this->query($this->qualifyDdl($statement->query, $tableName));
    }

    /**
     * Older pre-release tables have no exact snapshot identifier. Keep their
     * default empty so watermark reads omit them and fail closed with gaps.
     */
    private function ensureSampleColumns(): void
    {
        $table = $this->buildTableReference($this->getSamplesTableName());
        $this->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS `ingestId` String DEFAULT '' CODEC(ZSTD(3))");
    }

    /**
     * Apply (or strip) the retention TTL on a table as a separate idempotent
     * ALTER. CREATE TABLE IF NOT EXISTS won't add a TTL to an existing table,
     * and MODIFY TTL is a no-op when unchanged, so setup() stays re-runnable.
     * The raw events and aggregated events_daily tables share the same window;
     * gauges are left untouched. materialize_ttl_after_modify = 0 defers the
     * purge to background merges rather than an immediate mutation.
     *
     * @throws Exception
     */
    private function applyRetention(string $tableName): void
    {
        $escapedTable = $this->escapeIdentifier($this->database)
            . '.' . $this->escapeIdentifier($tableName);

        if ($this->retention !== null) {
            $this->query(
                "ALTER TABLE {$escapedTable} "
                . "MODIFY TTL toDateTime(time) + INTERVAL {$this->retention} DAY "
                . 'SETTINGS materialize_ttl_after_modify = 0'
            );
            return;
        }

        // Disabling retention must actively strip any TTL a previous run
        // applied; otherwise rows keep being purged despite retention being
        // null. REMOVE TTL on a table with no TTL raises BAD_ARGUMENTS
        // (code 36) — a generic code, so anchor on both the stable code and
        // a "TTL" mention rather than the full English phrase (which can
        // drift by version). This keeps setup() idempotent without swallowing
        // unrelated bad-argument errors.
        try {
            $this->query("ALTER TABLE {$escapedTable} REMOVE TTL");
        } catch (Exception $e) {
            $message = $e->getMessage();
            if (!str_contains($message, 'Code: 36') || !str_contains($message, 'TTL')) {
                throw $e;
            }
        }
    }

    /**
     * Allow lightweight DELETE on tables that carry projections. ClickHouse
     * defaults to throwing because a delete can leave projection parts
     * inconsistent; 'rebuild' tells the engine to re-materialize the
     * affected projection parts after the delete.
     */
    private function setLightweightMutationProjectionMode(string $baseTable): void
    {
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($baseTable);
        $sql = "ALTER TABLE {$escapedTable} MODIFY SETTING lightweight_mutation_projection_mode = 'rebuild'";
        $this->query($sql);
    }

    private const BASE_KEY_COLUMNS = ['id', 'metric', 'value', 'time', 'tenant'];

    private function ensureGaugeDimColumns(): void
    {
        $this->ensureDimColumns($this->getGaugesTableName(), Metric::GAUGE_COLUMNS, 'gauge');
    }

    private function ensureEventDimColumns(): void
    {
        $this->ensureDimColumns($this->getEventsTableName(), Metric::EVENT_COLUMNS, 'event');
    }

    /**
     * @param array<int, string> $columns
     */
    private function ensureDimColumns(string $tableName, array $columns, string $type): void
    {
        $escapedTable = $this->escapeIdentifier($this->database)
            . '.' . $this->escapeIdentifier($tableName);

        $adds = [];
        foreach ($columns as $column) {
            if (in_array($column, self::BASE_KEY_COLUMNS, true)) {
                continue;
            }
            $adds[] = 'ADD COLUMN IF NOT EXISTS '
                . $this->escapeIdentifier($column)
                . ' ' . $this->getColumnType($column, $type);
        }

        if ($adds === []) {
            return;
        }

        $this->query("ALTER TABLE {$escapedTable} " . implode(', ', $adds));
    }

    /**
     * Idempotently add a projection to a base table. Projection columns are
     * (metric, time, [tenant,] ...dims, aggregate) and the GROUP BY shape
     * matches; the ClickHouse optimizer picks this projection for any
     * grouped query whose GROUP BY is a subset of those keys and whose
     * filters are expressible on the projection columns. Raw `time` is
     * kept in the projection (not `toStartOfDay`) so `WHERE time BETWEEN`
     * filters can match the projection without query rewriting.
     *
     * @param array<int, string> $dims
     */
    private function addProjection(string $baseTable, string $name, array $dims, string $aggregateExpr, bool $includeTimeKey = true): void
    {
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($baseTable);

        // Two key shapes, one per read family. With `time` in the keys the
        // projection has one row per sample — narrow-column but 1:1 with the
        // base table — which is what windowed grouped reads need (the time
        // predicate must be expressible on the projection). WITHOUT `time`
        // the projection holds one aggregate state per series, so an
        // unwindowed latest-value read (the billing prefetch shape) collapses
        // to one row per group instead of re-aggregating every sample.
        $selectParts = $includeTimeKey ? ['metric', 'time'] : [];
        $groupParts = $includeTimeKey ? ['metric', 'time'] : [];
        if ($this->sharedTables) {
            $selectParts[] = 'tenant';
            $groupParts[] = 'tenant';
        }
        if (!$includeTimeKey) {
            $selectParts[] = 'metric';
            $groupParts[] = 'metric';
        }
        foreach ($dims as $dim) {
            $selectParts[] = $this->escapeIdentifier($dim);
            $groupParts[] = $this->escapeIdentifier($dim);
        }
        $selectParts[] = $aggregateExpr;

        $selectSql = implode(', ', $selectParts);
        $groupSql = implode(', ', $groupParts);

        $sql = "ALTER TABLE {$escapedTable} ADD PROJECTION IF NOT EXISTS {$name} ("
            . "SELECT {$selectSql} "
            . "GROUP BY {$groupSql}"
            . ")";

        $this->query($sql);
    }

    /**
     * Keyed (tenant, metric, hourly bucket, ...dims).
     *
     * Tenant leads because a projection is sorted by its GROUP BY order; the hourly
     * key keeps it O(tenant × metric × hour × dim) instead of a copy of the table.
     *
     * @param array<int, string> $dims
     */
    private function addEventProjection(string $baseTable, string $name, array $dims): void
    {
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($baseTable);

        $selectParts = [];
        $groupParts = [];
        if ($this->sharedTables) {
            $selectParts[] = 'tenant';
            $groupParts[] = 'tenant';
        }
        $selectParts[] = 'metric';
        $groupParts[] = 'metric';
        $selectParts[] = self::EVENT_TIME_BUCKET . ' AS `timeBucket`';
        $groupParts[] = '`timeBucket`';
        foreach ($dims as $dim) {
            $selectParts[] = $this->escapeIdentifier($dim);
            $groupParts[] = $this->escapeIdentifier($dim);
        }
        $selectParts[] = 'sum(value) AS value';

        $selectSql = implode(', ', $selectParts);
        $groupSql = implode(', ', $groupParts);

        $sql = "ALTER TABLE {$escapedTable} ADD PROJECTION IF NOT EXISTS {$name} ("
            . "SELECT {$selectSql} "
            . "GROUP BY {$groupSql}"
            . ")";

        $this->query($sql);
    }

    /**
     * Create a MergeTree table for the given type via the schema layer.
     *
     * @param string $tableName
     * @param string $type 'event' or 'gauge'
     * @param array<int, array<string, mixed>> $indexes
     * @throws Exception
     */
    private function createTable(string $tableName, string $type, array $indexes): void
    {
        $table = $this->newSchema()->table($tableName);

        $idColumn = $table->string('id');
        foreach ($this->getColumnCodecParts('id') as $codec) {
            $idColumn->codec($codec);
        }

        foreach ($this->getAttributes($type) as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];
            $this->declareColumn($table, $id, $type);
        }

        // Add tenant column only if tables are shared across tenants
        if ($this->sharedTables) {
            $table->string('tenant')->nullable();
        }

        // Index names carry hyphens (`index-path`), which the schema layer's
        // typed index API rejects for ClickHouse skip indexes, so the INDEX
        // clauses are emitted raw to keep the deployed names unchanged.
        foreach ($indexes as $index) {
            /** @var string $indexName */
            $indexName = $index['$id'];
            /** @var array<string> $attributes */
            $attributes = $index['attributes'];
            $indexType = is_string($index['indexType'] ?? null) ? $index['indexType'] : 'bloom_filter';
            $escapedIndexName = $this->escapeIdentifier($indexName);
            $attributeList = implode(', ', array_map($this->escapeIdentifier(...), $attributes));
            $table->rawColumn("INDEX {$escapedIndexName} ({$attributeList}) TYPE {$indexType} GRANULARITY 1");
        }

        // Primary key matches the most common filter pattern:
        // tenant (multi-tenant isolation) → metric (per-metric series) →
        // time (range scans). id is the tiebreaker for stable physical
        // ordering. This shape lets ClickHouse skip whole granules on
        // metric+time predicates instead of doing a full-table scan.
        $table->engine(Engine::MergeTree)
            ->orderBy($this->sharedTables ? ['tenant', 'metric', 'time', 'id'] : ['metric', 'time', 'id'])
            ->partitionBy('toYYYYMM(time)')
            ->settings(['index_granularity' => 8192, 'allow_nullable_key' => 1]);

        $statement = $table->createIfNotExists();

        $this->query($this->qualifyDdl($statement->query, $tableName));
    }

    /**
     * Declare a column on the schema table, mapping the Metric attribute
     * schema to typed column kinds. Column shapes the typed API cannot
     * express — `DateTime64(3, 'UTC')` (timezone argument) and
     * `LowCardinality(Nullable(String))` (nullable inside the wrapper) —
     * fall back to a raw definition so the emitted DDL stays identical.
     *
     * @throws Exception
     */
    private function declareColumn(ClickHouseTable $table, string $id, string $type): void
    {
        if ($id === 'time') {
            $table->rawColumn("`time` DateTime64(3, 'UTC') " . $this->getColumnCodec('time'));

            return;
        }

        $columnType = $this->getColumnType($id, $type);

        if (str_contains($columnType, 'LowCardinality(') || str_contains($columnType, 'DateTime64(')) {
            $table->rawColumn($this->getColumnDefinition($id, $type));
            return;
        }

        $attribute = $this->getAttribute($id, $type);
        if ($attribute === null) {
            throw new Exception("Attribute {$id} not found in {$type} schema");
        }

        $attributeType = is_string($attribute['type'] ?? null) ? $attribute['type'] : 'string';
        $required = (bool) ($attribute['required'] ?? false);

        $column = match ($attributeType) {
            'integer' => $table->bigInteger($id),
            'float' => $table->float($id),
            'boolean' => $table->boolean($id),
            default => $table->string($id),
        };

        if (!$required) {
            $column->nullable();
        }

        foreach ($this->getColumnCodecParts($id) as $codec) {
            $column->codec($codec);
        }
    }

    /**
     * Create the events daily SummingMergeTree table.
     *
     * Minimal schema: metric, value, time, tenant.
     * Resource-level breakdown uses the raw events table.
     *
     * @throws Exception
     */
    private function createDailyTable(): void
    {
        $dailyTableName = $this->getEventsDailyTableName();

        $table = $this->newSchema()->table($dailyTableName);

        $table->string('metric');
        $table->bigInteger('value');
        $table->rawColumn("time DateTime64(3, 'UTC')");
        $table->rawColumn('resourceType LowCardinality(Nullable(String))');
        $table->string('resourceId')->nullable();
        $table->string('resourceInternalId')->nullable();
        $table->string('teamId')->nullable();
        $table->string('teamInternalId')->nullable();

        if ($this->sharedTables) {
            $table->string('tenant')->nullable();
        }

        $dailyOrderBy = ['metric', 'time', 'resourceType', 'resourceId', 'resourceInternalId', 'teamId', 'teamInternalId'];
        if ($this->sharedTables) {
            array_unshift($dailyOrderBy, 'tenant');
        }

        $table->engine(Engine::SummingMergeTree)
            ->orderBy($dailyOrderBy)
            ->partitionBy('toYYYYMM(time)')
            ->settings(['index_granularity' => 8192, 'allow_nullable_key' => 1]);

        $statement = $table->createIfNotExists();

        $this->query($this->qualifyDdl($statement->query, $dailyTableName));
    }

    /**
     * Create the materialized view for daily event aggregation.
     *
     * @throws Exception
     */
    private function createDailyMaterializedView(): void
    {
        $eventsTable = $this->getEventsTableName();
        $dailyTableName = $this->getEventsDailyTableName();
        $dailyMvName = $this->getTableName() . '_events_daily_mv';

        $escapedEventsTable = $this->buildTableReference($eventsTable);

        $dimensions = 'resourceType, resourceId, resourceInternalId, teamId, teamInternalId';

        if ($this->sharedTables) {
            $innerSelect  = "metric, tenant, {$dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d";
            $innerGroupBy = "metric, tenant, {$dimensions}, d";
            $outerSelect  = "metric, value, d as time, tenant, {$dimensions}";
        } else {
            $innerSelect  = "metric, {$dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d";
            $innerGroupBy = "metric, {$dimensions}, d";
            $outerSelect  = "metric, value, d as time, {$dimensions}";
        }

        // The MV body needs an inner aggregation subquery, which the builder
        // does not round-trip cleanly yet, so the SELECT stays hand-written.
        $body = "SELECT {$outerSelect}"
            . " FROM ("
            . " SELECT {$innerSelect}"
            . " FROM {$escapedEventsTable}"
            . " GROUP BY {$innerGroupBy}"
            . " )";

        $statement = $this->newSchema()->createMaterializedView(
            $dailyMvName,
            $body,
            $dailyTableName,
            true,
        );

        $this->query($this->qualifyDdl($statement->query, $dailyMvName, $dailyTableName));
    }

    /**
     * Backfill the daily rollup from the raw events table for [$from, $to).
     *
     * The daily materialized view only aggregates rows inserted after it was
     * created; windows before that are absent from the rollup, so every read
     * routed to it under-counts. This runs the exact aggregation the view
     * body runs, bounded to the window, making backfilled days
     * indistinguishable from view-produced ones.
     *
     * Both bounds must be UTC midnights: SummingMergeTree folds duplicate
     * keys by adding them, so a partial day written here would double-count
     * against rows the view produced for that day. For the same reason the
     * window must not overlap rows already in the rollup — the call refuses
     * when it does unless $force is passed, after the caller has cleared the
     * range itself.
     *
     * Single-operator migration primitive, not a concurrent API: the overlap
     * check and the INSERT are separate statements with no lock, so two
     * concurrent calls for the same empty window can both pass the check and
     * double the rollup. Run one backfill at a time, and verify with a
     * raw-vs-rollup parity read before routing traffic at the window.
     *
     * @throws Exception
     */
    public function backfillDaily(string $from, string $to, bool $force = false): void
    {
        try {
            // The constructor's timezone argument is ignored when the string
            // carries its own offset, and the bindings are formatted without
            // one — so normalize to UTC first. A bound like
            // '2026-01-01 00:00:00+05:00' is midnight only in its own zone;
            // after normalization it is 19:00 UTC and correctly refused.
            $fromDt = (new DateTime($from, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
            $toDt = (new DateTime($to, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new Exception("backfillDaily() bounds must be valid datetimes: {$e->getMessage()}", 0, $e);
        }

        if (!$this->isDayAligned($fromDt) || !$this->isDayAligned($toDt)) {
            throw new Exception('backfillDaily() bounds must be UTC midnights: a partial day double-counts against rows the daily view already produced for that day.');
        }
        if ($fromDt >= $toDt) {
            throw new Exception('backfillDaily() needs an ascending half-open window.');
        }

        $this->setOperationContext('backfillDaily()');

        $eventsTable = $this->buildTableReference($this->getEventsTableName());
        $dailyTable = $this->buildTableReference($this->getEventsDailyTableName());

        $bindings = [
            'bf_from' => $fromDt->format('Y-m-d H:i:s.v'),
            'bf_to' => $toDt->format('Y-m-d H:i:s.v'),
        ];
        $window = 'time >= {bf_from:DateTime64(3)} AND time < {bf_to:DateTime64(3)}';

        if (!$force) {
            $existing = $this->decodeTotal($this->query(
                "SELECT count() AS total FROM {$dailyTable} WHERE {$window} FORMAT JSON",
                $bindings,
            ));
            if ($existing > 0) {
                throw new Exception("backfillDaily() window already holds {$existing} rollup rows and inserting again would double-count. Clear the range first, or pass force after doing so.");
            }
        }

        // Mirrors createDailyMaterializedView()'s body exactly, plus the bound.
        $dimensions = 'resourceType, resourceId, resourceInternalId, teamId, teamInternalId';

        if ($this->sharedTables) {
            $columns = "metric, value, time, tenant, {$dimensions}";
            $innerSelect = "metric, tenant, {$dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d";
            $innerGroupBy = "metric, tenant, {$dimensions}, d";
            $outerSelect = "metric, value, d as time, tenant, {$dimensions}";
        } else {
            $columns = "metric, value, time, {$dimensions}";
            $innerSelect = "metric, {$dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d";
            $innerGroupBy = "metric, {$dimensions}, d";
            $outerSelect = "metric, value, d as time, {$dimensions}";
        }

        $sql = "INSERT INTO {$dailyTable} ({$columns})"
            . " SELECT {$outerSelect}"
            . " FROM ("
            . " SELECT {$innerSelect}"
            . " FROM {$eventsTable}"
            . " WHERE {$window}"
            . " GROUP BY {$innerGroupBy}"
            . " )";

        $this->query($sql, $bindings);
    }

    /**
     * Validate that an attribute name exists in the schema for a given type.
     *
     * @param string $attributeName
     * @param string $type 'event' or 'gauge'
     * @return bool
     * @throws Exception
     */
    private function validateAttributeName(string $attributeName, string $type = 'event'): bool
    {
        if ($attributeName === 'id') {
            return true;
        }

        if ($attributeName === 'tenant' && $this->sharedTables) {
            return true;
        }

        foreach ($this->getAttributes($type) as $attribute) {
            if ($attribute['$id'] === $attributeName) {
                return true;
            }
        }

        // Reject attributes that don't exist on the target type's schema.
        // Falling back to the other type's columns (e.g. allowing `path` on
        // a gauge query because it exists on the event schema) compiles to
        // SQL that references columns the gauge table doesn't have, which
        // ClickHouse rejects with "Unknown identifier".
        throw new Exception("Invalid attribute name: {$attributeName}");
    }

    /**
     * Validate that a groupBy attribute is an aggregable dimension column.
     *
     * Restricted to the indexed dimension columns for the table type — `metric`,
     * `value` and `time` are excluded since they are already part of the
     * aggregation (metric is in the SELECT, time is bucketed via
     * groupByInterval, value is the measured quantity).
     *
     * @throws Exception
     */
    private function validateGroupByAttribute(string $attribute, string $type): bool
    {
        $allowed = $type === Usage::TYPE_GAUGE ? Metric::GAUGE_COLUMNS : Metric::EVENT_COLUMNS;

        // `tenant` is a real column in shared-tables mode, not a dimension on
        // Metric. Grouping by it is what makes a cross-tenant read
        // ({@see findAcrossTenants}) attributable, so allow it there.
        if ($this->sharedTables) {
            $allowed[] = 'tenant';
        }

        if (in_array($attribute, $allowed, true)) {
            return true;
        }

        throw new Exception("Invalid groupBy attribute '{$attribute}' for {$type}. Allowed: " . implode(', ', $allowed));
    }

    /**
     * Columns available in the events daily (pre-aggregated) table.
     */
    private const DAILY_COLUMNS = [
        'metric', 'value', 'time',
        'resourceType', 'resourceId', 'resourceInternalId',
        'teamId', 'teamInternalId',
    ];

    /**
     * Validate that a query attribute exists in the daily table schema.
     * The daily table only has metric, value, time (+ tenant if shared).
     *
     * @throws Exception
     */
    private function validateDailyAttributeName(string $attributeName): bool
    {
        if ($attributeName === 'id') {
            return true;
        }

        if ($attributeName === 'tenant' && $this->sharedTables) {
            return true;
        }

        if (in_array($attributeName, self::DAILY_COLUMNS, true)) {
            return true;
        }

        $allowed = implode(', ', self::DAILY_COLUMNS) . ($this->sharedTables ? ', tenant' : '');
        throw new Exception(
            "Invalid attribute '{$attributeName}' for daily table. "
            . "Allowed: {$allowed}."
        );
    }

    /**
     * Format datetime for ClickHouse compatibility.
     *
     * @param DateTime|string|null $dateTime
     * @return string
     * @throws Exception
     */
    private function formatDateTime($dateTime): string
    {
        if ($dateTime === null) {
            return (new DateTime())->format('Y-m-d H:i:s.v');
        }

        if ($dateTime instanceof DateTime) {
            return $dateTime->format('Y-m-d H:i:s.v');
        }

        if (is_string($dateTime)) {
            try {
                $dt = new DateTime($dateTime);
                return $dt->format('Y-m-d H:i:s.v');
            } catch (Exception $e) {
                throw new Exception("Invalid datetime string: {$dateTime}");
            }
        }

        /** @phpstan-ignore-next-line */
        throw new Exception("Invalid datetime value type: " . gettype($dateTime));
    }

    /**
     * Get ClickHouse type for an attribute.
     *
     * @param string $id Attribute identifier
     * @param string $type 'event' or 'gauge'
     * @return string ClickHouse type
     * @throws Exception
     */
    private function getColumnType(string $id, string $type = 'event'): string
    {
        $attribute = $this->getAttribute($id, $type);
        if (!$attribute) {
            throw new Exception("Attribute {$id} not found in {$type} schema");
        }

        $lowCardinality = [
            'country', 'region', 'service', 'resourceType',
            'osCode', 'osName', 'osVersion',
            'clientType', 'clientCode', 'clientName', 'clientVersion',
            'clientEngine', 'clientEngineVersion',
            'deviceName', 'deviceBrand', 'deviceModel',
            'hostname', 'ip',
            // request attributes: only protocol is bounded. accept/acceptLanguage/
            // queryKeys hold raw, un-normalized caller input (unbounded distinct
            // values), so they stay plain Nullable(String) + bloom_filter.
            'protocol',
            // ip reputation verdict (placeholder): bounded enum
            // (clean/low/suspicious/block)
            'ipReputation',
            // premium geo (lower-cardinality only; city/isp/AS org/connection org and
            // postalCode/latitude/longitude are high-cardinality and intentionally fall
            // through to Nullable(String))
            'continentCode', 'subdivisions', 'connectionType',
            'connectionUsageType', 'autonomousSystemNumber',
            'timeZone', 'weatherCode',
            // sdk identity
            'sdk', 'sdkVersion',
            // gauge replica ordinal
            'ordinal',
        ];

        if (in_array($id, $lowCardinality, true)) {
            return 'LowCardinality(Nullable(String))';
        }

        $attributeType = is_string($attribute['type'] ?? null) ? $attribute['type'] : 'string';
        $baseType = match ($attributeType) {
            'integer' => 'Int64',
            'float' => 'Float64',
            'boolean' => 'UInt8',
            'datetime' => "DateTime64(3, 'UTC')",
            default => 'String',
        };

        return !$attribute['required'] ? 'Nullable(' . $baseType . ')' : $baseType;
    }

    protected function getColumnDefinition(string $id, string $type = 'event'): string
    {
        $codec = $this->getColumnCodec($id);
        $suffix = $codec !== '' ? ' ' . $codec : '';
        return $this->escapeIdentifier($id) . ' ' . $this->getColumnType($id, $type) . $suffix;
    }

    /**
     * Return the per-column ClickHouse CODEC clause for the events / gauges
     * tables. Empty string when no codec is overridden for this column.
     */
    private function getColumnCodec(string $id): string
    {
        if ($id === 'time') {
            return 'CODEC(Delta(4), LZ4)';
        }

        $zstdColumns = [
            'id', 'path', 'hostname',
            'resourceId', 'resourceInternalId',
            'teamId', 'teamInternalId',
            'osVersion', 'clientVersion', 'clientEngineVersion', 'deviceModel',
            'city', 'continentCode', 'subdivisions', 'isp',
            'autonomousSystemNumber', 'autonomousSystemOrganization',
            'connectionType', 'connectionUsageType', 'connectionOrganization',
            'sdk', 'sdkVersion',
            // high-entropy, un-normalized request text
            'accept', 'acceptLanguage', 'queryKeys',
        ];

        if (in_array($id, $zstdColumns, true)) {
            return 'CODEC(ZSTD(3))';
        }

        return '';
    }

    /**
     * Return the per-column codec clauses as a list consumable by the schema
     * layer's `Column::codec()` (e.g. ['Delta(4)', 'LZ4']). Empty when no
     * codec is overridden for this column.
     *
     * @return list<string>
     */
    private function getColumnCodecParts(string $id): array
    {
        $codec = $this->getColumnCodec($id);
        if ($codec === '') {
            return [];
        }

        $inner = substr($codec, strlen('CODEC('), -1);

        return array_map(trim(...), explode(',', $inner));
    }

    /**
     * Validate metric data for batch operations.
     *
     * @param string $metric Metric name
     * @param int $value Metric value
     * @param string $type Metric type ('event' or 'gauge')
     * @param array<string,mixed> $tags Tags
     * @param int|null $metricIndex Index for batch error messages
     * @param bool $allowNegative Permit a negative value for this row (default: reject)
     * @throws Exception
     */
    private function validateMetricData(string $metric, int $value, string $type, array $tags, ?int $metricIndex = null, bool $allowNegative = false): void
    {
        $prefix = $metricIndex !== null ? "Metric #{$metricIndex}: " : '';

        if (empty($metric)) {
            throw new Exception($prefix . 'Metric cannot be empty');
        }

        if (strlen($metric) > 255) {
            throw new Exception($prefix . 'Metric exceeds maximum size of 255 characters');
        }

        // Negatives are rejected by default so a buggy negative count/bandwidth
        // is caught. A row opts in with `allowNegative` for genuine signed
        // deltas (realtime connections emit +1/-1). The library stays generic —
        // the caller decides which metrics may be negative.
        if ($value < 0 && !$allowNegative) {
            throw new Exception($prefix . 'Value cannot be negative');
        }

        if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
            throw new \InvalidArgumentException($prefix . "Invalid type '{$type}'. Allowed: " . Usage::TYPE_EVENT . ', ' . Usage::TYPE_GAUGE);
        }
    }

    /**
     * Validate all metrics in a batch before processing.
     *
     * @param array<int,array<string,mixed>> $metrics
     * @param string $type The target table type
     * @throws Exception
     */
    private function validateMetricsBatch(array $metrics, string $type): void
    {
        foreach ($metrics as $index => $metricData) {
            if (!isset($metricData['metric'])) {
                throw new Exception("Metric #{$index}: 'metric' is required");
            }
            if (!isset($metricData['value'])) {
                throw new Exception("Metric #{$index}: 'value' is required");
            }

            $metric = $metricData['metric'];
            $value = $metricData['value'];

            if (!is_string($metric)) {
                throw new Exception("Metric #{$index}: 'metric' must be a string, got " . gettype($metric));
            }
            if (!is_int($value)) {
                throw new Exception("Metric #{$index}: 'value' must be an integer, got " . gettype($value));
            }

            /** @var array<string, mixed> */
            $tags = $metricData['tags'] ?? [];
            // `allowNegative` is a validation-only flag carried on the row; it
            // gates the negative-value guard and is never stored as a column.
            $allowNegative = (bool) ($metricData['allowNegative'] ?? false);
            $this->validateMetricData($metric, $value, $type, $tags, $index, $allowNegative);

            $hasTenant = array_key_exists('tenant', $metricData);

            // Shared tables filter every read by tenant, so a row written
            // without one would be invisible to normal tenant-scoped reads.
            // Reject it at write time rather than silently storing dead data.
            if ($this->sharedTables && (!$hasTenant || !is_string($metricData['tenant']) || $metricData['tenant'] === '')) {
                throw new Exception("Metric #{$index}: 'tenant' is required (non-empty string) when shared tables are enabled");
            }

            if ($hasTenant && $metricData['tenant'] !== null && !is_string($metricData['tenant'])) {
                throw new Exception("Metric #{$index}: 'tenant' must be a string or null, got " . gettype($metricData['tenant']));
            }
        }
    }

    /**
     * Add metrics in batch (raw append to appropriate table).
     *
     * For events: extracts path/method/status/resourceType/resourceId from tags into
     * dedicated columns; remaining tags stay in the tags JSON column.
     * For gauges: simple metric/value/time/tags insert.
     *
     * @param  array<int,array<string,mixed>>  $metrics
     * @param  string  $type  Metric type: 'event' or 'gauge'
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     * @throws Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize = self::INSERT_BATCH_SIZE): bool
    {
        if (empty($metrics)) {
            return true;
        }

        $this->setOperationContext('addBatch()');

        // Validate all metrics before processing
        $this->validateMetricsBatch($metrics, $type);

        $batchSize = \min(self::INSERT_BATCH_SIZE, \max(1, $batchSize));

        $tableName = $this->getTableForType($type);

        $statement = $this->newBuilder($type)
            ->into($tableName)
            ->insertFormat('JSONEachRow', $this->getInsertColumns($type))
            ->insert();
        $insertSql = $this->qualifyDdl($statement->query, $tableName);

        foreach (\array_chunk($metrics, $batchSize) as $metricsBatch) {
            $rows = [];

            foreach ($metricsBatch as $metricData) {
                /** @var string $metric */
                $metric = $metricData['metric'];
                /** @var int $value */
                $value = $metricData['value'];
                /** @var array<string, mixed> $tags */
                $tags = $metricData['tags'] ?? [];

                $tenant = $this->sharedTables ? $this->resolveTenantFromMetric($metricData) : null;

                $columns = Metric::extractColumns($tags, $type);

                $rawTime = $metricData['time'] ?? null;
                $emittedAt = ($rawTime instanceof DateTime || is_string($rawTime))
                    ? $rawTime
                    : null;

                $row = array_merge([
                    'id'     => $this->generateId(),
                    'metric' => $metric,
                    'value'  => $value,
                    'time'   => $this->formatDateTime($emittedAt),
                ], $columns);

                if ($this->sharedTables) {
                    $row['tenant'] = $tenant;
                }

                $encoded = json_encode($row);
                if ($encoded === false) {
                    throw new Exception("Failed to JSON encode metric row: " . json_last_error_msg());
                }
                $rows[] = $encoded;
            }

            $this->insert($tableName, $insertSql, $rows);
        }

        return true;
    }

    /**
     * @param list<Sample> $samples
     */
    #[\Override]
    public function addSamples(array $samples, int $batchSize = self::SAMPLE_BATCH_SIZE): bool
    {
        if ($samples === []) {
            return true;
        }

        $this->setOperationContext('addSamples()');

        $batchSize = min(self::SAMPLE_BATCH_SIZE, max(1, $batchSize));
        $tableName = $this->getSamplesTableName();
        $columns = [
            'id',
            'payloadHash',
            'ingestId',
            'environment',
            'region',
            'projectInternalId',
            'databaseInternalId',
            'member',
            'generation',
            'sequence',
            'metric',
            'intervalStart',
            'intervalEnd',
            'value',
            'eventVersion',
        ];
        $escapedColumns = implode(', ', array_map($this->escapeIdentifier(...), $columns));
        $insertSql = 'INSERT INTO ' . $this->buildTableReference($tableName)
            . " ({$escapedColumns}) FORMAT JSONEachRow";

        foreach (array_chunk($samples, $batchSize) as $batch) {
            $rows = [];

            foreach ($batch as $sample) {
                // The ID belongs to this logical row, not its HTTP attempt.
                // A transport retry repeats the encoded body and therefore
                // keeps the same ID; a later addSamples() call receives a new
                // one and cannot cross an already captured watermark.
                $rows[] = json_encode([
                    'id' => $sample->getId(),
                    'payloadHash' => $sample->getPayloadHash(),
                    'ingestId' => bin2hex(random_bytes(16)),
                    'environment' => $sample->environment,
                    'region' => $sample->region,
                    'projectInternalId' => $sample->projectInternalId,
                    'databaseInternalId' => $sample->databaseInternalId,
                    'member' => $sample->member,
                    'generation' => $sample->generation,
                    'sequence' => $sample->sequence,
                    'metric' => $sample->metric,
                    'intervalStart' => $sample->getFormattedIntervalStart(),
                    'intervalEnd' => $sample->getFormattedIntervalEnd(),
                    'value' => $sample->value,
                    'eventVersion' => $sample->eventVersion,
                ], JSON_THROW_ON_ERROR);
            }

            $this->insert($tableName, $insertSql, $rows, durable: true);
        }

        return true;
    }

    #[\Override]
    public function getSampleWatermark(SampleRange $range, int $limit): SampleWatermark
    {
        if ($limit < 1 || $limit === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Sample watermark limit must be positive and leave room for truncation detection');
        }

        $this->setOperationContext('getSampleWatermark()');

        $table = $this->buildTableReference($this->getSamplesTableName());
        $sql = <<<SQL
            SELECT concat(ingestId, ':', id, ':', payloadHash) AS entryId
            FROM {$table}
            WHERE environment = {environment:String}
              AND region = {region:String}
              AND projectInternalId = {projectInternalId:String}
              AND databaseInternalId = {databaseInternalId:String}
              AND member = {member:String}
              AND generation = {generation:String}
              AND metric = {metric:String}
              AND sequence >= {firstSequence:UInt64}
              AND sequence <= {lastSequence:UInt64}
              AND ingestId != ''
            LIMIT 1 BY entryId
            LIMIT {queryLimit:UInt64}
            FORMAT JSON
            SQL;

        $rows = $this->decodeRows($this->query($sql, [
            'environment' => $range->environment,
            'region' => $range->region,
            'projectInternalId' => $range->projectInternalId,
            'databaseInternalId' => $range->databaseInternalId,
            'member' => $range->member,
            'generation' => $range->generation,
            'metric' => $range->metric,
            'firstSequence' => $range->firstSequence,
            'lastSequence' => $range->lastSequence,
            'queryLimit' => $limit + 1,
        ]));

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = self::toStr($row['entryId'] ?? null);
        }

        return new SampleWatermark($range, $entries, $truncated);
    }

    #[\Override]
    public function findSamples(SampleRange $range, SampleWatermark $watermark, int $limit): SampleResult
    {
        if ($limit < 1 || $limit === PHP_INT_MAX) {
            throw new \InvalidArgumentException('Sample limit must be positive and leave room for truncation detection');
        }

        if (!$watermark->matches($range)) {
            throw new \InvalidArgumentException('Sample watermark does not match the requested range');
        }

        $this->setOperationContext('findSamples()');

        $table = $this->buildTableReference($this->getSamplesTableName());
        $sql = <<<SQL
            SELECT
                environment,
                region,
                projectInternalId,
                databaseInternalId,
                member,
                generation,
                sequence,
                metric,
                argMin(
                    tuple(intervalStart, intervalEnd, value, eventVersion, payloadHash),
                    tuple(
                        payloadHash,
                        intervalStart,
                        intervalEnd,
                        value,
                        eventVersion,
                        concat(ingestId, ':', id, ':', payloadHash)
                    )
                ) AS observation,
                uniqExact(concat(ingestId, ':', id, ':', payloadHash)) AS copies,
                uniqExact(tuple(payloadHash, intervalStart, intervalEnd, value, eventVersion)) AS variants
            FROM {$table}
            WHERE environment = {environment:String}
              AND region = {region:String}
              AND projectInternalId = {projectInternalId:String}
              AND databaseInternalId = {databaseInternalId:String}
              AND member = {member:String}
              AND generation = {generation:String}
              AND metric = {metric:String}
              AND sequence >= {firstSequence:UInt64}
              AND sequence <= {lastSequence:UInt64}
              AND has({entries:Array(String)}, concat(ingestId, ':', id, ':', payloadHash))
            GROUP BY
                environment,
                region,
                projectInternalId,
                databaseInternalId,
                member,
                generation,
                sequence,
                metric
            ORDER BY sequence ASC
            LIMIT {queryLimit:UInt64}
            FORMAT JSON
            SQL;

        $rows = $this->decodeRows($this->query($sql, [
            'environment' => $range->environment,
            'region' => $range->region,
            'projectInternalId' => $range->projectInternalId,
            'databaseInternalId' => $range->databaseInternalId,
            'member' => $range->member,
            'generation' => $range->generation,
            'metric' => $range->metric,
            'firstSequence' => $range->firstSequence,
            'lastSequence' => $range->lastSequence,
            'entries' => $watermark->getEntries() === []
                ? '[]'
                : "['" . implode("','", $watermark->getEntries()) . "']",
            'queryLimit' => $limit + 1,
        ]));

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $samples = [];
        $conflicts = [];
        $duplicates = 0;

        foreach ($rows as $row) {
            $sequence = self::toInt($row['sequence'] ?? null);
            $copies = self::toInt($row['copies'] ?? null);
            $variants = self::toInt($row['variants'] ?? null);
            $duplicates += max(0, $copies - max(1, $variants));

            if ($variants !== 1) {
                $conflicts[] = $sequence;
                continue;
            }

            $observation = $row['observation'] ?? null;
            if (!is_array($observation) || count($observation) !== 5) {
                $conflicts[] = $sequence;
                continue;
            }

            try {
                $sample = new Sample(
                    environment: self::toStr($row['environment'] ?? null),
                    region: self::toStr($row['region'] ?? null),
                    projectInternalId: self::toStr($row['projectInternalId'] ?? null),
                    databaseInternalId: self::toStr($row['databaseInternalId'] ?? null),
                    member: self::toStr($row['member'] ?? null),
                    generation: self::toStr($row['generation'] ?? null),
                    sequence: $sequence,
                    metric: self::toStr($row['metric'] ?? null),
                    intervalStart: new DateTimeImmutable(self::toStr($observation[0] ?? null), new DateTimeZone('UTC')),
                    intervalEnd: new DateTimeImmutable(self::toStr($observation[1] ?? null), new DateTimeZone('UTC')),
                    value: self::toInt($observation[2] ?? null),
                    eventVersion: self::toInt($observation[3] ?? null),
                );
            } catch (\InvalidArgumentException) {
                $conflicts[] = $sequence;
                continue;
            }

            if (
                $sample->getPayloadHash() !== self::toStr($observation[4] ?? null)
                || $sample->intervalStart < $range->intervalStart
                || $sample->intervalEnd > $range->intervalEnd
            ) {
                $conflicts[] = $sequence;
                continue;
            }

            $samples[] = $sample;
        }

        $gaps = $this->findSampleGaps($samples, $range->firstSequence, $range->lastSequence);
        $discontinuities = $this->findSampleDiscontinuities($samples, $range);

        return new SampleResult(
            samples: $samples,
            conflicts: array_values(array_unique($conflicts)),
            gaps: $gaps,
            discontinuities: $discontinuities,
            duplicateCount: $duplicates,
            truncated: $truncated,
            watermark: $watermark,
        );
    }

    /**
     * @param list<Sample> $samples
     * @return list<SampleGap>
     */
    private function findSampleGaps(array $samples, int $first, int $last): array
    {
        $gaps = [];
        $expected = $first;

        foreach ($samples as $sample) {
            if ($sample->sequence > $expected) {
                $gaps[] = new SampleGap($expected, $sample->sequence - 1);
            }

            $expected = $sample->sequence + 1;
        }

        if ($expected <= $last) {
            $gaps[] = new SampleGap($expected, $last);
        }

        return $gaps;
    }

    /**
     * @param list<Sample> $samples
     * @return list<int>
     */
    private function findSampleDiscontinuities(array $samples, SampleRange $range): array
    {
        $discontinuities = [];
        $expectedStart = $range->intervalStart;

        foreach ($samples as $sample) {
            if ($sample->intervalStart != $expectedStart) {
                $discontinuities[] = $sample->sequence;
            }

            $expectedStart = $sample->intervalEnd;
        }

        if ($samples !== [] && $expectedStart != $range->intervalEnd) {
            $last = $samples[array_key_last($samples)];
            $discontinuities[] = $last->sequence;
        }

        return array_values(array_unique($discontinuities));
    }

    /**
     * Columns declared in the INSERT envelope for the given type. Matches
     * the row shape produced by addBatch(): base columns, the type's
     * dimension columns, and tenant in shared-tables mode.
     *
     * @return list<string>
     */
    private function getInsertColumns(string $type): array
    {
        $columns = ['id', 'metric', 'value', 'time'];

        $dimensions = $type === Usage::TYPE_GAUGE ? Metric::GAUGE_COLUMNS : Metric::EVENT_COLUMNS;
        foreach ($dimensions as $column) {
            $columns[] = $column;
        }

        if ($this->sharedTables) {
            $columns[] = 'tenant';
        }

        return $columns;
    }

    /**
     * Resolve tenant for a single metric entry.
     *
     * @param array<string, mixed> $metricData
     */
    private function resolveTenantFromMetric(array $metricData): ?string
    {
        $tenant = $metricData['tenant'] ?? null;

        if (is_string($tenant)) {
            return $tenant;
        }

        if (is_int($tenant) || is_float($tenant)) {
            return (string) $tenant;
        }

        return null;
    }

    /**
     * Find metrics using Query objects.
     * When $type is null, queries both tables with UNION ALL.
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (both)
     * @return array<Metric>
     * @throws Exception
     */
    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        $this->setOperationContext('find()');

        return $this->findScoped($tenant, $queries, $type);
    }

    /**
     * Find metrics across every tenant. Applies no tenant filter, so it is
     * restricted to shared-tables mode and reserved for operator-side
     * aggregation jobs — see {@see Adapter::findAcrossTenants()}.
     *
     * Callers should add `groupBy('tenant')` to keep rows attributable; the
     * aggregated paths already carry `tenant` through select/group-by in
     * shared-tables mode.
     *
     * @param array<Query> $queries
     * @param string|null $type
     * @return array<Metric>
     * @throws Exception
     */
    public function findAcrossTenants(array $queries = [], ?string $type = null): array
    {
        $this->setOperationContext('findAcrossTenants()');

        if (!$this->sharedTables) {
            throw new Exception('findAcrossTenants() requires shared-tables mode; use find() instead');
        }

        return $this->findScoped(null, $queries, $type);
    }

    /**
     * Shared body for find()/findAcrossTenants(). A null $tenant means no
     * tenant filter (cross-tenant); a string scopes to that tenant.
     *
     * @param array<Query> $queries
     * @return array<Metric>
     * @throws Exception
     */
    private function findScoped(?string $tenant, array $queries, ?string $type): array
    {
        if ($type !== null) {
            return $this->findFromTable($tenant, $queries, $type);
        }

        // Cursor pagination is per-table — paginating across both events and
        // gauges has no coherent ordering, so reject this combination upfront.
        $userLimit = null;
        foreach ($queries as $query) {
            $method = $query->getMethod();
            if ($method === Method::CursorAfter || $method === Method::CursorBefore) {
                throw new Exception('Cursor pagination requires an explicit $type (event or gauge)');
            }
            if ($method === Method::Limit) {
                $values = $query->getValues();
                if (!empty($values) && is_numeric($values[0])) {
                    $userLimit = (int) $values[0];
                }
            }
        }

        // Query both tables and merge. Each side already applied LIMIT, so
        // without a final cap callers asking for `limit(N)` could receive
        // up to 2N rows. Slice the merged result back down to the user's
        // requested limit. Tables whose schema doesn't support every filter
        // attribute (e.g. `path` on a gauge query) are skipped.
        $events = $this->queriesMatchType($queries, Usage::TYPE_EVENT)
            ? $this->findFromTable($tenant, $queries, Usage::TYPE_EVENT)
            : [];
        $gauges = $this->queriesMatchType($queries, Usage::TYPE_GAUGE)
            ? $this->findFromTable($tenant, $queries, Usage::TYPE_GAUGE)
            : [];

        $merged = array_merge($events, $gauges);

        if ($userLimit !== null && count($merged) > $userLimit) {
            $merged = array_slice($merged, 0, $userLimit);
        }

        return $merged;
    }

    /**
     * Check whether every filter attribute in $queries exists on the schema
     * for the given type. Used by the null-$type code paths in find/count/sum
     * so a query with event-only attributes (path/method/status/etc.) silently
     * skips the gauges table instead of throwing "Invalid attribute name".
     *
     * @param array<Query> $queries
     */
    private function queriesMatchType(array $queries, string $type): bool
    {
        foreach ($queries as $query) {
            $attribute = $query->getAttribute();
            if ($attribute === '' || $attribute === 'id') {
                continue;
            }
            if ($attribute === 'tenant' && $this->sharedTables) {
                continue;
            }
            $matched = false;
            foreach ($this->getAttributes($type) as $schemaAttribute) {
                if ($schemaAttribute['$id'] === $attribute) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /**
     * Find metrics from a specific table.
     *
     * When a `groupByInterval` query is present, switches to aggregated mode:
     * - Events: SELECT metric, SUM(value) as value, toStartOfInterval(time, INTERVAL ...) as bucket
     * - Gauges: SELECT metric, argMax(value, time) as value, toStartOfInterval(time, INTERVAL ...) as bucket
     * Results are grouped by metric and time bucket, ordered by time ASC.
     *
     * An `aggregate('max')` query overrides the per-type default value
     * expression — see {@see findAggregatedFromTable()}.
     *
     * @param array<Query> $queries
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     * @throws Exception
     */
    private function findFromTable(?string $tenant, array $queries, string $type): array
    {
        $tableName = $this->getTableForType($type);

        $parsed = $this->parseQueries($tenant, $queries, $type);

        // Cursor pagination is incompatible with time-bucketed aggregation —
        // aggregated rows have no stable identity to anchor a keyset cursor on.
        if (isset($parsed['cursor']) && isset($parsed['groupByInterval'])) {
            throw new Exception('Cursor pagination cannot be combined with groupByInterval');
        }

        // Route through the aggregated path whenever any aggregation hint is
        // present — time bucketing, dimension breakdown, an explicit
        // aggregate(), or any combination. An aggregate() on its own still
        // counts: `aggregate('max')` with no interval and no dimensions is the
        // flat "highest value over this window" shape, and without this it
        // would fall through and return raw rows instead.
        if (
            isset($parsed['groupByInterval'])
            || !empty($parsed['groupBy'])
            || isset($parsed['aggregate'])
        ) {
            return $this->findAggregatedFromTable($tenant, $parsed, $tableName, $type);
        }

        $orderAttributes = $parsed['orderAttributes'];
        $cursorDirection = $parsed['cursorDirection'] ?? null;

        $builder = $this->newBuilder($type)
            ->from($tableName)
            ->select($this->getSelectColumns($type));

        $this->applyFilters($builder, $tenant, $parsed);

        $extraBindings = [];
        if (isset($parsed['cursor'])) {
            $orderAttributes = $this->resolveCursorOrder($orderAttributes);
            $extraBindings = $this->applyCursorWhere(
                $builder,
                $orderAttributes,
                $parsed['cursor'],
                $cursorDirection ?? 'after',
            );
        }

        $this->applyOrderBy($builder, $orderAttributes, flip: $cursorDirection === 'before');

        if (isset($parsed['limit'])) {
            $builder->limit($parsed['limit']);
        }
        if (isset($parsed['offset'])) {
            $builder->offset($parsed['offset']);
        }

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        $result = $this->query($sql, array_merge($statement->namedBindings ?? [], $extraBindings));

        $rows = $this->parseResults($result, $type);

        if ($cursorDirection === 'before') {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Find aggregated metrics from a table using time-bucketed grouping.
     *
     * Produces SQL like:
     *   SELECT metric, SUM(value) as value,
     *          toStartOfInterval(time, INTERVAL 1 HOUR) as bucket
     *   FROM table WHERE ... GROUP BY metric, bucket ORDER BY bucket ASC
     *
     * @param array{filters: array<Query>, orderAttributes: array<int, array{attribute: string, direction: string}>, limit?: int, offset?: int, groupByInterval?: string, groupBy?: array<int, string>, aggregate?: string} $parsed Parsed query data from parseQueries()
     * @param string $tableName Unqualified table name
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     * @throws Exception
     */
    private function findAggregatedFromTable(?string $tenant, array $parsed, string $tableName, string $type): array
    {
        $hasInterval = isset($parsed['groupByInterval']);

        // Choose aggregation function based on metric type. `aggregate('max')`
        // overrides both defaults: for gauges it takes the highest reading in
        // the bucket rather than the latest one (argMax), which is what rolling
        // a sampled level series up to a coarser interval needs.
        $valueExpr = $type === Usage::TYPE_GAUGE
            ? 'argMax(`value`, `time`) AS `value`'
            : 'SUM(`value`) AS `value`';

        // The override has to be picked before the builder reads it. Upstream
        // assigned $valueExpr again further down, which worked while the SQL
        // was assembled as a string at the end but is dead here — the builder
        // has already taken the value by then, so aggregate('max') would be
        // accepted and silently ignored.
        if (($parsed['aggregate'] ?? null) === 'max') {
            $valueExpr = 'max(`value`) AS `value`';
        }

        // Only events carry an hourly key, and only hour-or-coarser derives from it.
        $bucketed = null;
        $split = null;
        if (
            $type === Usage::TYPE_EVENT
            && (!$hasInterval || in_array($parsed['groupByInterval'], self::BUCKET_ROUTABLE_INTERVALS, true))
        ) {
            $bucketed = $this->bucketAlignedFilters($parsed['filters']);
            // Splitting is only worth three branches when one of them can route:
            // the projections store sum(value), so `max` has nothing to reach.
            if ($bucketed === null && !isset($parsed['aggregate']) && $this->eventProjectionCovers($parsed)) {
                $split = $this->splitEventWindow($parsed['filters']);
            }
        }

        if ($split !== null) {
            $result = $this->querySplitAggregate($tenant, $parsed, $split, $tableName, $type, $valueExpr);

            return $this->parseAggregatedResults($result, $type);
        }

        $builder = $this->aggregateBranchBuilder(
            $tenant,
            $parsed,
            $bucketed === null ? $parsed['filters'] : $bucketed['filters'],
            $tableName,
            $type,
            $valueExpr,
            $bucketed === null ? '`time`' : self::EVENT_TIME_BUCKET,
            $bucketed === null ? [] : $bucketed['conditions'],
        );

        foreach ($this->aggregateOrderClauses($parsed, $hasInterval) as $clause) {
            $builder->orderByRaw($clause);
        }

        if (isset($parsed['limit'])) {
            $builder->limit($parsed['limit']);
        }
        if (isset($parsed['offset'])) {
            $builder->offset($parsed['offset']);
        }

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        $result = $this->query($sql, array_merge($statement->namedBindings ?? [], $bucketed['bindings'] ?? []));

        return $this->parseAggregatedResults($result, $type);
    }

    /**
     * One grouped branch of an aggregated read. Branches differ only in the time
     * expression they bucket on and the bounds they carry, so the SELECT and
     * GROUP BY shape — which decides whether a projection can serve them — is
     * built in one place.
     *
     * @param array{groupByInterval?: string, groupBy?: array<int, string>} $parsed
     * @param array<Query> $filters
     * @param array<int, string> $conditions
     * @throws Exception
     */
    private function aggregateBranchBuilder(
        ?string $tenant,
        array $parsed,
        array $filters,
        string $tableName,
        string $type,
        string $valueExpr,
        string $timeExpr,
        array $conditions,
    ): ClickHouseBuilder {
        $builder = $this->newBuilder($type)
            ->from($tableName)
            ->select(['metric'])
            ->selectRaw($valueExpr);

        // Bucket column is only emitted when time bucketing is requested.
        // Without it the result is a flat aggregate per (metric, …dims).
        if (isset($parsed['groupByInterval'])) {
            $intervalSql = UsageQuery::VALID_INTERVALS[$parsed['groupByInterval']];
            $builder->selectRaw("toStartOfInterval({$timeExpr}, {$intervalSql}) AS `bucket`");
        }

        foreach ($parsed['groupBy'] ?? [] as $dim) {
            $builder->selectRaw($this->escapeIdentifier($dim));
        }

        foreach ($conditions as $condition) {
            $builder->whereRaw($condition);
        }

        $this->applyFilters($builder, $tenant, ['filters' => $filters]);

        $builder->groupByRaw(implode(', ', $this->aggregateGroupParts($parsed)));

        return $builder;
    }

    /**
     * @param array{groupByInterval?: string, groupBy?: array<int, string>} $parsed
     * @return array<int, string>
     */
    private function aggregateGroupParts(array $parsed): array
    {
        $parts = ['`metric`'];

        if (isset($parsed['groupByInterval'])) {
            $parts[] = '`bucket`';
        }

        foreach ($parsed['groupBy'] ?? [] as $dim) {
            $parts[] = $this->escapeIdentifier($dim);
        }

        return $parts;
    }

    /**
     * Default ORDER BY:
     * - With time bucketing: bucket ASC (chronological time series).
     * - Dim-only: value DESC (top-N table semantics).
     * For caller-supplied ORDER BY, `time` is rewritten to `bucket`
     * only when bucket is present; otherwise sorting by time is
     * invalid (the column is no longer in the SELECT after GROUP BY).
     *
     * @param array{orderAttributes: array<int, array{attribute: string, direction: string}>} $parsed
     * @return array<int, string>
     * @throws Exception
     */
    private function aggregateOrderClauses(array $parsed, bool $hasInterval): array
    {
        if (empty($parsed['orderAttributes'])) {
            return [$hasInterval ? '`bucket` ASC' : '`value` DESC'];
        }

        $clauses = [];
        foreach ($parsed['orderAttributes'] as $entry) {
            $attribute = $entry['attribute'];
            if ($attribute === 'time') {
                if (!$hasInterval) {
                    throw new Exception(
                        'orderBy("time") requires groupByInterval — without time bucketing the result has no time column'
                    );
                }
                $attribute = 'bucket';
            }
            $clauses[] = $this->escapeIdentifier($attribute) . ' ' . $entry['direction'];
        }

        return $clauses;
    }

    /**
     * Read a mid-hour window as an hour-aligned interior — which routes to a
     * projection — UNION ALL its partial edge hours off the base table, summed
     * back together outside. The branches partition the window, so re-summing
     * outside is exact; ORDER BY / LIMIT move out with it because no single
     * branch holds a whole group.
     *
     * @param array{groupByInterval?: string, groupBy?: array<int, string>, orderAttributes: array<int, array{attribute: string, direction: string}>, limit?: int, offset?: int} $parsed
     * @param array{filters: array<int, Query>, branches: array<int, array{timeExpr: string, conditions: array<int, string>, bindings: array<string, string>}>} $split
     * @throws Exception
     */
    private function querySplitAggregate(
        ?string $tenant,
        array $parsed,
        array $split,
        string $tableName,
        string $type,
        string $valueExpr,
    ): string {
        $branches = [];
        $bindings = [];

        foreach ($split['branches'] as $index => $branch) {
            $statement = $this->aggregateBranchBuilder(
                $tenant,
                $parsed,
                $split['filters'],
                $tableName,
                $type,
                $valueExpr,
                $branch['timeExpr'],
                $branch['conditions'],
            )->build();

            // Each branch numbers its own bindings from param0, so they are
            // renamed apart before the branches become one statement.
            [$branchSql, $renamed] = $this->prefixNamedBindings(
                $this->qualifyDdl($statement->query, $tableName),
                $statement->namedBindings ?? [],
                'b' . $index . '_',
            );

            $branches[] = $branchSql;
            $bindings = array_merge($bindings, $renamed, $branch['bindings']);
        }

        $groupParts = $this->aggregateGroupParts($parsed);
        $selectParts = array_merge([$groupParts[0], $valueExpr], array_slice($groupParts, 1));

        $sql = 'SELECT ' . implode(', ', $selectParts)
            . ' FROM (' . implode(' UNION ALL ', $branches) . ')'
            . ' GROUP BY ' . implode(', ', $groupParts)
            . ' ORDER BY ' . implode(', ', $this->aggregateOrderClauses($parsed, isset($parsed['groupByInterval'])));

        if (isset($parsed['limit'])) {
            $sql .= ' LIMIT ' . (int) $parsed['limit'];
        }
        if (isset($parsed['offset'])) {
            $sql .= ' OFFSET ' . (int) $parsed['offset'];
        }

        return $this->query($sql . ' FORMAT JSON', $bindings);
    }

    /**
     * Parse ClickHouse JSON results from an aggregated (groupByInterval) query into Metric array.
     *
     * Maps the 'bucket' column back to 'time' for consistent Metric objects.
     *
     * @param string $result Raw JSON response from ClickHouse
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     */
    private function parseAggregatedResults(string $result, string $type = 'event'): array
    {
        if (empty(trim($result))) {
            return [];
        }

        $rows = $this->decodeRows($result);
        $metrics = [];

        foreach ($rows as $row) {
            $document = [];

            foreach ($row as $key => $value) {
                if ($key === 'bucket') {
                    // Map 'bucket' back to 'time' for consistent Metric objects
                    $parsedTime = self::toStr($value);
                    if (strpos($parsedTime, 'T') === false) {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document['time'] = $parsedTime;
                } elseif ($key === 'value') {
                    // Preserve numeric precision: SUM(value) over many rows
                    // can exceed PHP_INT_MAX, and gauge averages are floats.
                    // Casting to int truncates both cases — keep numeric
                    // strings as int|float depending on shape.
                    if ($value === null) {
                        $document[$key] = null;
                    } elseif (is_int($value) || is_float($value)) {
                        $document[$key] = $value;
                    } elseif (is_numeric($value)) {
                        $document[$key] = (str_contains((string) $value, '.') || str_contains((string) $value, 'e') || str_contains((string) $value, 'E'))
                            ? (float) $value
                            : (int) $value;
                    } else {
                        $document[$key] = $value;
                    }
                } else {
                    $document[$key] = $value;
                }
            }

            // Set the type based on which table we queried
            $document['type'] = $type;

            $metrics[] = new Metric($document);
        }

        return $metrics;
    }

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level via
     * a `LIMIT {max}` inside a subquery — ClickHouse stops scanning once
     * that many rows have been matched, keeping large counts cheap.
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (both)
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @return int
     * @throws Exception
     */
    public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int
    {
        $this->setOperationContext('count()');

        if ($type !== null) {
            return $this->countFromTable($tenant, $queries, $type, $max);
        }

        // Count from both tables. Each per-table count is independently
        // capped at $max, so naively summing them could yield up to 2*$max.
        // Cap the combined total at $max in PHP to honour the contract.
        // Skip a table when its schema can't satisfy every filter attribute.
        $events = $this->queriesMatchType($queries, Usage::TYPE_EVENT)
            ? $this->countFromTable($tenant, $queries, Usage::TYPE_EVENT, $max)
            : 0;
        $gauges = $this->queriesMatchType($queries, Usage::TYPE_GAUGE)
            ? $this->countFromTable($tenant, $queries, Usage::TYPE_GAUGE, $max)
            : 0;

        $total = $events + $gauges;

        if ($max !== null && $total > $max) {
            $total = $max;
        }

        return $total;
    }

    /**
     * Count metrics from a specific table.
     *
     * @param array<Query> $queries
     * @param string $type
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @return int
     * @throws Exception
     */
    private function countFromTable(string $tenant, array $queries, string $type, ?int $max = null): int
    {
        $tableName = $this->getTableForType($type);

        $parsed = $this->parseQueries($tenant, $queries, $type);

        if ($max !== null) {
            $innerBuilder = $this->newBuilder($type)
                ->from($tableName)
                ->selectRaw('1')
                ->limit($max);

            $this->applyFilters($innerBuilder, $tenant, $parsed);

            $innerStatement = $innerBuilder->build();
            $innerSql = $this->qualifyDdl($innerStatement->query, $tableName);
            $sql = "SELECT COUNT(*) as total FROM ({$innerSql}) sub FORMAT JSON";

            $result = $this->query($sql, $innerStatement->namedBindings ?? []);
        } else {
            $builder = $this->newBuilder($type)
                ->from($tableName)
                ->count('*', 'total');

            $this->applyFilters($builder, $tenant, $parsed);

            $statement = $builder->build();
            $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

            $result = $this->query($sql, $statement->namedBindings ?? []);
        }

        return $this->decodeTotal($result);
    }

    /**
     * Sum metric values using Query objects.
     *
     * Events-only by default — summing gauges is semantically meaningless.
     *
     * @param array<Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string $type 'event' or 'gauge'
     * @return int
     * @throws Exception
     */
    public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        $this->setOperationContext('sum()');

        if ($type === Usage::TYPE_EVENT && $attribute === 'value') {
            return $this->routedSum($tenant, $queries, 'sum');
        }

        return $this->sumFromTable($tenant, $queries, $attribute, $type);
    }

    /**
     * Routed event flat-sum: pick the cheapest source (closed-day MV, hybrid
     * MV+raw, or raw) for a `SELECT sum(value)` over the events table and
     * record the decision in the route log under `$operation`.
     *
     * @param array<Query> $queries
     */
    private function routedSum(string $tenant, array $queries, string $operation): int
    {
        $plan = $this->extractRoutingPlan($queries);
        $route = $this->selectAggregateSource($plan);
        $this->recordRoute($operation, $plan, $route);

        if ($route === 'daily') {
            $total = $this->sumDailyTotal($tenant, $this->translateInclusiveMidnightForDaily($queries), 'value');
            $this->maybeDualRead($tenant, $queries, $route, $plan, $total);
            return $total;
        }
        if ($route === 'hybrid') {
            $total = $this->sumHybridDailyAndRaw($tenant, $queries, $plan);
            $this->maybeDualRead($tenant, $queries, $route, $plan, $total);
            return $total;
        }
        if ($route === 'split') {
            $total = $this->sumSplitDailyAndRaw($tenant, $queries, $plan);
            $this->maybeDualRead($tenant, $queries, $route, $plan, $total);
            return $total;
        }

        return $this->sumFromTable($tenant, $queries, 'value', Usage::TYPE_EVENT);
    }

    /**
     * Snapshot of the parsed query shape relevant for routing.
     *
     * @param array<Query> $queries
     * @return array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns: array<int, string>, hasCursor: bool, hasIrregularTimeFilter: bool, endInclusive: bool}
     */
    private function extractRoutingPlan(array $queries): array
    {
        $metric = null;
        $start = null;
        $end = null;
        $filterColumns = [];
        $dimensions = [];
        $interval = null;
        $orderColumns = [];
        $hasCursor = false;
        $hasIrregularTimeFilter = false;
        $endInclusive = false;

        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            if ($method === Method::GroupBy) {
                $dims = $attribute !== '' ? [$attribute] : [];
                foreach ($values as $value) {
                    if (is_string($value) && $value !== '') {
                        $dims[] = $value;
                    }
                }
                foreach ($dims as $dim) {
                    if (!in_array($dim, $dimensions, true)) {
                        $dimensions[] = $dim;
                    }
                }
                continue;
            }
            if ($method === Method::GroupByTimeBucket) {
                $intervalValue = $values[0] ?? null;
                $interval = is_string($intervalValue) ? $intervalValue : null;
                continue;
            }
            if ($method === Method::CursorAfter || $method === Method::CursorBefore) {
                $rawCursor = $values[0] ?? null;
                if ($rawCursor !== null) {
                    $hasCursor = true;
                }
                continue;
            }
            if ($method === Method::OrderAsc || $method === Method::OrderDesc) {
                if ($attribute !== '' && !in_array($attribute, $orderColumns, true)) {
                    $orderColumns[] = $attribute;
                }
                continue;
            }
            if (in_array($method, [Method::Limit, Method::Offset], true)) {
                continue;
            }

            if ($attribute === '') {
                continue;
            }

            if (!in_array($attribute, $filterColumns, true)) {
                $filterColumns[] = $attribute;
            }

            if ($attribute === 'metric' && $method === Method::Equal) {
                $first = $values[0] ?? null;
                if (is_string($first) && count($values) === 1) {
                    $metric = $first;
                }
            }

            if ($attribute === 'time') {
                if ($method === Method::GreaterThanEqual || $method === Method::GreaterThan) {
                    $start = $this->tightenLowerBound($start, $this->stringifyTime($values[0] ?? null));
                } elseif ($method === Method::LessThanEqual || $method === Method::LessThan) {
                    $candidate = $this->stringifyTime($values[0] ?? null);
                    $tightened = $this->tightenUpperBound($end, $candidate);
                    if ($tightened !== $end) {
                        $endInclusive = $method === Method::LessThanEqual;
                    }
                    $end = $tightened;
                } elseif ($method === Method::Between) {
                    $start = $this->tightenLowerBound($start, $this->stringifyTime($values[0] ?? null));
                    $candidate = $this->stringifyTime($values[1] ?? null);
                    $tightened = $this->tightenUpperBound($end, $candidate);
                    if ($tightened !== $end) {
                        // Between's upper bound is inclusive.
                        $endInclusive = true;
                    }
                    $end = $tightened;
                } else {
                    // A time filter that is not a window bound (notBetween,
                    // equal, ...) carves shapes a day-granularity rollup
                    // cannot honor: a mid-day hole is invisible at day rows,
                    // and the split route's interior would drop it entirely.
                    $hasIrregularTimeFilter = true;
                }
            }
        }

        return [
            'metric' => $metric,
            'start' => $start,
            'end' => $end,
            'filterColumns' => $filterColumns,
            'dimensions' => $dimensions,
            'interval' => $interval,
            'orderColumns' => $orderColumns,
            'hasCursor' => $hasCursor,
            'hasIrregularTimeFilter' => $hasIrregularTimeFilter,
            'endInclusive' => $endInclusive,
        ];
    }

    /**
     * Pure routing decision for the events flat-sum path (sum / getTotal).
     * Returns one of:
     *   - 'raw'    — scan the raw events table.
     *   - 'daily'  — read the events daily MV (closed-day window, only
     *                daily-MV-compatible filters, no grouping).
     *   - 'hybrid' — closed days from events daily MV, today's partial from raw.
     *
     * Grouped reads (`dimensions` non-empty) route to 'raw' here; the
     * base `find()` issues a GROUP BY query against the events / gauges
     * table and the ClickHouse optimizer transparently picks the
     * matching projection.
     *
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     */
    private function selectAggregateSource(array $plan): string
    {
        if ($plan['interval'] !== null) {
            return 'raw';
        }

        if ($plan['end'] === null) {
            return 'raw';
        }

        if (!empty($plan['hasCursor'])) {
            return 'raw';
        }

        if (!empty($plan['hasIrregularTimeFilter'])) {
            return 'raw';
        }

        if (in_array('id', $plan['filterColumns'], true) || in_array('value', $plan['filterColumns'], true)) {
            return 'raw';
        }

        if (!empty($plan['dimensions'])) {
            return 'raw';
        }

        try {
            $endDt = new DateTime($plan['end'], new DateTimeZone('UTC'));
            $boundaryDt = new DateTime('today', new DateTimeZone('UTC'));
            $startDt = $plan['start'] !== null ? new DateTime($plan['start'], new DateTimeZone('UTC')) : null;
        } catch (Exception $e) {
            return 'raw';
        }

        if ($startDt !== null && $startDt >= $boundaryDt) {
            return 'raw';
        }

        foreach ($plan['filterColumns'] as $column) {
            if (!in_array($column, self::DAILY_COLUMNS, true) && $column !== 'tenant') {
                return 'raw';
            }
        }

        if (!$this->isDayAligned($startDt)) {
            return $this->splitInterior($startDt, $endDt, $boundaryDt) !== null ? 'split' : 'raw';
        }

        if ($endDt >= $boundaryDt) {
            return 'hybrid';
        }

        if (!$this->isDayAligned($endDt) || !empty($plan['endInclusive'])) {
            // A day-aligned but INCLUSIVE upper bound covers the midnight
            // instant itself, and a daily row cannot represent it: the bucket
            // for that day holds the whole day, so 'daily' would either drop
            // the instant (translating <= to <) or over-count the day. Split
            // reads the interior from the rollup and that instant from raw.
            return $this->splitInterior($startDt, $endDt, $boundaryDt) !== null ? 'split' : 'raw';
        }

        return 'daily';
    }

    /**
     * Interior [from, to) of a window: the whole UTC days the daily rollup can
     * answer. From = the start ceiled to the next midnight (null start = the
     * rollup's full history), to = the end floored to midnight and capped at
     * today (the running day always reads raw). Null when no whole day fits —
     * a sub-day window has no rollup-answerable interior.
     *
     * This is what makes routing reach the windows production actually uses:
     * billing cycles are anchored at the moment a team upgraded (0 of 451k
     * production teams have midnight-aligned invoice dates), so day-aligned
     * routes alone never fire for billing.
     *
     * @return array{0: ?string, 1: string}|null [interiorFrom|null, interiorTo)
     */
    private function splitInterior(?DateTime $startDt, DateTime $endDt, DateTime $boundaryDt): ?array
    {
        $from = null;
        if ($startDt !== null) {
            $from = (clone $startDt);
            if ($from->format('H:i:s.u') !== '00:00:00.000000') {
                $from->setTime(0, 0, 0, 0)->modify('+1 day');
            }
        }

        $to = (clone $endDt)->setTime(0, 0, 0, 0);
        if ($to > $boundaryDt) {
            $to = clone $boundaryDt;
        }

        if ($from !== null && $from >= $to) {
            return null;
        }

        return [
            $from?->format('Y-m-d H:i:s.v'),
            $to->format('Y-m-d H:i:s.v'),
        ];
    }

    /**
     * Returns true when the timestamp falls exactly on a UTC midnight.
     */
    private function isDayAligned(?DateTime $dt): bool
    {
        if ($dt === null) {
            return true;
        }
        return $dt->format('H:i:s.u') === '00:00:00.000000';
    }

    private function isMidnightString(?string $ts): bool
    {
        if ($ts === null) {
            return false;
        }
        try {
            return $this->isDayAligned(new DateTime($ts, new DateTimeZone('UTC')));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Daily MV rows are keyed at toStartOfDay(time), so an inclusive
     * `<= midnight` upper bound matches the row representing the entire
     * end day and over-counts. Rewrite inclusive-midnight upper bounds
     * (LESSER_EQUAL and BETWEEN upper) to exclusive `<` for the daily
     * branch. Other bounds pass through untouched.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    private function translateInclusiveMidnightForDaily(array $queries): array
    {
        $result = [];
        foreach ($queries as $q) {
            if ($q->getAttribute() !== 'time') {
                $result[] = $q;
                continue;
            }
            $method = $q->getMethod();
            $values = $q->getValues();

            if ($method === Method::LessThanEqual) {
                $upper = $this->stringifyTime($values[0] ?? null);
                if ($upper !== null && $this->isMidnightString($upper)) {
                    $result[] = Query::lessThan('time', $upper);
                    continue;
                }
            }

            if ($method === Method::Between && count($values) >= 2) {
                $upper = $this->stringifyTime($values[1] ?? null);
                if ($upper !== null && $this->isMidnightString($upper)) {
                    $lower = $this->stringifyTime($values[0] ?? null);
                    if ($lower !== null) {
                        $result[] = Query::greaterThanEqual('time', $lower);
                    }
                    $result[] = Query::lessThan('time', $upper);
                    continue;
                }
            }

            $result[] = $q;
        }
        return $result;
    }

    private function stringifyTime(mixed $value): ?string
    {
        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        return is_string($value) ? $value : null;
    }

    /**
     * Combine two candidate lower bounds into the tighter (later) one.
     * Returns whichever input is non-null when only one is present.
     */
    private function tightenLowerBound(?string $current, ?string $candidate): ?string
    {
        if ($current === null) {
            return $candidate;
        }
        if ($candidate === null) {
            return $current;
        }
        try {
            $cur = new DateTime($current);
            $cand = new DateTime($candidate);
        } catch (Exception $e) {
            return $current;
        }
        return $cand > $cur ? $candidate : $current;
    }

    /**
     * Combine two candidate upper bounds into the tighter (earlier) one.
     */
    private function tightenUpperBound(?string $current, ?string $candidate): ?string
    {
        if ($current === null) {
            return $candidate;
        }
        if ($candidate === null) {
            return $current;
        }
        try {
            $cur = new DateTime($current);
            $cand = new DateTime($candidate);
        } catch (Exception $e) {
            return $current;
        }
        return $cand < $cur ? $candidate : $current;
    }

    /**
     * Partition a query list into non-time filters and time filters. Used by
     * the hybrid helpers so the daily branch can substitute a day-floored
     * lower bound while the raw branch keeps the caller's original literal.
     *
     * @param array<Query> $queries
     * @return array{nonTime: array<int, Query>, time: array<int, Query>}
     */
    private function splitTimeQueries(array $queries): array
    {
        $timeMethods = [
            Method::GreaterThan,
            Method::GreaterThanEqual,
            Method::LessThan,
            Method::LessThanEqual,
            Method::Between,
            Method::NotBetween,
        ];

        $nonTime = [];
        $time = [];
        foreach ($queries as $query) {
            if ($query->getAttribute() === 'time' && in_array($query->getMethod(), $timeMethods, true)) {
                $time[] = $query;
            } else {
                $nonTime[] = $query;
            }
        }

        return ['nonTime' => $nonTime, 'time' => $time];
    }

    /**
     * Floor a stringified timestamp to its UTC start-of-day. Returns null if
     * the input can't be parsed.
     */
    private function floorToStartOfDay(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
        $dt->setTime(0, 0, 0);
        return $dt->format('Y-m-d H:i:s.v');
    }

    /**
     * Rewrite the time bounds in a query list so a purge against a
     * day-bucketed rollup only touches days *entirely* covered by the
     * caller's range. Mid-day boundaries shrink inward — lower bounds
     * ceil to the next day's midnight (skipping a partial start day),
     * upper bounds floor to the same day's midnight (skipping a partial
     * end day). Other queries pass through unchanged.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    private function translateTimeQueriesToDayBoundaries(array $queries): array
    {
        $output = [];
        foreach ($queries as $query) {
            if ($query->getAttribute() !== 'time') {
                $output[] = $query;
                continue;
            }

            $method = $query->getMethod();
            $values = $query->getValues();

            if ($method === Method::GreaterThanEqual || $method === Method::GreaterThan) {
                $ceiled = $this->ceilLowerToFullyCoveredDayStart($this->stringifyTime($values[0] ?? null));
                if ($ceiled === null) {
                    $output[] = $query;
                    continue;
                }
                $output[] = Query::greaterThanEqual('time', $ceiled);
                continue;
            }

            if ($method === Method::LessThanEqual || $method === Method::LessThan) {
                $floored = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                if ($floored === null) {
                    $output[] = $query;
                    continue;
                }
                $output[] = Query::lessThan('time', $floored);
                continue;
            }

            if ($method === Method::Between) {
                $lower = $this->ceilLowerToFullyCoveredDayStart($this->stringifyTime($values[0] ?? null));
                $upper = $this->floorToStartOfDay($this->stringifyTime($values[1] ?? null));
                if ($lower !== null) {
                    $output[] = Query::greaterThanEqual('time', $lower);
                }
                if ($upper !== null) {
                    $output[] = Query::lessThan('time', $upper);
                }
                continue;
            }

            $output[] = $query;
        }

        return $output;
    }

    /**
     * Round a lower-bound timestamp up to the first day-start that is fully
     * inside the caller's range. Midnight stays as-is; any non-midnight value
     * advances to the next day's midnight so the rollup purge skips the
     * partially-covered start day.
     */
    private function ceilLowerToFullyCoveredDayStart(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
        if ($dt->format('H:i:s.u') !== '00:00:00.000000') {
            $dt->setTime(0, 0, 0, 0);
            $dt->modify('+1 day');
        }
        return $dt->format('Y-m-d H:i:s.v');
    }

    /**
     * Translate the caller's time-bound queries into filters suited to a
     * day-bucketed rollup. Lower bounds are floored to start-of-day so a
     * mid-day start still picks up that day's rollup row; upper bounds pass
     * through unchanged, except inclusive midnight upper bounds which
     * tighten to exclusive so the row representing the entire end day is
     * skipped.
     *
     * @param array<int, Query> $timeQueries
     * @return array<int, Query>
     */
    private function buildDailyTimeQueries(array $timeQueries): array
    {
        $result = [];
        foreach ($timeQueries as $query) {
            $method = $query->getMethod();
            $values = $query->getValues();

            if ($method === Method::GreaterThanEqual || $method === Method::GreaterThan) {
                $floored = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                if ($floored === null) {
                    continue;
                }
                $result[] = Query::greaterThanEqual('time', $floored);
                continue;
            }

            if ($method === Method::LessThanEqual || $method === Method::LessThan) {
                $upper = $this->stringifyTime($values[0] ?? null);
                if ($upper === null) {
                    continue;
                }
                $inclusiveOnMidnight = $method === Method::LessThanEqual && $this->isMidnightString($upper);
                $result[] = ($method === Method::LessThan || $inclusiveOnMidnight)
                    ? Query::lessThan('time', $upper)
                    : Query::lessThanEqual('time', $upper);
                continue;
            }

            if ($method === Method::Between) {
                $lower = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                $upper = $this->stringifyTime($values[1] ?? null);
                if ($lower !== null) {
                    $result[] = Query::greaterThanEqual('time', $lower);
                }
                if ($upper !== null) {
                    $result[] = $this->isMidnightString($upper)
                        ? Query::lessThan('time', $upper)
                        : Query::lessThanEqual('time', $upper);
                }
                continue;
            }
        }

        return $result;
    }

    /**
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     */
    private function recordRoute(string $operation, array $plan, string $route): void
    {
        $this->appendRouteLogEntry([
            'operation' => $operation,
            'metric' => $plan['metric'],
            'route' => $route,
            'start' => $plan['start'],
            'end' => $plan['end'],
            'dimensions' => $plan['dimensions'],
            'interval' => $plan['interval'],
        ]);
    }

    /**
     * @param array{operation: string, metric: ?string, route: string, start: ?string, end: ?string, dimensions: array<int, string>, interval: ?string} $entry
     */
    private function appendRouteLogEntry(array $entry): void
    {
        $this->routeLog[] = $entry;
        if (count($this->routeLog) > self::ROUTE_LOG_MAX) {
            $overflow = count($this->routeLog) - self::ROUTE_LOG_MAX;
            $this->routeLog = array_slice($this->routeLog, $overflow);
        }
    }

    /**
     * Dual-read sampler: with probability `$dualReadSampleRate`, re-run
     * the same flat-sum query against the raw events table and log a
     * warning when the totals diverge.
     *
     * Only the billing daily MV (`daily` / `hybrid` route) can diverge
     * from raw — projections are derived in the same write transaction
     * as the parent insert and cannot drift, so the sampler skips grouped
     * (projection-routed) reads.
     *
     * @param array<Query> $queries
     * @param string $route
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     */
    private function maybeDualRead(string $tenant, array $queries, string $route, array $plan, int $rolledTotal): void
    {
        if ($this->dualReadSampleRate <= 0.0) {
            return;
        }
        if (mt_rand() / mt_getrandmax() > $this->dualReadSampleRate) {
            return;
        }

        try {
            $rawTotal = $this->sumFromTable($tenant, $queries, 'value', Usage::TYPE_EVENT);
        } catch (Throwable $e) {
            return;
        }

        if ($rawTotal === 0 && $rolledTotal === 0) {
            return;
        }

        $denominator = $rawTotal === 0 ? max(abs($rolledTotal), 1) : abs($rawTotal);
        $delta = abs($rolledTotal - $rawTotal) / $denominator;
        if ($delta > 0.01) {
            $this->appendRouteLogEntry([
                'operation' => 'dual_read_warning',
                'metric' => $plan['metric'],
                'route' => $route . ':delta=' . round($delta, 4),
                'start' => $plan['start'],
                'end' => $plan['end'],
                'dimensions' => $plan['dimensions'],
                'interval' => $plan['interval'],
            ]);
        }
    }

    /**
     * Hybrid daily + raw read: closed days from the daily MV, today's
     * partial from the raw events table, combined via outer SUM over
     * UNION ALL. The two sides compile independently; the daily side's
     * named bindings are prefixed so the merged statement has no
     * placeholder collisions.
     *
     * @param array<Query> $queries
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string} $plan
     */
    private function sumHybridDailyAndRaw(string $tenant, array $queries, array $plan): int
    {
        $startOfToday = (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $dailyTableName = $this->getEventsDailyTableName();
        $eventsTableName = $this->getEventsTableName();

        $split = $this->splitTimeQueries($queries);

        $rawQueries = array_merge($queries, [Query::greaterThanEqual('time', $startOfToday)]);
        $dailyQueries = array_merge(
            $split['nonTime'],
            $this->buildDailyTimeQueries($split['time']),
            [Query::lessThan('time', $startOfToday)],
        );

        $rawParsed = $this->parseQueries($tenant, $rawQueries, Usage::TYPE_EVENT);
        $dailyParsed = $this->parseQueries($tenant, $dailyQueries, Usage::TYPE_EVENT);

        $rawBuilder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($eventsTableName)
            ->sum('value', 'total');
        $this->applyFilters($rawBuilder, $tenant, $rawParsed);
        $rawStatement = $rawBuilder->build();
        $rawSql = $this->qualifyDdl($rawStatement->query, $eventsTableName);

        $dailyBuilder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($dailyTableName)
            ->sum('value', 'total');
        $this->applyFilters($dailyBuilder, $tenant, $dailyParsed);
        $dailyStatement = $dailyBuilder->build();
        [$dailySql, $dailyBindings] = $this->prefixNamedBindings(
            $this->qualifyDdl($dailyStatement->query, $dailyTableName),
            $dailyStatement->namedBindings ?? [],
            'd_',
        );

        $sql = "
            SELECT sum(total) AS total FROM (
                {$dailySql}
                UNION ALL
                {$rawSql}
            )
            FORMAT JSON
        ";

        $result = $this->query($sql, array_merge($rawStatement->namedBindings ?? [], $dailyBindings));

        return $this->decodeTotal($result);
    }

    /**
     * Split flat-sum: the interior whole days from the daily rollup, the
     * sub-day head and tail edges from the raw events table, folded by an
     * outer SUM over UNION ALL. This is the route production windows
     * actually take — billing cycles are anchored at upgrade time, never
     * midnight, so the day-aligned 'daily'/'hybrid' routes cannot fire for
     * them. Measured on the largest production tenant (30-day window):
     * 282M rows raw vs 6.7M rows split, identical totals.
     *
     * @param array<Query> $queries
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     */
    private function sumSplitDailyAndRaw(string $tenant, array $queries, array $plan): int
    {
        [$branches, $bindings] = $this->splitBranchStatements($tenant, $queries, $plan, null);

        $sql = "
            SELECT sum(total) AS total FROM (
                " . implode("\n                UNION ALL\n                ", $branches) . "
            )
            FORMAT JSON
        ";

        return $this->decodeTotal($this->query($sql, $bindings));
    }

    /**
     * Split batched event totals: the batch shape of sumSplitDailyAndRaw(),
     * grouped by metric on every branch and re-folded per metric outside.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     * @return array<string, int>
     */
    private function totalBatchSplit(string $tenant, array $metrics, array $queries, array $plan): array
    {
        [$branches, $bindings] = $this->splitBranchStatements($tenant, $queries, $plan, $metrics);

        $sql = "
            SELECT `metric`, sum(`total`) AS `agg_val` FROM (
                " . implode("\n                UNION ALL\n                ", $branches) . "
            )
            GROUP BY `metric`
            FORMAT JSON
        ";

        $totalsByMetric = [];
        foreach ($this->decodeRows($this->query($sql, $bindings)) as $row) {
            $totalsByMetric[self::toStr($row['metric'] ?? null)] = self::toInt($row['agg_val'] ?? null);
        }

        return $totalsByMetric;
    }

    /**
     * Compile the split branches: the daily rollup over the interior whole
     * days, and the raw events table over the sub-day edges. The edges keep
     * the caller's original time filters (preserving their inclusivity) with
     * the interior boundary ANDed on, so head ∪ interior ∪ tail partitions
     * the window exactly. Each branch's bindings are prefixed so the merged
     * statement has no placeholder collisions. A null $metrics compiles the
     * flat-sum shape; a list compiles the grouped-by-metric batch shape.
     *
     * @param array<Query> $queries
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     * @param array<string>|null $metrics
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     * @throws Exception
     */
    private function splitBranchStatements(string $tenant, array $queries, array $plan, ?array $metrics): array
    {
        $boundaryDt = new DateTime('today', new DateTimeZone('UTC'));
        $endDt = new DateTime((string) $plan['end'], new DateTimeZone('UTC'));
        $startDt = $plan['start'] !== null ? new DateTime($plan['start'], new DateTimeZone('UTC')) : null;

        $interior = $this->splitInterior($startDt, $endDt, $boundaryDt);
        if ($interior === null) {
            // Unreachable: selectAggregateSource() only returns 'split' when
            // an interior exists. Fail loudly rather than mis-aggregate.
            throw new Exception('Split route selected for a window with no whole interior day.');
        }
        [$interiorFrom, $interiorTo] = $interior;

        $split = $this->splitTimeQueries($queries);

        $branchQueryLists = [];

        $dailyQueries = $split['nonTime'];
        if ($interiorFrom !== null) {
            $dailyQueries[] = Query::greaterThanEqual('time', $interiorFrom);
        }
        $dailyQueries[] = Query::lessThan('time', $interiorTo);
        $branchQueryLists[] = [true, $dailyQueries];

        if ($interiorFrom !== null && $startDt !== null && $startDt->format('Y-m-d H:i:s.v') !== $interiorFrom) {
            $branchQueryLists[] = [false, array_merge($queries, [Query::lessThan('time', $interiorFrom)])];
        }

        // The tail carries the caller's own upper bound, so an inclusive
        // bound resolves to exactly the boundary instant ([midnight, midnight])
        // — the rows the interior's half-open upper edge excludes.
        if ($endDt->format('Y-m-d H:i:s.v') !== $interiorTo || !empty($plan['endInclusive'])) {
            $branchQueryLists[] = [false, array_merge($queries, [Query::greaterThanEqual('time', $interiorTo)])];
        }

        $dailyTableName = $this->getEventsDailyTableName();
        $eventsTableName = $this->getEventsTableName();

        $branches = [];
        $bindings = [];
        foreach ($branchQueryLists as $index => [$isDaily, $branchQueries]) {
            $tableName = $isDaily ? $dailyTableName : $eventsTableName;
            $parsed = $this->parseQueries($tenant, $branchQueries, Usage::TYPE_EVENT);

            $builder = $this->newBuilder(Usage::TYPE_EVENT)->from($tableName);
            if ($metrics === null) {
                $builder->sum('value', 'total');
            } else {
                $builder
                    ->select(['metric'])
                    ->selectRaw('SUM(`value`) AS `total`')
                    ->filter([Query::equal('metric', $metrics)])
                    ->groupByRaw('`metric`');
            }
            $this->applyFilters($builder, $tenant, $parsed);

            $statement = $builder->build();
            [$branchSql, $branchBindings] = $this->prefixNamedBindings(
                $this->qualifyDdl($statement->query, $tableName),
                $statement->namedBindings ?? [],
                'b' . $index . '_',
            );

            $branches[] = $branchSql;
            $bindings = array_merge($bindings, $branchBindings);
        }

        return [$branches, $bindings];
    }

    /**
     * Sum metric values from a specific table.
     *
     * @param array<Query> $queries
     * @param string $attribute
     * @param string $type
     * @return int
     * @throws Exception
     */
    private function sumFromTable(string $tenant, array $queries, string $attribute, string $type): int
    {
        $tableName = $this->getTableForType($type);

        $this->validateAttributeName($attribute, $type);

        $parsed = $this->parseQueries($tenant, $queries, $type);

        $builder = $this->newBuilder($type)
            ->from($tableName)
            ->sum($attribute, 'total');

        $this->applyFilters($builder, $tenant, $parsed);

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        return $this->decodeTotal($this->query($sql, $statement->namedBindings ?? []));
    }

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * @param array<Query> $queries
     * @return array<Metric>
     * @throws Exception
     */
    public function findDaily(string $tenant, array $queries = []): array
    {
        $this->setOperationContext('findDaily()');

        $tableName = $this->getEventsDailyTableName();

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                $this->validateDailyAttributeName($attr);
            }
        }
        $parsed = $this->parseQueries($tenant, $queries, Usage::TYPE_EVENT);

        $groupByColumns = $this->sharedTables ? ['tenant'] : [];
        $groupByColumns[] = 'metric';
        $groupByColumns[] = 'time';
        foreach (['resourceType', 'resourceId', 'resourceInternalId', 'teamId', 'teamInternalId'] as $dim) {
            $groupByColumns[] = $dim;
        }

        $builder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($tableName)
            ->select($groupByColumns)
            ->selectRaw('sum(`value`) AS `value`')
            ->groupByRaw(implode(', ', array_map($this->escapeIdentifier(...), $groupByColumns)));

        $this->applyFilters($builder, $tenant, $parsed);
        $this->applyOrderBy($builder, $parsed['orderAttributes']);

        if (isset($parsed['limit'])) {
            $builder->limit($parsed['limit']);
        }
        if (isset($parsed['offset'])) {
            $builder->offset($parsed['offset']);
        }

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        return $this->parseResults($this->query($sql, $statement->namedBindings ?? []), Usage::TYPE_EVENT);
    }

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * @param array<Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     * @throws Exception
     */
    public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int
    {
        $this->setOperationContext('sumDaily()');

        return $this->sumDailyTotal($tenant, $queries, $attribute);
    }

    /**
     * Sum the daily table. Split out from sumDaily() so internal callers
     * (e.g. routedSum) can reuse it without re-setting the operation context.
     *
     * @param array<Query> $queries
     * @throws Exception
     */
    private function sumDailyTotal(string $tenant, array $queries, string $attribute = 'value'): int
    {
        $tableName = $this->getEventsDailyTableName();
        $this->validateDailyAttributeName($attribute);

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                $this->validateDailyAttributeName($attr);
            }
        }
        $parsed = $this->parseQueries($tenant, $queries, Usage::TYPE_EVENT);

        $builder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($tableName)
            ->sum($attribute, 'total');

        $this->applyFilters($builder, $tenant, $parsed);

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        return $this->decodeTotal($this->query($sql, $statement->namedBindings ?? []));
    }

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @return array<string, int>
     * @throws Exception
     */
    public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array
    {
        if (empty($metrics)) {
            return [];
        }

        $this->setOperationContext('sumDailyBatch()');

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                $this->validateDailyAttributeName($attr);
            }
        }

        $totals = \array_fill_keys($metrics, 0);

        $tableName = $this->getEventsDailyTableName();

        $parsed = $this->parseQueries($tenant, $queries, Usage::TYPE_EVENT);

        $builder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($tableName)
            ->select(['metric'])
            ->selectRaw('SUM(`value`) AS `total`')
            ->filter([Query::equal('metric', $metrics)])
            ->groupByRaw('`metric`');

        $this->applyFilters($builder, $tenant, $parsed);

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        $result = $this->query($sql, $statement->namedBindings ?? []);
        $rows = $this->decodeRows($result);

        foreach ($rows as $row) {
            $metricName = self::toStr($row['metric'] ?? null);
            if (isset($totals[$metricName])) {
                $totals[$metricName] = self::toInt($row['total'] ?? null);
            }
        }

        return $totals;
    }

    /**
     * Get time series data for metrics with query-time aggregation.
     *
     * Uses SUM for event metrics and argMax for gauge metrics.
     * When $type is null, queries both tables and merges results.
     *
     * @param  array<string>  $metrics
     * @param  string  $interval  '1h' or '1d'
     * @param  string  $startDate
     * @param  string  $endDate
     * @param  array<Query>  $queries
     * @param  bool  $zeroFill
     * @param  string|null  $type  'event', 'gauge', or null (both)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     * @throws Exception
     */
    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        if (empty($metrics)) {
            return [];
        }

        if (!isset(self::INTERVAL_FUNCTIONS[$interval])) {
            throw new \InvalidArgumentException("Invalid interval '{$interval}'. Allowed: " . implode(', ', array_keys(self::INTERVAL_FUNCTIONS)));
        }

        $this->setOperationContext('getTimeSeries()');

        // Initialize result structure
        $output = [];
        foreach ($metrics as $metric) {
            $output[$metric] = ['total' => 0, 'data' => []];
        }

        $typesToQuery = [];
        if ($type === Usage::TYPE_EVENT || $type === null) {
            $typesToQuery[] = Usage::TYPE_EVENT;
        }
        if ($type === Usage::TYPE_GAUGE || $type === null) {
            $typesToQuery[] = Usage::TYPE_GAUGE;
        }

        $metricTypes = [];

        foreach ($typesToQuery as $queryType) {
            // Skip a table when its schema can't satisfy every filter attribute
            // (e.g. `path` on a gauge query); avoids "Invalid attribute name"
            // when the caller leaves $type null and only one side is applicable.
            if (!$this->queriesMatchType($queries, $queryType)) {
                continue;
            }

            $typeResult = $this->getTimeSeriesFromTable($tenant, $metrics, $interval, $startDate, $endDate, $queries, $queryType);

            // Merge results
            foreach ($typeResult as $metricName => $metricData) {
                if (!isset($output[$metricName])) {
                    continue;
                }

                if (!empty($metricData['data'])) {
                    $metricTypes[$metricName] = $queryType;
                }

                $output[$metricName]['total'] += $metricData['total'];
                $output[$metricName]['data'] = array_merge(
                    $output[$metricName]['data'],
                    $metricData['data']
                );
            }
        }

        if ($zeroFill) {
            foreach ($output as $metricName => &$metricData) {
                $fillType = $metricTypes[$metricName] ?? $type ?? Usage::TYPE_EVENT;
                if ($fillType === Usage::TYPE_GAUGE) {
                    $metricData['data'] = $this->locfFillTimeSeries(
                        $metricData['data'],
                        $interval,
                        $startDate,
                        $endDate
                    );
                } else {
                    $metricData['data'] = $this->zeroFillTimeSeries(
                        $metricData['data'],
                        $interval,
                        $startDate,
                        $endDate
                    );
                }
            }
            unset($metricData);
        }

        return $output;
    }

    /**
     * Get time series data from a specific table.
     *
     * @param  array<string>  $metrics
     * @param  string  $interval
     * @param  string  $startDate
     * @param  string  $endDate
     * @param  array<Query>  $queries
     * @param  string  $type
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     * @throws Exception
     */
    private function getTimeSeriesFromTable(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries, string $type): array
    {
        $timeFunction = self::INTERVAL_FUNCTIONS[$interval];
        $tableName = $this->getTableForType($type);

        $parsed = $this->parseQueries($tenant, $queries, $type);

        $valueExpr = $type === Usage::TYPE_EVENT
            ? 'SUM(`value`) AS `agg_value`'
            : 'argMax(`value`, `time`) AS `agg_value`';

        $window = Query::between('time', $this->formatDateTime($startDate), $this->formatDateTime($endDate));

        // Hour-or-coarser, so both re-express on the hourly key when bounds align.
        $bucketed = $type === Usage::TYPE_EVENT
            ? $this->bucketAlignedFilters(array_merge($parsed['filters'], [$window]))
            : null;
        $timeExpr = $bucketed === null ? '`time`' : self::EVENT_TIME_BUCKET;

        $builder = $this->newBuilder($type)
            ->from($tableName)
            ->select(['metric'])
            ->selectRaw("{$timeFunction}({$timeExpr}, 'UTC') AS `bucket`")
            ->selectRaw($valueExpr)
            ->filter([Query::equal('metric', $metrics)])
            ->groupByRaw('`metric`, `bucket`')
            ->orderByRaw('`bucket` ASC');

        $bucketBindings = [];
        if ($bucketed === null) {
            $builder->filter([$window]);
        } else {
            $parsed['filters'] = $bucketed['filters'];
            $bucketBindings = $bucketed['bindings'];
            foreach ($bucketed['conditions'] as $condition) {
                $builder->whereRaw($condition);
            }
        }

        $this->applyFilters($builder, $tenant, $parsed);

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        $result = $this->query($sql, array_merge($statement->namedBindings ?? [], $bucketBindings));
        $rows = $this->decodeRows($result);

        // Initialize result structure
        $output = [];
        foreach ($metrics as $metric) {
            $output[$metric] = ['total' => 0.0, 'data' => []];
        }

        foreach ($rows as $row) {
            $metricName = self::toStr($row['metric'] ?? null);
            $bucketTime = self::toStr($row['bucket'] ?? null);
            $value = self::toFloat($row['agg_value'] ?? null);

            if (!isset($output[$metricName])) {
                continue;
            }

            // Format bucket time
            $formattedDate = $bucketTime;
            if (strpos($bucketTime, 'T') === false) {
                $formattedDate = str_replace(' ', 'T', $bucketTime) . '+00:00';
            }

            $output[$metricName]['total'] += $value;
            $output[$metricName]['data'][] = [
                'value' => $value,
                'date' => $formattedDate,
            ];
        }

        return $output;
    }

    /**
     * Fill gaps in time series data with zero-value entries.
     *
     * @param array<array{value: float, date: string}> $data Existing data points
     * @param string $interval '1h' or '1d'
     * @param string $startDate Start datetime
     * @param string $endDate End datetime
     * @return array<array{value: float, date: string}>
     */
    private function zeroFillTimeSeries(array $data, string $interval, string $startDate, string $endDate): array
    {
        $format = $interval === '1h' ? 'Y-m-d\TH:00:00+00:00' : 'Y-m-d\T00:00:00+00:00';
        $step = $interval === '1h' ? '+1 hour' : '+1 day';

        // Build lookup of existing data points by formatted date
        $existing = [];
        foreach ($data as $point) {
            $dt = new DateTime($point['date']);
            $key = $dt->format($format);
            // If multiple points in the same bucket, sum them
            $existing[$key] = ($existing[$key] ?? 0.0) + $point['value'];
        }

        // Generate all time buckets in range
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        $result = [];
        $current = clone $start;

        while ($current <= $end) {
            $key = $current->format($format);
            $result[] = [
                'value' => $existing[$key] ?? 0.0,
                'date' => $key,
            ];
            $current->modify($step);
        }

        return $result;
    }

    /**
     * Fill missing gauge buckets by carrying the last observation forward.
     *
     * Multiple points in the same bucket collapse to the most recent point's
     * value (last-write-wins), matching argMax(value, time) on the write side.
     *
     * @param array<array{value: float, date: string}> $data
     * @return array<array{value: float, date: string}>
     */
    private function locfFillTimeSeries(array $data, string $interval, string $startDate, string $endDate): array
    {
        $format = $interval === '1h' ? 'Y-m-d\TH:00:00+00:00' : 'Y-m-d\T00:00:00+00:00';
        $step = $interval === '1h' ? '+1 hour' : '+1 day';

        usort($data, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $existing = [];
        foreach ($data as $point) {
            $dt = new DateTime($point['date']);
            $key = $dt->format($format);
            $existing[$key] = $point['value'];
        }

        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        $result = [];
        $current = clone $start;
        $lastValue = 0.0;
        $seenAny = false;

        while ($current <= $end) {
            $key = $current->format($format);
            if (array_key_exists($key, $existing)) {
                $lastValue = $existing[$key];
                $seenAny = true;
            }
            $result[] = [
                // Pre-window buckets fall back to 0 rather than fabricating a value.
                'value' => $seenAny ? $lastValue : 0.0,
                'date' => $key,
            ];
            $current->modify($step);
        }

        return $result;
    }

    /**
     * Get total value for a single metric.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     * When $type is null, queries both tables.
     *
     * @param  string  $metric
     * @param  array<Query>  $queries
     * @param  string|null  $type  'event', 'gauge', or null (both)
     * @return int
     * @throws Exception
     */
    public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int
    {
        $this->setOperationContext('getTotal()');

        if ($type === Usage::TYPE_EVENT) {
            return $this->getTotalFromEvents($tenant, $metric, $queries);
        }

        if ($type === Usage::TYPE_GAUGE) {
            return $this->getTotalFromGauges($tenant, $metric, $queries);
        }

        // Query both tables — event uses SUM, gauge uses argMax
        $eventTotal = $this->getTotalFromEvents($tenant, $metric, $queries);
        $gaugeTotal = $this->getTotalFromGauges($tenant, $metric, $queries);

        if ($eventTotal > 0 && $gaugeTotal > 0) {
            throw new Exception(
                "Metric '{$metric}' exists in both event and gauge tables. "
                . "Specify \$type explicitly to avoid ambiguous aggregation."
            );
        }

        return $eventTotal > 0 ? $eventTotal : $gaugeTotal;
    }

    /**
     * Get total from events table (SUM). Routes through routedSum() so
     * closed-day windows hit the daily MV.
     *
     * @param string $metric
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    private function getTotalFromEvents(string $tenant, string $metric, array $queries): int
    {
        $queries[] = Query::equal('metric', [$metric]);
        return $this->routedSum($tenant, $queries, 'getTotal');
    }

    /**
     * Get total from gauges table (argMax). Records a route decision so
     * ops dashboards see gauge getTotal calls alongside the rest.
     *
     * @param string $metric
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    private function getTotalFromGauges(string $tenant, string $metric, array $queries): int
    {
        $queries[] = Query::equal('metric', [$metric]);

        $plan = $this->extractRoutingPlan($queries);
        $this->recordRoute('getTotal', $plan, 'raw');

        $tableName = $this->getGaugesTableName();

        $parsed = $this->parseQueries($tenant, $queries, Usage::TYPE_GAUGE);

        $builder = $this->newBuilder(Usage::TYPE_GAUGE)
            ->from($tableName)
            ->selectRaw('argMax(`value`, `time`) AS `total`');

        $this->applyFilters($builder, $tenant, $parsed);

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        return $this->decodeTotal($this->query($sql, $statement->namedBindings ?? []));
    }

    /**
     * Get totals for multiple metrics in a single query.
     *
     * When $type is null both tables are queried with their type-appropriate
     * aggregator (SUM for events, argMax for gauges). If a metric appears in
     * both tables the result of mixing those aggregators is meaningless, so
     * the second occurrence raises an exception — callers must specify $type
     * to disambiguate.
     *
     * @param  array<string>  $metrics
     * @param  array<Query>  $queries
     * @param  string|null  $type  'event', 'gauge', or null (both)
     * @return array<string, int>
     * @throws Exception
     */
    public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array
    {
        if (empty($metrics)) {
            return [];
        }

        $this->setOperationContext('getTotalBatch()');

        // Initialize all metrics to 0
        $totals = \array_fill_keys($metrics, 0);

        // Track which type contributed a non-zero value to detect ambiguous mixing.
        $contributingType = [];

        $typesToQuery = [];
        if ($type === Usage::TYPE_EVENT || $type === null) {
            $typesToQuery[] = Usage::TYPE_EVENT;
        }
        if ($type === Usage::TYPE_GAUGE || $type === null) {
            $typesToQuery[] = Usage::TYPE_GAUGE;
        }

        foreach ($typesToQuery as $queryType) {
            // Event totals route like sum()/getTotal(): closed-day windows
            // read the daily rollup, open ones read rollup + today's raw tail.
            // Gauges have no rollup and always read their own table.
            $typeTotals = $queryType === Usage::TYPE_EVENT
                ? $this->routedTotalBatch($tenant, $metrics, $queries)
                : $this->totalBatchFromTable($tenant, $metrics, $queries, $queryType);

            foreach ($typeTotals as $metricName => $rowValue) {
                if (!isset($totals[$metricName])) {
                    continue;
                }

                if ($rowValue === 0) {
                    continue;
                }

                if ($type === null
                    && isset($contributingType[$metricName])
                    && $contributingType[$metricName] !== $queryType) {
                    throw new Exception(
                        "Metric '{$metricName}' exists in both event and gauge tables. "
                        . "Specify \$type explicitly to avoid ambiguous aggregation."
                    );
                }

                $contributingType[$metricName] = $queryType;
                $totals[$metricName] = $rowValue;
            }
        }

        return $totals;
    }

    /**
     * One grouped total per metric from a single physical table: SUM for the
     * raw events table, latest-sample argMax for gauges.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @return array<string, int>
     * @throws Exception
     */
    private function totalBatchFromTable(string $tenant, array $metrics, array $queries, string $type): array
    {
        $tableName = $this->getTableForType($type);

        $parsed = $this->parseQueries($tenant, $queries, $type);

        $valueExpr = $type === Usage::TYPE_EVENT
            ? 'SUM(`value`) AS `agg_val`'
            : 'argMax(`value`, `time`) AS `agg_val`';

        $builder = $this->newBuilder($type)
            ->from($tableName)
            ->select(['metric'])
            ->selectRaw($valueExpr)
            ->filter([Query::equal('metric', $metrics)])
            ->groupByRaw('`metric`');

        $this->applyFilters($builder, $tenant, $parsed);

        $statement = $builder->build();
        $sql = $this->qualifyDdl($statement->query, $tableName) . ' FORMAT JSON';

        $totalsByMetric = [];
        foreach ($this->decodeRows($this->query($sql, $statement->namedBindings ?? [])) as $row) {
            $totalsByMetric[self::toStr($row['metric'] ?? null)] = self::toInt($row['agg_val'] ?? null);
        }

        return $totalsByMetric;
    }

    /**
     * Routed batched event totals: the same source selection sum()/getTotal()
     * use, applied to one grouped-by-metric query, with the decision recorded
     * in the route log under `getTotalBatch`.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @return array<string, int>
     * @throws Exception
     */
    private function routedTotalBatch(string $tenant, array $metrics, array $queries): array
    {
        $plan = $this->extractRoutingPlan(array_merge($queries, [Query::equal('metric', $metrics)]));
        $route = $this->selectAggregateSource($plan);
        $this->recordRoute('getTotalBatch', $plan, $route);

        if ($route === 'daily') {
            $totalsByMetric = $this->sumDailyBatch($tenant, $metrics, $this->translateInclusiveMidnightForDaily($queries));
            $this->maybeDualReadBatch($tenant, $metrics, $queries, $route, $plan, $totalsByMetric);
            return $totalsByMetric;
        }
        if ($route === 'hybrid') {
            $totalsByMetric = $this->totalBatchHybrid($tenant, $metrics, $queries);
            $this->maybeDualReadBatch($tenant, $metrics, $queries, $route, $plan, $totalsByMetric);
            return $totalsByMetric;
        }
        if ($route === 'split') {
            $totalsByMetric = $this->totalBatchSplit($tenant, $metrics, $queries, $plan);
            $this->maybeDualReadBatch($tenant, $metrics, $queries, $route, $plan, $totalsByMetric);
            return $totalsByMetric;
        }

        return $this->totalBatchFromTable($tenant, $metrics, $queries, Usage::TYPE_EVENT);
    }

    /**
     * Hybrid batched event totals: closed days per metric from the daily
     * rollup, today's partial from the raw events table, folded by an outer
     * per-metric SUM over UNION ALL — the batched shape of
     * sumHybridDailyAndRaw().
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @return array<string, int>
     * @throws Exception
     */
    private function totalBatchHybrid(string $tenant, array $metrics, array $queries): array
    {
        $startOfToday = (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $dailyTableName = $this->getEventsDailyTableName();
        $eventsTableName = $this->getEventsTableName();

        $split = $this->splitTimeQueries($queries);

        $rawQueries = array_merge($queries, [Query::greaterThanEqual('time', $startOfToday)]);
        $dailyQueries = array_merge(
            $split['nonTime'],
            $this->buildDailyTimeQueries($split['time']),
            [Query::lessThan('time', $startOfToday)],
        );

        $rawParsed = $this->parseQueries($tenant, $rawQueries, Usage::TYPE_EVENT);
        $dailyParsed = $this->parseQueries($tenant, $dailyQueries, Usage::TYPE_EVENT);

        $rawBuilder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($eventsTableName)
            ->select(['metric'])
            ->selectRaw('SUM(`value`) AS `total`')
            ->filter([Query::equal('metric', $metrics)])
            ->groupByRaw('`metric`');
        $this->applyFilters($rawBuilder, $tenant, $rawParsed);
        $rawStatement = $rawBuilder->build();
        $rawSql = $this->qualifyDdl($rawStatement->query, $eventsTableName);

        $dailyBuilder = $this->newBuilder(Usage::TYPE_EVENT)
            ->from($dailyTableName)
            ->select(['metric'])
            ->selectRaw('SUM(`value`) AS `total`')
            ->filter([Query::equal('metric', $metrics)])
            ->groupByRaw('`metric`');
        $this->applyFilters($dailyBuilder, $tenant, $dailyParsed);
        $dailyStatement = $dailyBuilder->build();
        [$dailySql, $dailyBindings] = $this->prefixNamedBindings(
            $this->qualifyDdl($dailyStatement->query, $dailyTableName),
            $dailyStatement->namedBindings ?? [],
            'd_',
        );

        $sql = "
            SELECT `metric`, sum(`total`) AS `agg_val` FROM (
                {$dailySql}
                UNION ALL
                {$rawSql}
            )
            GROUP BY `metric`
            FORMAT JSON
        ";

        $result = $this->query($sql, array_merge($rawStatement->namedBindings ?? [], $dailyBindings));

        $totalsByMetric = [];
        foreach ($this->decodeRows($result) as $row) {
            $totalsByMetric[self::toStr($row['metric'] ?? null)] = self::toInt($row['agg_val'] ?? null);
        }

        return $totalsByMetric;
    }

    /**
     * Batched twin of maybeDualRead(): with the same sampling, re-run the
     * batch against the raw events table and log a warning per metric whose
     * routed total diverges.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string, orderColumns?: array<int, string>, hasCursor?: bool, hasIrregularTimeFilter?: bool, endInclusive?: bool} $plan
     * @param array<string, int> $rolledTotals
     */
    private function maybeDualReadBatch(string $tenant, array $metrics, array $queries, string $route, array $plan, array $rolledTotals): void
    {
        if ($this->dualReadSampleRate <= 0.0) {
            return;
        }
        if (mt_rand() / mt_getrandmax() > $this->dualReadSampleRate) {
            return;
        }

        try {
            $rawTotals = $this->totalBatchFromTable($tenant, $metrics, $queries, Usage::TYPE_EVENT);
        } catch (Throwable $e) {
            return;
        }

        foreach ($metrics as $metric) {
            $rawTotal = $rawTotals[$metric] ?? 0;
            $rolledTotal = $rolledTotals[$metric] ?? 0;

            if ($rawTotal === 0 && $rolledTotal === 0) {
                continue;
            }

            $denominator = $rawTotal === 0 ? max(abs($rolledTotal), 1) : abs($rawTotal);
            $delta = abs($rolledTotal - $rawTotal) / $denominator;
            if ($delta > 0.01) {
                $this->appendRouteLogEntry([
                    'operation' => 'dual_read_warning',
                    'metric' => $metric,
                    'route' => $route . ':delta=' . round($delta, 4),
                    'start' => $plan['start'],
                    'end' => $plan['end'],
                    'dimensions' => $plan['dimensions'],
                    'interval' => $plan['interval'],
                ]);
            }
        }
    }

    /**
     * Resolve the ClickHouse parameter type for a column.
     *
     * Used by both filter binding and cursor keyset comparison so values are
     * bound with the column's actual SQL type — binding a numeric column as
     * `String` would compare values lexicographically (`"9" > "10"`) and
     * silently produce incorrect filter results or page boundaries. Add a
     * branch here when introducing a new typed column.
     *
     * @param string $attribute
     * @return string ClickHouse parameter type (e.g. 'String', 'DateTime64(3, \'UTC\')', 'Int64')
     */
    private function getParamType(string $attribute): string
    {
        return match ($attribute) {
            'time' => "DateTime64(3, 'UTC')",
            'value' => 'Int64',
            default => 'String',
        };
    }

    /**
     * Format a value for the given ClickHouse parameter type.
     *
     * Routes DateTime-typed columns through formatDateTime() and everything
     * else through formatParamValue(). Centralising this dispatch keeps
     * parseQueries and buildCursorWhere consistent across libraries.
     *
     * @param string $chType ClickHouse parameter type as returned by getParamType()
     * @param mixed $value
     * @return string
     * @throws Exception
     */
    private function formatTypedValue(string $chType, mixed $value): string
    {
        if ($chType === "DateTime64(3, 'UTC')") {
            if ($value === null) {
                throw new Exception('DateTime parameter value cannot be null');
            }
            /** @var DateTime|string $value */
            return $this->formatDateTime($value);
        }

        return $this->formatParamValue($value);
    }

    /**
     * Normalize a user-supplied cursor row into a column-keyed array.
     *
     * Accepts a `Metric` (or any `ArrayObject`) or a plain associative array.
     * `Metric` stores its identifier under `$id` (Appwrite convention) while
     * the underlying column is `id` — this remaps `$id` → `id` so cursor
     * pagination can match the SQL column.
     *
     * @param mixed $rawCursor
     * @return array<string, mixed>
     * @throws Exception
     */
    private function normalizeCursorRow(mixed $rawCursor): array
    {
        if ($rawCursor instanceof ArrayObject) {
            /** @var array<string, mixed> $row */
            $row = $rawCursor->getArrayCopy();
        } elseif (is_array($rawCursor)) {
            /** @var array<string, mixed> $rawCursor */
            $row = $rawCursor;
        } else {
            throw new Exception(
                'Invalid cursor value: expected ArrayObject (Metric) or associative array, got '
                . get_debug_type($rawCursor)
            );
        }

        if (!array_key_exists('id', $row) && array_key_exists('$id', $row)) {
            $row['id'] = $row['$id'];
            unset($row['$id']);
        }

        return $row;
    }

    /**
     * Resolve the effective order attributes for cursor pagination.
     *
     * Auto-appends `id` as a tiebreaker when not already present so keyset
     * pagination is deterministic on non-unique columns (e.g. time).
     *
     * @param array<int, array{attribute: string, direction: string}> $orderAttributes
     * @return array<int, array{attribute: string, direction: string}>
     */
    private function resolveCursorOrder(array $orderAttributes): array
    {
        foreach ($orderAttributes as $entry) {
            if ($entry['attribute'] === 'id') {
                return $orderAttributes;
            }
        }

        $defaultDirection = 'ASC';
        if (!empty($orderAttributes)) {
            $last = $orderAttributes[count($orderAttributes) - 1];
            $defaultDirection = $last['direction'];
        }

        $orderAttributes[] = ['attribute' => 'id', 'direction' => $defaultDirection];

        return $orderAttributes;
    }

    /**
     * Compile keyset-pagination WHERE fragments for cursor support and
     * register them on the builder via `whereRaw`. Returns the named
     * bindings to merge into the statement's bindings at execute time.
     *
     * Produces a tuple-compare clause across the order attributes:
     *   (a > A) OR (a = A AND b > B) OR ...
     *
     * For cursor `before`, the comparison directions are flipped relative to
     * the requested ORDER BY (the caller is responsible for also flipping the
     * actual ORDER BY at SQL build time so the page comes back from the right
     * side, then reversing the rows post-fetch).
     *
     * @param array<int, array{attribute: string, direction: string}> $orderAttributes
     * @param array<string, mixed> $cursor
     * @param string $cursorDirection 'after' or 'before'
     * @return array<string, mixed>
     * @throws Exception
     */
    private function applyCursorWhere(ClickHouseBuilder $builder, array $orderAttributes, array $cursor, string $cursorDirection): array
    {
        $orderAttributes = $this->resolveCursorOrder($orderAttributes);

        $params = [];
        $tuples = [];
        foreach ($orderAttributes as $i => $entry) {
            $attr = $entry['attribute'];
            $direction = $entry['direction'];

            if (!array_key_exists($attr, $cursor)) {
                throw new Exception("Cursor is missing required attribute '{$attr}'");
            }

            // Flip comparison direction for `before` so we paginate to the previous page.
            if ($cursorDirection === 'before') {
                $direction = $direction === 'DESC' ? 'ASC' : 'DESC';
            }

            $conditions = [];

            for ($j = 0; $j < $i; $j++) {
                $prev = $orderAttributes[$j];
                $prevAttr = $prev['attribute'];
                if (!array_key_exists($prevAttr, $cursor)) {
                    throw new Exception("Cursor is missing required attribute '{$prevAttr}'");
                }
                $prevValue = $cursor[$prevAttr];
                if ($prevValue === null) {
                    throw new Exception("Cursor value for '{$prevAttr}' cannot be null");
                }
                $prevEscaped = $this->escapeIdentifier($prevAttr);
                $prevType = $this->getParamType($prevAttr);
                $paramName = "cursor_eq_{$i}_{$j}";

                $conditions[] = "{$prevEscaped} = {{$paramName}:{$prevType}}";
                $params[$paramName] = $this->formatTypedValue($prevType, $prevValue);
            }

            $value = $cursor[$attr];
            if ($value === null) {
                throw new Exception("Cursor value for '{$attr}' cannot be null");
            }
            $escaped = $this->escapeIdentifier($attr);
            $chType = $this->getParamType($attr);
            $operator = $direction === 'DESC' ? '<' : '>';
            $paramName = "cursor_cmp_{$i}";

            $conditions[] = "{$escaped} {$operator} {{$paramName}:{$chType}}";
            $params[$paramName] = $this->formatTypedValue($chType, $value);

            $tuples[] = '(' . implode(' AND ', $conditions) . ')';
        }

        $builder->whereRaw('(' . implode(' OR ', $tuples) . ')');

        return $params;
    }

    /**
     * Apply the ORDER BY chain on the builder, optionally flipping all
     * directions.
     *
     * Used when cursor direction is `before` — we run the query in reverse to
     * grab the previous-page rows, then `array_reverse` the result.
     *
     * @param array<int, array{attribute: string, direction: string}> $orderAttributes
     * @param bool $flip Whether to flip ASC↔DESC
     */
    private function applyOrderBy(ClickHouseBuilder $builder, array $orderAttributes, bool $flip = false): void
    {
        foreach ($orderAttributes as $entry) {
            $direction = $entry['direction'];
            if ($flip) {
                $direction = $direction === 'DESC' ? 'ASC' : 'DESC';
            }

            if ($direction === 'DESC') {
                $builder->sortDesc($entry['attribute']);
            } else {
                $builder->sortAsc($entry['attribute']);
            }
        }
    }

    /**
     * Parse Query objects into the builder-consumable pieces: filter query
     * list, order attributes, pagination, grouping metadata and cursor.
     *
     * Validation is centralised here — attribute names, value requirements,
     * pagination types and grouping arguments are all checked before any
     * query reaches the builder. Tenant scoping is applied at execution time
     * via applyFilters(), which every read/delete path funnels through; the
     * empty-tenant guard lives here so no parse can succeed without a valid
     * tenant in shared-tables mode.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<Query> $queries
     * @param string $type 'event' or 'gauge' — used for attribute validation
     * @return array{filters: array<Query>, orderAttributes: array<int, array{attribute: string, direction: string}>, limit?: int, offset?: int, groupByInterval?: string, groupBy?: array<int, string>, aggregate?: string, cursor?: array<string, mixed>, cursorDirection?: string}
     * @throws Exception
     */
    private function parseQueries(?string $tenant, array $queries, string $type = 'event'): array
    {
        // A null tenant is the explicit cross-tenant read used by operator-side
        // aggregation ({@see findAcrossTenants}) — no tenant filter is applied.
        // It is deliberately distinct from '': an empty string would compile to
        // `tenant = ''` and silently read an empty scope, so that still fails
        // fast, like the write side. ("0" is a valid tenant id, so check for ''
        // specifically.)
        //
        // Only the validation lives here. The filter itself is applied through
        // the builder in applyTenantFilter(), which is why this no longer
        // unshifts a tenant query onto $queries.
        if ($this->sharedTables && $tenant === '') {
            throw new Exception('Tenant cannot be empty in shared-tables mode');
        }

        $filters = [];
        $orderAttributes = [];
        $limit = null;
        $offset = null;
        $groupByInterval = null;
        $groupBy = [];
        $aggregate = null;
        $cursor = null;
        $cursorDirection = null;

        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            // Reject empty values for filter methods that take values — mirrors
            // the validator in utopia-php/database (Validator/Query/Filter.php)
            // and prevents silently dropping the WHERE fragment, which would
            // otherwise turn `Query::contains('attr', [])` into a full-table
            // match instead of an empty result.
            if (\in_array($method, self::VALUE_REQUIRED_METHODS, true) && empty($values)) {
                throw new Exception(\ucfirst($method->value) . ' queries require at least one value.');
            }

            switch ($method) {
                case Method::Equal:
                case Method::NotEqual:
                case Method::LessThan:
                case Method::LessThanEqual:
                case Method::GreaterThan:
                case Method::GreaterThanEqual:
                case Method::Between:
                case Method::NotBetween:
                case Method::IsNull:
                case Method::IsNotNull:
                    $this->validateAttributeName($attribute, $type);
                    $filters[] = $query;
                    break;

                case Method::Contains:
                case Method::ContainsAny:
                case Method::NotContains:
                    // Substring match, mirroring utopia-php/database. The
                    // ClickHouse builder compiles these to `position(attr, ?)`,
                    // so needles are matched literally and `%` / `_` carry no
                    // wildcard meaning.
                    $this->validateAttributeName($attribute, $type);
                    foreach ($values as $value) {
                        if (!is_string($value)) {
                            throw new Exception("{$method->value} value must be a string for attribute '{$attribute}'");
                        }
                    }
                    $filters[] = $query;
                    break;

                case Method::StartsWith:
                case Method::EndsWith:
                    $this->validateAttributeName($attribute, $type);
                    $needle = $values[0] ?? null;
                    if (!is_string($needle)) {
                        $word = $method === Method::StartsWith ? 'startsWith' : 'endsWith';
                        throw new Exception("{$word} needle must be a string for attribute '{$attribute}'");
                    }
                    $filters[] = $query;
                    break;

                case Method::OrderDesc:
                    $this->validateAttributeName($attribute, $type);
                    $orderAttributes[] = ['attribute' => $attribute, 'direction' => 'DESC'];
                    break;

                case Method::OrderAsc:
                    $this->validateAttributeName($attribute, $type);
                    $orderAttributes[] = ['attribute' => $attribute, 'direction' => 'ASC'];
                    break;

                case Method::CursorAfter:
                case Method::CursorBefore:
                    if ($cursor !== null) {
                        // Keep the first cursor encountered (matches base groupByType semantics)
                        break;
                    }
                    $rawCursor = $values[0] ?? null;
                    if ($rawCursor === null) {
                        break; // no-op cursor
                    }
                    $cursor = $this->normalizeCursorRow($rawCursor);
                    $cursorDirection = $method === Method::CursorAfter ? 'after' : 'before';
                    break;

                case Method::Limit:
                    $limitVal = !empty($values) ? $values[0] : $values;
                    if (!\is_int($limitVal)) {
                        throw new Exception('Invalid limit value. Expected int');
                    }
                    $limit = $limitVal;
                    break;

                case Method::Offset:
                    $offsetVal = !empty($values) ? $values[0] : $values;
                    if (!\is_int($offsetVal)) {
                        throw new Exception('Invalid offset value. Expected int');
                    }
                    $offset = $offsetVal;
                    break;

                case Method::GroupByTimeBucket:
                    $this->validateAttributeName($attribute, $type);
                    $interval = $values[0] ?? '1h';
                    if (!is_string($interval)) {
                        throw new Exception(
                            'Invalid groupByInterval interval: expected string, got ' . get_debug_type($interval) . '. Allowed: '
                            . implode(', ', array_keys(UsageQuery::VALID_INTERVALS))
                        );
                    }
                    if (!isset(UsageQuery::VALID_INTERVALS[$interval])) {
                        throw new Exception(
                            "Invalid groupByInterval interval '{$interval}'. Allowed: "
                            . implode(', ', array_keys(UsageQuery::VALID_INTERVALS))
                        );
                    }
                    $groupByInterval = $interval;
                    break;

                case Method::GroupBy:
                    $dims = $attribute !== '' ? [$attribute] : [];
                    foreach ($values as $value) {
                        if (is_string($value) && $value !== '') {
                            $dims[] = $value;
                        }
                    }
                    foreach ($dims as $dim) {
                        $this->validateGroupByAttribute($dim, $type);
                        if (!in_array($dim, $groupBy, true)) {
                            $groupBy[] = $dim;
                        }
                    }
                    break;

                case Method::Max:
                    // Since query 0.3 the aggregate is carried by the method
                    // rather than by a value, so there is nothing to validate
                    // here: only a supported function can produce a method that
                    // reaches this case. extractAggregate() maps it back to its
                    // name so the rest of the adapter still works in strings.
                    $aggregate = UsageQuery::extractAggregate([$query]);
                    break;
                default:
                    // Same guard the Database adapter carries: a method with no
                    // arm would contribute nothing and the caller would get a
                    // wider result than it asked for. Fail where it can be seen.
                    throw new Exception('Unsupported query method for the ClickHouse adapter: ' . $method->value);
            }
        }

        $result = [
            'filters' => $this->normalizeTimeValues($filters),
            'orderAttributes' => $orderAttributes,
        ];

        if ($limit !== null) {
            $result['limit'] = $limit;
        }

        if ($offset !== null) {
            $result['offset'] = $offset;
        }

        if ($groupByInterval !== null) {
            $result['groupByInterval'] = $groupByInterval;
        }

        if (!empty($groupBy)) {
            $result['groupBy'] = $groupBy;
        }

        if ($aggregate !== null) {
            $result['aggregate'] = $aggregate;
        }

        if ($cursor !== null && $cursorDirection !== null) {
            $result['cursor'] = $cursor;
            $result['cursorDirection'] = $cursorDirection;
        }

        return $result;
    }

    /**
     * Parse ClickHouse JSON results into Metric array.
     *
     * @param string $result
     * @param string $type 'event' or 'gauge' — used to set the type attribute on parsed metrics
     * @return array<Metric>
     */
    private function parseResults(string $result, string $type = 'event'): array
    {
        if (empty(trim($result))) {
            return [];
        }

        $rows = $this->decodeRows($result);
        $metrics = [];

        foreach ($rows as $row) {
            $document = [];

            foreach ($row as $key => $value) {
                if ($key === 'tenant') {
                    $document[$key] = $value !== null ? self::toStr($value) : null;
                } elseif ($key === 'value') {
                    $document[$key] = $value !== null ? self::toInt($value) : null;
                } elseif ($key === 'time') {
                    $parsedTime = self::toStr($value);
                    if (strpos($parsedTime, 'T') === false) {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document[$key] = $parsedTime;
                } elseif ($key === 'tags') {
                    if (is_string($value)) {
                        $document[$key] = json_decode($value, true) ?? [];
                    } else {
                        $document[$key] = $value;
                    }
                } else {
                    $document[$key] = $value;
                }
            }

            if (isset($document['id'])) {
                $document['$id'] = $document['id'];
                unset($document['id']);
            }

            // Set the type based on which table we queried
            $document['type'] = $type;

            $metrics[] = new Metric($document);
        }

        return $metrics;
    }

    /**
     * Get the SELECT column list for queries.
     *
     * @param string $type 'event' or 'gauge'
     * @return list<string>
     */
    private function getSelectColumns(string $type = 'event'): array
    {
        $columns = ['id'];

        foreach ($this->getAttributes($type) as $attribute) {
            $id = $attribute['$id'];
            if (is_string($id)) {
                $columns[] = $id;
            }
        }

        if ($this->sharedTables) {
            $columns[] = 'tenant';
        }

        return $columns;
    }

    /**
     * Purge usage metrics matching the given queries.
     * Deletes from the specified table(s).
     *
     * For event purges, also deletes matching rows from the pre-aggregated
     * daily table — materialized views are forward-only triggers, so deletes
     * on the source table do not propagate to the MV target. Only daily-table
     * compatible filters (metric, value, time, tenant) are forwarded; queries
     * with event-only attributes (path/method/status/etc.) leave existing
     * daily rows in place.
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (purge both)
     * @throws Exception
     */
    public function purge(string $tenant, array $queries = [], ?string $type = null): bool
    {
        $this->setOperationContext('purge()');

        $typesToPurge = [];
        if ($type === Usage::TYPE_EVENT || $type === null) {
            $typesToPurge[] = Usage::TYPE_EVENT;
        }
        if ($type === Usage::TYPE_GAUGE || $type === null) {
            $typesToPurge[] = Usage::TYPE_GAUGE;
        }

        foreach ($typesToPurge as $purgeType) {
            $tableName = $this->getTableForType($purgeType);

            $parsed = $this->parseQueries($tenant, $queries, $purgeType);

            $builder = $this->newBuilder($purgeType)->from($tableName);

            $this->applyFilters($builder, $tenant, $parsed);

            $builder->whereRaw('1 = 1');

            $statement = $builder->delete();
            $sql = $this->qualifyDdl($statement->query, $tableName);

            $this->query($sql, $statement->namedBindings ?? []);

            if ($purgeType === Usage::TYPE_EVENT) {
                $this->purgeDaily($tenant, $queries);
            }
        }

        return true;
    }

    /**
     * Build a query list for the "filter not expressible on rollup" branch:
     * keep only filters on rollup-safe columns (metric, time, tenant), drop
     * any narrowing filter the rollup can't apply, then widen time to whole
     * days. The result deletes a superset of the affected raw rows —
     * accuracy on the rest of that day's rollup row degrades, but
     * staleness is worse than degradation, and the next ingest cycle adds
     * data back.
     *
     * @param array<Query> $queries
     * @param array<int, string> $safeAttributes
     * @return array<Query>
     */
    private function buildStaleRollupPurgeQueries(array $queries, array $safeAttributes): array
    {
        $filtered = [];
        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if ($attr === '' || in_array($attr, $safeAttributes, true)) {
                $filtered[] = $query;
            }
        }
        return $this->translateTimeQueriesToDayBoundaries($filtered);
    }

    /**
     * Purge the events_daily SummingMergeTree.
     *
     * id and value are dropped from the safe filter set: id doesn't exist
     * on the daily table at all, and value is an aggregate column whose
     * semantics differ from the raw row's value (a daily row's value is
     * the SUM of the raw rows' values for that day, so `value = 10`
     * means "the day's total was 10", not "a raw row had value 10").
     * Time bounds widen to whole-day boundaries. Filters on event-only
     * attributes (path / method / status / etc.) trigger a whole-day
     * delete using only the daily-safe subset, because leaving the
     * rollup with stale rows over-reports under routed reads.
     *
     * An empty `$queries` argument means "purge everything" and issues
     * `DELETE WHERE 1 = 1`. A non-empty argument whose filters cannot be
     * expressed on the daily schema AND leave no time bound is treated
     * as a no-op on the daily side: an unbounded delete here would wipe
     * unrelated metrics. The next ingest cycle will overwrite any rows
     * the caller's raw-table purge left stale.
     *
     * @param array<Query> $queries  The caller's filters (tenant-free); the
     *        tenant is applied as a scope on the resulting delete.
     * @throws Exception
     */
    private function purgeDaily(string $tenant, array $queries): void
    {
        $safeAttributes = array_values(array_filter(
            self::DAILY_COLUMNS,
            fn (string $col): bool => $col !== 'value'
        ));
        $safeAttributes = array_merge(['time'], $safeAttributes);
        if ($this->sharedTables) {
            $safeAttributes[] = 'tenant';
        }

        $compatible = true;
        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if ($attr === '') {
                continue;
            }
            if (!in_array($attr, $safeAttributes, true)) {
                $compatible = false;
                break;
            }
        }

        $dailyQueries = $compatible
            ? $this->translateTimeQueriesToDayBoundaries($queries)
            : $this->buildStaleRollupPurgeQueries($queries, $safeAttributes);

        if (!empty($queries) && empty($dailyQueries)) {
            return;
        }

        // The compatibility decision above deliberately runs on the caller's
        // tenant-free filters, so the tenant scope can never make an
        // imprecise purge look safe to forward to the rollup.
        $tableName = $this->getEventsDailyTableName();

        $parsed = $this->parseQueries($tenant, $dailyQueries, Usage::TYPE_EVENT);

        $builder = $this->newBuilder(Usage::TYPE_EVENT)->from($tableName);

        $this->applyFilters($builder, $tenant, $parsed);

        $builder->whereRaw('1 = 1');

        $statement = $builder->delete();
        $sql = $this->qualifyDdl($statement->query, $tableName);

        $this->query($sql, $statement->namedBindings ?? []);
    }
}
