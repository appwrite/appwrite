<?php

namespace Utopia\Audit\Adapter;

use Exception;
use Utopia\Audit\Log;
use Utopia\Audit\Query;
use Utopia\Database\Database;
use Utopia\Fetch\Client;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\ClickHouse\Format;
use Utopia\Query\Method;
use Utopia\Query\Query as BaseQuery;
use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\ClickHouse\Engine as ClickHouseEngine;
use Utopia\Query\Schema\ClickHouse\IndexAlgorithm;
use Utopia\Query\Schema\ColumnType;
use Utopia\Validator\Hostname;

/**
 * ClickHouse Adapter for Audit
 *
 * This adapter stores audit logs in ClickHouse using HTTP interface.
 * ClickHouse is optimized for analytical queries and can handle massive amounts of log data.
 */
class ClickHouse extends SQL
{
    private const int DEFAULT_PORT = 8123;

    private const string DEFAULT_TABLE = 'audits';

    private const string DEFAULT_DATABASE = 'default';

    /**
     * @var list<string>
     */
    private const array LOW_CARDINALITY_COLUMNS = [
        'event',
        'actorType',
        'resourceType',
        'country',
        'sdk',
        // premium geo (autonomousSystemNumber is intentionally NOT low-cardinality:
        // ~100k ASNs globally can exceed ClickHouse's LowCardinality sweet spot)
        'continentCode',
        'subdivisions',
        'connectionType',
        'connectionUsageType',
        // user-agent — bounded name/code/type dimensions (version/model strings
        // stay high-cardinality Nullable(String), mirroring sdk / sdkVersion)
        'osCode',
        'osName',
        'clientType',
        'clientCode',
        'clientName',
        'clientEngine',
        'deviceName',
        'deviceBrand',
    ];

    /**
     * Filter methods that must be supplied at least one value. Empty `values`
     * arrays for these methods are rejected up front so they can't silently
     * compile into a "no filter applied" WHERE clause.
     *
     * @var list<Method>
     */
    private const array VALUE_REQUIRED_METHODS = [
        Method::Equal,
        Method::NotEqual,
        Method::LessThan,
        Method::LessThanEqual,
        Method::GreaterThan,
        Method::GreaterThanEqual,
        Method::Between,
        Method::NotBetween,
        Method::Contains,
        Method::NotContains,
        Method::StartsWith,
        Method::NotStartsWith,
        Method::EndsWith,
        Method::NotEndsWith,
        Method::Regex,
        Method::Select,
    ];

    private readonly string $host;

    private readonly int $port;

    private string $database = self::DEFAULT_DATABASE;

    private string $table = self::DEFAULT_TABLE;

    private readonly Client $client;

    protected string $namespace = '';

    protected ?int $tenant = null;

    protected bool $sharedTables = false;

    protected bool $asyncCleanup = false;

    /** @var int|null Retention in days; when set, setup() applies a TTL on the table. Null disables TTL. */
    private ?int $retention = null;

    /**
     * @param string $host ClickHouse host
     * @param string $username ClickHouse username (default: 'default')
     * @param string $password ClickHouse password (default: '')
     * @param int $port ClickHouse HTTP port (default: 8123)
     * @param bool $secure Whether to use HTTPS (default: false)
     * @throws Exception If validation fails
     */
    public function __construct(
        string $host,
        private readonly string $username = 'default',
        private readonly string $password = '',
        int $port = self::DEFAULT_PORT,
        private bool $secure = false,
    ) {
        $this->validateHost($host);
        $this->validatePort($port);

        $this->host = $host;
        $this->port = $port;

        // Initialize the HTTP client for connection reuse
        $this->client = new Client();
        $this->client->addHeader('X-ClickHouse-User', $this->username);
        $this->client->addHeader('X-ClickHouse-Key', $this->password);
        $this->client->setTimeout(30_000); // 30 seconds
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return 'ClickHouse';
    }

    /**
     * Ping ClickHouse to check connectivity.
     *
     * Uses ClickHouse's dedicated /ping endpoint, which bypasses the query
     * pipeline, requires no database context, and is not recorded in query
     * logs. Returns false on any connectivity failure rather than throwing.
     *
     * @return bool True when ClickHouse is reachable, false otherwise.
     */
    public function ping(): bool
    {
        $scheme = $this->secure ? 'https' : 'http';
        $url = "{$scheme}://{$this->host}:{$this->port}/ping";

        try {
            $response = $this->client->fetch(url: $url, method: Client::METHOD_GET);
        } catch (\Throwable) {
            return false;
        }

        return $response->getStatusCode() === 200;
    }

    /**
     * Validate host parameter.
     *
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
     * ClickHouse identifiers follow SQL standard rules.
     *
     * @param string $type Name of the identifier type for error messages
     * @throws Exception
     */
    private function validateIdentifier(string $identifier, string $type = 'Identifier'): void
    {
        if ($identifier === '' || $identifier === '0') {
            throw new Exception("{$type} cannot be empty");
        }

        if (\strlen($identifier) > 255) {
            throw new Exception("{$type} cannot exceed 255 characters");
        }

        // ClickHouse identifiers: alphanumeric, underscores, cannot start with number
        if (!preg_match('/^[a-zA-Z_]\w*$/', $identifier)) {
            throw new Exception("{$type} must start with a letter or underscore and contain only alphanumeric characters and underscores");
        }

        // Check against SQL keywords (common ones)
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TABLE', 'DATABASE'];
        if (\in_array(strtoupper($identifier), $keywords, true)) {
            throw new Exception("{$type} cannot be a reserved SQL keyword");
        }
    }

    /**
     * Escape an identifier (database name, table name, column name) for safe use in SQL.
     * Uses backticks as per SQL standard for identifier quoting.
     */
    private function escapeIdentifier(string $identifier): string
    {
        // Backtick escaping: replace any backticks in the identifier with double backticks
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    /**
     * Set the namespace for multi-project support.
     * Namespace is used as a prefix for table names.
     *
     * @throws Exception
     */
    public function setNamespace(string $namespace): self
    {
        if ($namespace !== '' && $namespace !== '0') {
            $this->validateIdentifier($namespace, 'Namespace');
        }
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Set the database name for subsequent operations.
     *
     * @throws Exception
     */
    public function setDatabase(string $database): self
    {
        $this->validateIdentifier($database, 'Database');
        $this->database = $database;
        return $this;
    }

    /**
     * Set the table name for subsequent operations.
     *
     * @throws Exception
     */
    public function setTable(string $table): self
    {
        $this->validateIdentifier($table, 'Table');
        $this->table = $table;
        return $this;
    }

    /**
     * Get the table name (without namespace prefix).
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Enable or disable HTTPS for ClickHouse HTTP interface.
     */
    public function setSecure(bool $secure): self
    {
        $this->secure = $secure;
        return $this;
    }

    /**
     * Get the namespace.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Set the tenant ID for multi-tenant support.
     * Tenant is used to isolate audit logs by tenant.
     */
    public function setTenant(?int $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    /**
     * Get the tenant ID.
     */
    public function getTenant(): ?int
    {
        return $this->tenant;
    }

    /**
     * Set whether tables are shared across tenants.
     * When enabled, a tenant column is added to the table for data isolation.
     */
    public function setSharedTables(bool $sharedTables): self
    {
        $this->sharedTables = $sharedTables;
        return $this;
    }

    /**
     * Get whether tables are shared across tenants.
     */
    public function isSharedTables(): bool
    {
        return $this->sharedTables;
    }

    /**
     * Set whether cleanup() should return after scheduling the DELETE mutation
     * rather than waiting for it to complete. When enabled, the DELETE is sent
     * with `SETTINGS lightweight_deletes_sync = 0` and the HTTP call returns
     * as soon as the mutation is queued.
     */
    public function setAsyncCleanup(bool $asyncCleanup): self
    {
        $this->asyncCleanup = $asyncCleanup;
        return $this;
    }

    /**
     * Get whether cleanup() runs asynchronously.
     */
    public function isAsyncCleanup(): bool
    {
        return $this->asyncCleanup;
    }

    /**
     * Set the retention window in days. When set, setup() applies a TTL so
     * rows older than the window are dropped by background merges. Pass null
     * to disable (the default).
     *
     * @throws Exception If $days is not positive
     */
    public function setRetention(?int $days): self
    {
        if ($days !== null && $days < 1) {
            throw new Exception('Retention must be a positive number of days');
        }
        $this->retention = $days;
        return $this;
    }

    /**
     * Get the retention window in days, or null when TTL is disabled.
     */
    public function getRetention(): ?int
    {
        return $this->retention;
    }

    /**
     * Override getAttributes to provide extended attributes for ClickHouse.
     * Includes existing attributes from parent and adds new missing ones.
     *
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function getAttributes(): array
    {
        $parentAttributes = parent::getAttributes();

        foreach ($parentAttributes as &$attribute) {
            if (($attribute['$id'] ?? null) === 'userId') {
                $attribute['$id'] = 'actorId';
                break;
            }
        }
        unset($attribute);

        return [
            ...$parentAttributes,
            [
                '$id' => 'actorType',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => true,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'actorInternalId',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'resourceParent',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'resourceType',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => true,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'resourceId',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => true,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'resourceInternalId',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'country',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            // premium geo
            [
                '$id' => 'city',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'continentCode',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'subdivisions',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'isp',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'autonomousSystemNumber',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'autonomousSystemOrganization',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'connectionType',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'connectionUsageType',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'connectionOrganization',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'projectId',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => true,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'projectInternalId',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => true,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'teamId',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => true,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'teamInternalId',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => true,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'hostname',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => true,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'sdk',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'sdkVersion',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            // user-agent — parsed OS / client / device dimensions
            [
                '$id' => 'osCode',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'osName',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'osVersion',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'clientType',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'clientCode',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'clientName',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'clientVersion',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'clientEngine',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'clientEngineVersion',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'deviceName',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'deviceBrand',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'deviceModel',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => Database::LENGTH_KEY,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ],
        ];
    }

    /**
     * Override getIndexes to provide extended indexes for ClickHouse.
     * Includes existing indexes from parent and adds new missing ones.
     *
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function getIndexes(): array
    {
        $parentIndexes = parent::getIndexes();

        foreach ($parentIndexes as &$index) {
            if (($index['$id'] ?? null) === 'idx_userId_event') {
                $index['$id'] = 'idx_actorId_event';
                $index['attributes'] = ['actorId', 'event'];
                break;
            }
        }
        unset($index);

        return [
            ...$parentIndexes,
            [
                '$id' => '_key_actor_internal_and_event',
                'type' => Database::INDEX_KEY,
                'attributes' => ['actorInternalId', 'event'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_project_internal_id',
                'type' => Database::INDEX_KEY,
                'attributes' => ['projectInternalId'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_team_internal_id',
                'type' => Database::INDEX_KEY,
                'attributes' => ['teamInternalId'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_actor_internal_id',
                'type' => Database::INDEX_KEY,
                'attributes' => ['actorInternalId'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_actor_type',
                'type' => Database::INDEX_KEY,
                'attributes' => ['actorType'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_country',
                'type' => Database::INDEX_KEY,
                'attributes' => ['country'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_hostname',
                'type' => Database::INDEX_KEY,
                'attributes' => ['hostname'],
                'lengths' => [],
                'orders' => [],
            ],
            [
                '$id' => '_key_sdk',
                'type' => Database::INDEX_KEY,
                'attributes' => ['sdk'],
                'lengths' => [],
                'orders' => [],
            ],
        ];
    }

    /**
     * Get the table name with namespace prefix.
     * Namespace is used to isolate tables for different projects/applications.
     */
    private function getTableName(): string
    {
        $tableName = $this->table;

        if ($this->namespace !== '' && $this->namespace !== '0') {
            return $this->namespace . '_' . $tableName;
        }

        return $tableName;
    }

    /**
     * Build the column → ClickHouse type map registered on `Builder\ClickHouse`
     * so positional `?` bindings are emitted as typed `{paramN:Type}` placeholders.
     *
     * Derived from `getAttributes()` so the map stays in sync with the schema —
     * DateTime attributes get `DateTime64(3)`, everything else gets `String`.
     * `id` is added explicitly because it lives outside `getAttributes()`, and
     * `tenant` is added only when shared-tables mode is on. `limit`, `offset`
     * and `max` are pseudo-columns used by the count/find SQL wrappers.
     *
     * @return array<string, string>
     */
    private function getColumnTypeMap(): array
    {
        $map = ['id' => 'String'];

        foreach ($this->getAttributes() as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];
            $map[$id] = ($attribute['type'] ?? null) === Database::VAR_DATETIME
                ? 'DateTime64(3)'
                : 'String';
        }

        if ($this->sharedTables) {
            $map['tenant'] = 'UInt64';
        }

        $map['limit'] = 'UInt64';
        $map['offset'] = 'UInt64';
        $map['max'] = 'UInt64';

        return $map;
    }

    /**
     * Build a `Builder\ClickHouse` instance with the adapter's column type map
     * pre-registered. Every adapter call site that produces SQL goes through
     * here so positional `?` bindings can be rewritten to typed `{paramN:Type}`
     * placeholders at `Statement` time.
     */
    private function newBuilder(): ClickHouseBuilder
    {
        return new ClickHouseBuilder()
            ->useNamedBindings()
            ->withParamTypes($this->getColumnTypeMap());
    }

    /**
     * Execute a ClickHouse query via HTTP interface using Fetch Client.
     *
     * This unified method supports two modes of operation:
     *
     * 1. **Parameterized queries** (when $params is provided):
     *    Uses ClickHouse query parameters sent as POST multipart form data.
     *    Parameters are referenced in SQL using syntax: {paramName:Type}
     *    Example: SELECT * WHERE id = {id:String}
     *
     * 2. **Pre-serialized body queries** (when $rawBody is provided):
     *    Used for FORMAT-style INSERT operations (e.g. JSONEachRow).
     *    SQL envelope is sent via URL query string and the body is sent
     *    verbatim as the POST body. The caller (typically the typed
     *    `Builder\ClickHouse::bulkInsert()` entry point) is responsible for
     *    serializing rows into the format ClickHouse expects.
     *
     * ClickHouse handles all parameter escaping and type conversion internally,
     * making both approaches fully injection-safe.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params Key-value pairs for query parameters (for SELECT/UPDATE/DELETE)
     * @param string|null $rawBody Pre-serialized request body for FORMAT INSERT operations
     * @return string Response body
     * @throws Exception
     */
    private function query(string $sql, array $params = [], ?string $rawBody = null): string
    {
        $scheme = $this->secure ? 'https' : 'http';

        // Update the database header for each query (in case setDatabase was called)
        $this->client->addHeader('X-ClickHouse-Database', $this->database);

        try {
            if ($rawBody !== null) {
                // Pre-serialized body mode for FORMAT INSERT operations
                $url = "{$scheme}://{$this->host}:{$this->port}/?query=" . urlencode($sql);
                $body = $rawBody;
            } else {
                // Parameterized query mode using multipart form data
                $url = "{$scheme}://{$this->host}:{$this->port}/";

                // Build multipart form data body with query and parameters
                $body = ['query' => $sql];
                foreach ($params as $key => $value) {
                    $body['param_' . $key] = $this->formatParamValue($value);
                }
            }

            $response = $this->client->fetch(
                url: $url,
                method: Client::METHOD_POST,
                body: $body,
            );

            if ($response->getStatusCode() !== 200) {
                $responseBody = $response->getBody();
                $responseBody = \is_string($responseBody) ? $responseBody : '';
                throw new Exception("ClickHouse query failed with HTTP {$response->getStatusCode()}: {$responseBody}");
            }

            $responseBody = $response->getBody();
            return \is_string($responseBody) ? $responseBody : '';
        } catch (Exception $e) {
            throw new Exception(
                "ClickHouse query execution failed: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * Format a parameter value for safe transmission to ClickHouse.
     *
     * Converts PHP values to their string representation without SQL quoting.
     * ClickHouse's query parameter mechanism handles type conversion and escaping.
     */
    private function formatParamValue(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new Exception('Failed to encode array parameter to JSON: ' . $e->getMessage(), $e->getCode(), $e);
            }
        }

        if (\is_string($value)) {
            return $value;
        }

        // For objects or other types, attempt to convert to string
        if (\is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Setup ClickHouse table structure.
     *
     * Creates the database and table if they don't exist.
     * Uses schema definitions from the base SQL adapter.
     *
     * @throws Exception
     */
    public function setup(): void
    {
        // Create database if not exists
        $escapedDatabase = $this->escapeIdentifier($this->database);
        $this->query("CREATE DATABASE IF NOT EXISTS {$escapedDatabase}");

        $tableName = $this->getTableName();
        $qualifiedTable = $this->database . '.' . $tableName;
        $escapedDatabaseAndTable = $escapedDatabase . '.' . $this->escapeIdentifier($tableName);

        // Build the DDL through Schema\ClickHouse so column types, LowCardinality
        // wrappers, nullability, indexes, engine and settings all come from the
        // typed builder rather than hand-assembled SQL fragments.
        $schema = new ClickHouseSchema();
        $table = $schema->table($qualifiedTable);
        $table->string('id')->primary();

        foreach ($this->getAttributes() as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];

            // Special handling for time column - must be NOT NULL for partition key
            if ($id === 'time') {
                $table->datetime('time', precision: 3);

                continue;
            }

            $column = $table->addColumn($id, ($attribute['type'] ?? null) === Database::VAR_DATETIME
                ? ColumnType::Datetime
                : ColumnType::String);
            if (\in_array($id, self::LOW_CARDINALITY_COLUMNS, true)) {
                $column->lowCardinality();
            }
            if (empty($attribute['required'])) {
                $column->nullable();
            }
        }

        // Add tenant column only if tables are shared across tenants
        if ($this->sharedTables) {
            // Supports 11-digit MySQL auto-increment IDs
            $table->bigInteger('tenant')->unsigned()->nullable();
        }

        foreach ($this->getIndexes() as $index) {
            /** @var string $indexName */
            $indexName = $index['$id'];
            /** @var array<string> $attributes */
            $attributes = $index['attributes'];
            $table->index(
                columns: $attributes,
                name: $indexName,
                algorithm: IndexAlgorithm::BloomFilter,
                granularity: 1,
            );
        }

        $table->engine(ClickHouseEngine::MergeTree);
        $table->orderBy($this->sharedTables ? ['tenant', 'time', 'id'] : ['time', 'id']);
        $table->partitionBy('toYYYYMM(time)');

        $settings = ['index_granularity' => '8192'];
        if ($this->sharedTables) {
            $settings['allow_nullable_key'] = '1';
        }
        $table->settings($settings);

        $this->query($table->createIfNotExists()->query);

        // Apply retention as a separate, idempotent ALTER. CREATE TABLE IF NOT
        // EXISTS won't add a TTL to a table that already exists, and MODIFY TTL
        // is a no-op when the TTL already matches, so setup() stays re-runnable.
        // materialize_ttl_after_modify = 0 defers the purge to background merges
        // rather than an immediate, I/O-heavy mutation.
        if ($this->retention !== null) {
            $this->query(
                "ALTER TABLE {$escapedDatabaseAndTable} "
                . "MODIFY TTL toDateTime(time) + INTERVAL {$this->retention} DAY "
                . 'SETTINGS materialize_ttl_after_modify = 0',
            );
        } else {
            // Disabling retention must actively strip any TTL a previous run
            // applied; otherwise rows keep being purged despite retention being
            // null. ClickHouse errors (code 36) when REMOVE TTL runs on a table
            // that has no TTL, so swallow that specific case to keep setup()
            // idempotent.
            try {
                $this->query(
                    "ALTER TABLE {$escapedDatabaseAndTable} REMOVE TTL",
                );
            } catch (Exception $e) {
                if (!str_contains($e->getMessage(), "doesn't have any table TTL expression")) {
                    throw $e;
                }
            }
        }
    }


    /**
     * Get column names from attributes.
     * Returns an array of column names excluding 'id' and 'tenant' which are handled separately.
     *
     * @return array<string> Column names
     */
    private function getColumnNames(): array
    {
        $columns = [];
        foreach ($this->getAttributes() as $attribute) {
            /** @var string $columnName */
            $columnName = $attribute['$id'];
            // Exclude id and tenant as they're handled separately
            if ($columnName !== 'id' && $columnName !== 'tenant') {
                $columns[] = $columnName;
            }
        }
        return $columns;
    }

    /**
     * Validate that an attribute name exists in the schema.
     * Prevents SQL injection by ensuring only valid column names are used.
     *
     * @param string $attributeName The attribute name to validate
     * @return bool True if valid
     * @throws Exception If attribute name is invalid
     */
    /**
     * Translate legacy user* attribute names to actor* column names.
     */
    private function translateAttribute(string $attribute): string
    {
        return match ($attribute) {
            'userId' => 'actorId',
            'userType' => 'actorType',
            'userInternalId' => 'actorInternalId',
            default => $attribute,
        };
    }

    private function validateAttributeName(string $attributeName): bool
    {
        // Special case: 'id' is always valid
        if ($attributeName === 'id') {
            return true;
        }

        // Check if tenant is valid (only when sharedTables is enabled)
        if ($attributeName === 'tenant' && $this->sharedTables) {
            return true;
        }

        // Check against defined attributes
        foreach ($this->getAttributes() as $attribute) {
            if ($attribute['$id'] === $attributeName) {
                return true;
            }
        }

        throw new Exception("Invalid attribute name: {$attributeName}");
    }

    /**
     * Format datetime for ClickHouse compatibility.
     * Converts datetime to 'YYYY-MM-DD HH:MM:SS.mmm' format without timezone suffix.
     * ClickHouse DateTime64(3) type expects this format as timezone is handled by column metadata.
     * Works with DateTime objects, strings, and other datetime representations.
     *
     * @param \DateTime|string|null $dateTime The datetime value to format
     * @return string The formatted datetime string in ClickHouse compatible format
     * @throws Exception If the datetime string cannot be parsed
     */
    private function formatDateTime(\DateTime|string|null $dateTime): string
    {
        if ($dateTime === null) {
            return new \DateTime()->format('Y-m-d H:i:s.v');
        }

        if ($dateTime instanceof \DateTime) {
            return $dateTime->format('Y-m-d H:i:s.v');
        }

        try {
            // Parse the datetime string, handling ISO 8601 format with timezone
            $dt = new \DateTime($dateTime);
            return $dt->format('Y-m-d H:i:s.v');
        } catch (\Exception) {
            throw new Exception("Invalid datetime string: {$dateTime}");
        }
    }

    /**
     * Create an audit log entry
     *
     * @param array<string, mixed> $log The log data
     * @throws Exception
     */
    public function create(array $log): Log
    {
        // Generate ID if not provided
        $logId = $log['id'] ?? uniqid('', true);
        if (!\is_string($logId)) {
            throw new Exception('Log ID must be a string');
        }
        $log['id'] = $logId;

        // Use createBatch for the actual insertion
        $this->createBatch([$log]);

        // Retrieve the created log using getById to ensure consistency
        $createdLog = $this->getById($logId);
        if (!$createdLog instanceof \Utopia\Audit\Log) {
            throw new Exception("Failed to retrieve created log with ID: {$logId}");
        }

        return $createdLog;
    }

    /**
     * Get a single log by its ID using JSON format for reliable parsing.
     *
     * @return Log|null The log entry or null if not found
     * @throws Exception
     */
    public function getById(string $id): ?Log
    {
        $tableName = $this->getTableName();
        $qualifiedTable = $this->database . '.' . $tableName;

        $builder = $this->newBuilder()
            ->from($qualifiedTable)
            ->selectRaw($this->getSelectColumns())
            ->filter([Query::equal('id', $id)])
            ->limit(1);

        $this->applyTenantFilter($builder);

        $statement = $builder->build();
        $sql = $statement->query . ' FORMAT JSON';

        $result = $this->query($sql, $statement->namedBindings ?? []);
        $logs = $this->parseJsonResults($result);

        return $logs[0] ?? null;
    }

    /**
     * Find logs using Query objects.
     *
     * @param array<Query> $queries
     * @return array<Log>
     * @throws Exception
     */
    public function find(array $queries = []): array
    {
        $tableName = $this->getTableName();
        $qualifiedTable = $this->database . '.' . $tableName;

        $parsed = $this->parseQueries($queries);

        // Random ordering can't combine with anything that asks for a
        // specific row order: cursor pagination needs a stable anchor, and
        // mixing column-based ORDER BY with rand() would silently drop the
        // column order. Reject loudly in both cases so the caller fixes the
        // query rather than getting unexpected results.
        if ($parsed['randomOrder'] && isset($parsed['cursor'])) {
            throw new Exception('Cursor pagination cannot be combined with orderRandom');
        }
        if ($parsed['randomOrder'] && $parsed['orderAttributes'] !== []) {
            throw new Exception('orderRandom cannot be combined with orderAsc/orderDesc');
        }

        // Build SELECT clause — respect Query::select if provided, otherwise
        // fall back to the full column list.
        $selectColumns = $this->buildProjection($parsed['select'] ?? null);

        $builder = $this->newBuilder()
            ->from($qualifiedTable)
            ->selectRaw($selectColumns)
            ->filter($parsed['filters']);

        $this->applyTenantFilter($builder);

        $cursorDirection = $parsed['cursorDirection'] ?? null;
        $orderAttributes = $parsed['orderAttributes'];
        $cursorParams = [];

        if (isset($parsed['cursor'])) {
            $orderAttributes = $this->resolveCursorOrder($orderAttributes);
            $cursorWhere = $this->buildCursorWhere($orderAttributes, $parsed['cursor'], $cursorDirection ?? 'after', []);
            $builder->whereRaw($cursorWhere['clause']);
            $cursorParams = $cursorWhere['params'];
        }

        // ORDER BY. orderRandom is mutually exclusive with cursor and column
        // ordering (rejected above); when cursor is in play, rebuild from
        // orderAttributes (always non-empty after resolveCursorOrder, which
        // appends an id tiebreaker), flipping directions for `cursorBefore`.
        if ($parsed['randomOrder']) {
            $builder->sortRandom();
        } elseif (isset($parsed['cursor'])) {
            $this->applyOrderBy($builder, $orderAttributes, flip: $cursorDirection === 'before');
        } else {
            $this->applyOrderBy($builder, $orderAttributes);
        }

        if (isset($parsed['limit'])) {
            $builder->limit($parsed['limit']);
        }
        if (isset($parsed['offset'])) {
            $builder->offset($parsed['offset']);
        }

        $statement = $builder->build();
        $sql = $statement->query . ' FORMAT JSON';
        $params = ($statement->namedBindings ?? []) + $cursorParams;

        $result = $this->query($sql, $params);
        $rows = $this->parseJsonResults($result);

        if ($cursorDirection === 'before') {
            return array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Build the SELECT projection list. When `$select` is null, returns the
     * full column list from `getSelectColumns()`; otherwise validates and
     * escapes each requested column.
     *
     * `id` is always projected so `parseJsonResults` can map it back to the
     * `$id` field on the `Log` model. When `sharedTables` is enabled, the
     * `tenant` column is also always projected — it's metadata callers expect
     * on every row and the full-projection path already includes it. Callers
     * requesting a slim projection don't have to remember either.
     *
     * @param  list<string>|null  $select
     * @throws Exception
     */
    private function buildProjection(?array $select): string
    {
        if ($select === null) {
            return $this->getSelectColumns();
        }

        // Forced columns are injected here, so they're validated defensively.
        // User-supplied columns in $select are already validated inside the
        // TYPE_SELECT branch of parseQueries() — no need to walk
        // getAttributes() a second time per column.
        $forced = ['id'];
        if ($this->sharedTables) {
            $forced[] = 'tenant';
        }

        $columns = [];
        $seen = [];
        foreach ($forced as $column) {
            if (isset($seen[$column])) {
                continue;
            }
            $this->validateAttributeName($column);
            $columns[] = $this->escapeIdentifier($column);
            $seen[$column] = true;
        }
        foreach ($select as $column) {
            if (isset($seen[$column])) {
                continue;
            }
            $columns[] = $this->escapeIdentifier($column);
            $seen[$column] = true;
        }

        return implode(', ', $columns);
    }

    /**
     * Count logs using Query objects.
     *
     * When $max is non-null the count is bounded at the database level via a
     * `LIMIT {max}` inside a subquery — ClickHouse stops scanning once the cap
     * is reached, keeping large counts cheap (e.g. for "5000+" UI badges).
     *
     * @param array<Query> $queries
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @throws Exception
     */
    public function count(array $queries = [], ?int $max = null): int
    {
        $tableName = $this->getTableName();
        $qualifiedTable = $this->database . '.' . $tableName;

        // Parse queries - we only need filters, not ordering/limit/offset/cursor
        $parsed = $this->parseQueries($queries);

        $inner = $this->newBuilder()
            ->from($qualifiedTable)
            ->selectRaw($max !== null ? '1' : 'COUNT(*) AS count')
            ->filter($parsed['filters']);

        $this->applyTenantFilter($inner);

        if ($max !== null) {
            $inner->limit($max);
        }

        $statement = $inner->build();
        $params = $statement->namedBindings ?? [];

        $sql = $max !== null
            ? 'SELECT COUNT(*) AS count FROM (' . $statement->query . ') sub FORMAT TabSeparated'
            : $statement->query . ' FORMAT TabSeparated';

        $result = $this->query($sql, $params);
        $trimmed = trim($result);

        return $trimmed !== '' ? (int) $trimmed : 0;
    }

    /**
     * Parse Query objects into builder-ready filters and auxiliary metadata.
     *
     * Returns the input filters as a list of `Utopia\Query\Query` instances —
     * the caller hands them to `Builder\ClickHouse::filter()` which compiles
     * them into typed `{paramN:Type}` placeholders via the column → type map
     * registered on `newBuilder()`. Filter semantics are unchanged from the
     * hand-written SQL this replaces:
     *
     * - `Equal` with multiple values compiles to `IN (...)`.
     * - `Contains` / `NotContains` stay substring matches — the ClickHouse
     *   builder emits `position(col, ?) > 0` / `= 0`, which is equivalent to
     *   the previous `LIKE '%v%'` / `NOT LIKE '%v%'` form but needs no
     *   wildcard escaping.
     * - `Regex` compiles to ClickHouse's re2 `match(col, ?)` predicate.
     * - `time`-column values arriving as `\DateTimeInterface` are pre-formatted
     *   to ClickHouse's `Y-m-d H:i:s.v` literal so the HTTP layer doesn't see
     *   raw DateTime objects in `namedBindings`.
     *
     * @param  array<Query>  $queries
     * @return array{filters: array<int, BaseQuery>, orderAttributes: array<int, array{attribute: string, direction: string}>, randomOrder: bool, limit?: int, offset?: int, cursor?: array<string, mixed>, cursorDirection?: string, select?: list<string>}
     * @throws Exception
     */
    private function parseQueries(array $queries): array
    {
        $filters = [];
        $orderAttributes = [];
        $limit = null;
        $offset = null;
        $cursor = null;
        $cursorDirection = null;
        $select = null;
        $randomOrder = false;

        foreach ($queries as $query) {
            if (!$query instanceof Query) {
                $type = get_debug_type($query);
                throw new \InvalidArgumentException("Invalid query item: expected instance of Query, got {$type}");
            }

            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $attribute = $this->translateAttribute($attribute);
            $values = $query->getValues();

            // Reject empty values for filter methods that take values — mirrors
            // the validator in utopia-php/database (Validator/Query/Filter.php)
            // and prevents silently dropping the WHERE fragment, which would
            // otherwise turn `Query::contains('attr', [])` into a full-table
            // match instead of an empty result.
            if (\in_array($method, self::VALUE_REQUIRED_METHODS, true) && $values === []) {
                throw new \Exception(ucfirst($method->value) . ' queries require at least one value.');
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
                case Method::Contains:
                case Method::NotContains:
                case Method::IsNull:
                case Method::IsNotNull:
                case Method::StartsWith:
                case Method::NotStartsWith:
                case Method::EndsWith:
                case Method::NotEndsWith:
                case Method::Regex:
                    $this->validateAttributeName($attribute);
                    $filters[] = new BaseQuery($method, $attribute, $this->normalizeFilterValues($attribute, $values));
                    break;

                case Method::Select:
                    if ($values === []) {
                        // VALUE_REQUIRED_METHODS already rejects empty values
                        // earlier, but the explicit check keeps this branch safe
                        // if the guard is ever bypassed.
                        break;
                    }
                    // Multiple Query::select(...) calls combine into a single
                    // projection. Duplicates are removed; column names are
                    // validated and escaped at SQL build time in find().
                    $select ??= [];
                    foreach ($values as $column) {
                        if (!\is_string($column) || $column === '') {
                            throw new Exception('select columns must be non-empty strings');
                        }
                        $this->validateAttributeName($column);
                        if (!\in_array($column, $select, true)) {
                            $select[] = $column;
                        }
                    }
                    break;

                case Method::OrderDesc:
                    $this->validateAttributeName($attribute);
                    $orderAttributes[] = ['attribute' => $attribute, 'direction' => 'DESC'];
                    break;

                case Method::OrderAsc:
                    $this->validateAttributeName($attribute);
                    $orderAttributes[] = ['attribute' => $attribute, 'direction' => 'ASC'];
                    break;

                case Method::OrderRandom:
                    // ClickHouse's rand() is the per-row PRNG used for random
                    // sampling. Single emission across the result set — repeated
                    // Query::orderRandom() calls collapse into one ORDER BY rand().
                    $randomOrder = true;
                    break;

                case Method::Limit:
                    if (!\is_int($values[0])) {
                        throw new \Exception('Invalid limit value. Expected int');
                    }
                    $limit = $values[0];
                    break;

                case Method::Offset:
                    if (!\is_int($values[0])) {
                        throw new \Exception('Invalid offset value. Expected int');
                    }
                    $offset = $values[0];
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
            }
        }

        $result = [
            'filters' => $filters,
            'orderAttributes' => $orderAttributes,
            'randomOrder' => $randomOrder,
        ];

        if ($limit !== null) {
            $result['limit'] = $limit;
        }

        if ($offset !== null) {
            $result['offset'] = $offset;
        }

        if ($cursor !== null && $cursorDirection !== null) {
            $result['cursor'] = $cursor;
            $result['cursorDirection'] = $cursorDirection;
        }

        if ($select !== null) {
            $result['select'] = $select;
        }

        return $result;
    }

    /**
     * Normalize filter values so DateTime instances on the `time` column flow
     * through `namedBindings` as ClickHouse-compatible strings rather than raw
     * objects (the HTTP layer would otherwise serialise them as empty).
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     *
     * @throws Exception
     */
    private function normalizeFilterValues(string $attribute, array $values): array
    {
        if ($this->getParamType($attribute) !== 'DateTime64(3)') {
            return $values;
        }

        $normalized = [];
        foreach ($values as $value) {
            if ($value === null) {
                $normalized[] = null;

                continue;
            }
            /** @var \DateTime|string $value */
            $normalized[] = $this->formatDateTime($value);
        }

        return $normalized;
    }

    /**
     * Apply an ordered list of column directions to the builder via the
     * canonical `sortAsc` / `sortDesc` API, optionally flipping each direction
     * for `cursorBefore` pagination.
     *
     * @param  array<int, array{attribute: string, direction: string}>  $orderAttributes
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
     * Normalize a user-supplied cursor row into a column-keyed array.
     *
     * Accepts a `Log` (or any `ArrayObject`) or a plain associative array.
     * `Log` stores its identifier under `$id` (Appwrite convention) while the
     * underlying column is `id` — this remaps `$id` → `id` so cursor pagination
     * can match the SQL column.
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    private function normalizeCursorRow(mixed $rawCursor): array
    {
        if ($rawCursor instanceof \ArrayObject) {
            /** @var array<string, mixed> $row */
            $row = $rawCursor->getArrayCopy();
        } elseif (\is_array($rawCursor)) {
            /** @var array<string, mixed> $rawCursor */
            $row = $rawCursor;
        } else {
            throw new Exception(
                'Invalid cursor value: expected ArrayObject (Log) or associative array, got '
                . get_debug_type($rawCursor),
            );
        }

        if (!\array_key_exists('id', $row) && \array_key_exists('$id', $row)) {
            $row['id'] = $row['$id'];
            unset($row['$id']);
        }

        return $row;
    }

    /**
     * Resolve the ClickHouse parameter type for a column.
     *
     * Used by both filter binding and cursor keyset comparison so values are
     * bound with the column's actual SQL type — binding a numeric column as
     * `String` would compare values lexicographically (`"9" > "10"`) and
     * silently produce incorrect filter results or page boundaries. Add a
     * branch here when introducing a new non-String column type.
     *
     * @return string ClickHouse parameter type (e.g. 'String', 'DateTime64(3)', 'UInt64')
     */
    private function getParamType(string $attribute): string
    {
        // Derived from the same map the builder is given, rather than a second
        // hardcoded list. The two used to be able to disagree: this matched only
        // `time`, while getColumnTypeMap() types every VAR_DATETIME attribute, so
        // a second datetime column would have been bound as String here and
        // declared DateTime64(3) there.
        return $this->getColumnTypeMap()[$attribute] ?? 'String';
    }

    /**
     * Format a value for the given ClickHouse parameter type.
     *
     * Routes DateTime-typed columns through formatDateTime() and everything
     * else through formatParamValue(). Centralising this dispatch keeps
     * parseQueries and buildCursorWhere consistent across libraries.
     *
     * @param string $chType ClickHouse parameter type as returned by getParamType()
     * @throws Exception
     */
    private function formatTypedValue(string $chType, mixed $value): string
    {
        if ($chType === 'DateTime64(3)') {
            if ($value === null) {
                throw new Exception('DateTime parameter value cannot be null');
            }
            /** @var \DateTime|string $value */
            return $this->formatDateTime($value);
        }

        return $this->formatParamValue($value);
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

        $defaultDirection = 'DESC';
        if ($orderAttributes !== []) {
            $last = $orderAttributes[\count($orderAttributes) - 1];
            $defaultDirection = $last['direction'];
        }

        $orderAttributes[] = ['attribute' => 'id', 'direction' => $defaultDirection];

        return $orderAttributes;
    }

    /**
     * Build keyset-pagination WHERE fragments for cursor support.
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
     * @param array<string, mixed> $params Existing params (mutated by adding cursor binds)
     * @return array{clause: string, params: array<string, mixed>}
     * @throws Exception
     */
    private function buildCursorWhere(array $orderAttributes, array $cursor, string $cursorDirection, array $params): array
    {
        $tuples = [];
        foreach ($orderAttributes as $i => $entry) {
            $attr = $entry['attribute'];
            $direction = $entry['direction'];

            if (!\array_key_exists($attr, $cursor)) {
                throw new Exception("Cursor is missing required attribute '{$attr}'");
            }

            if ($cursorDirection === 'before') {
                $direction = $direction === 'DESC' ? 'ASC' : 'DESC';
            }

            $conditions = [];

            for ($j = 0; $j < $i; $j++) {
                $prev = $orderAttributes[$j];
                $prevAttr = $prev['attribute'];
                if (!\array_key_exists($prevAttr, $cursor)) {
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

        return [
            'clause' => '(' . implode(' OR ', $tuples) . ')',
            'params' => $params,
        ];
    }

    /**
     * Create multiple audit log entries in batch.
     *
     * @param array<array<string, mixed>> $logs The logs to insert
     * @throws Exception
     */
    public function createBatch(array $logs): bool
    {
        if ($logs === []) {
            return true;
        }

        $tableName = $this->getTableName();
        $qualifiedTable = $this->database . '.' . $tableName;

        // Get all attribute column names
        $schemaColumns = $this->getColumnNames();

        // Build JSON rows for JSONEachRow format
        $rows = [];

        foreach ($logs as $log) {
            foreach (['userId' => 'actorId', 'userType' => 'actorType', 'userInternalId' => 'actorInternalId'] as $legacy => $current) {
                if (isset($log[$legacy]) && !isset($log[$current])) {
                    $log[$current] = $log[$legacy];
                }
                unset($log[$legacy]);
            }

            /** @var array<string, mixed> $logData */
            $logData = $log['data'] ?? [];

            foreach (['userId' => 'actorId', 'userType' => 'actorType', 'userInternalId' => 'actorInternalId'] as $legacy => $current) {
                if (\array_key_exists($legacy, $logData) && !\array_key_exists($current, $logData)) {
                    $logData[$current] = $logData[$legacy];
                }
                unset($logData[$legacy]);
            }
            $log['data'] = $logData;

            // Separate data for non-schema attributes
            $nonSchemaData = $logData;
            $resourceValue = $log['resource'] ?? null;
            if (!\is_string($resourceValue)) {
                $resourceValue = '';
            }
            $resource = $this->parseResource($resourceValue);
            $processedLog = $log;

            // Extract schema attributes: check main log first, then data array
            foreach ($schemaColumns as $columnName) {
                if ($columnName === 'data') {
                    continue;
                }
                if ($columnName === 'time') {
                    continue;
                }
                // If attribute not in main log, check data array
                if (!isset($processedLog[$columnName]) && isset($logData[$columnName])) {
                    $processedLog[$columnName] = $logData[$columnName];
                    unset($nonSchemaData[$columnName]);
                } elseif (!isset($processedLog[$columnName]) && isset($resource[$columnName])) {
                    // Check parsed resource for resourceType, resourceId, resourceParent
                    $processedLog[$columnName] = $resource[$columnName];
                } elseif (isset($processedLog[$columnName]) && isset($logData[$columnName])) {
                    // If in both, main log takes precedence, remove from data
                    unset($nonSchemaData[$columnName]);
                }
            }

            // Build JSON row - use provided id or generate one
            $logId = $log['id'] ?? uniqid('', true);

            /** @var string|\DateTime|null $providedTime */
            $providedTime = $processedLog['time'] ?? null;
            $formattedTime = $this->formatDateTime($providedTime);

            $row = [
                'id' => $logId,
                'time' => $formattedTime,
            ];

            // Add all other columns
            foreach ($schemaColumns as $columnName) {
                if ($columnName === 'time') {
                    continue; // Already handled
                }

                // Get attribute metadata to determine if required
                $attributeMetadata = $this->getAttribute($columnName);
                $isRequiredAttribute = $attributeMetadata !== null && isset($attributeMetadata['required']) && $attributeMetadata['required'];

                if ($columnName === 'data') {
                    // Data column - encode remaining non-schema data as JSON
                    try {
                        $encodedData = json_encode($nonSchemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw new Exception('Failed to encode data column to JSON: ' . $e->getMessage(), $e->getCode(), $e);
                    }
                    $row['data'] = $encodedData;
                } elseif (isset($processedLog[$columnName])) {
                    $row[$columnName] = $processedLog[$columnName];
                } elseif ($isRequiredAttribute) {
                    throw new \InvalidArgumentException("Required attribute '{$columnName}' is missing in batch log entry");
                }
            }

            if ($this->sharedTables) {
                $row['tenant'] = $log['$tenant'] ?? $this->tenant;
            }

            $rows[] = $row;
        }

        $columns = ['id', 'time'];
        foreach ($schemaColumns as $columnName) {
            if ($columnName === 'time') {
                continue;
            }
            $columns[] = $columnName;
        }
        if ($this->sharedTables) {
            $columns[] = 'tenant';
        }

        // Builder\ClickHouse::bulkInsert() emits the `INSERT INTO ... FORMAT
        // JSONEachRow` envelope and serialises the rows into the request body.
        $statement = $this->newBuilder()
            ->into($qualifiedTable)
            ->bulkInsert(Format::JSONEachRow, $rows, $columns);

        $this->query($statement->query, [], $statement->body);

        return true;
    }

    /**
     * Parse ClickHouse JSON format results into Log objects.
     * JSON format provides structured data with automatic type handling.
     *
     * @param string $result The JSON response from ClickHouse
     * @return array<int, Log>
     * @throws Exception If JSON parsing fails
     */
    private function parseJsonResults(string $result): array
    {
        if (\in_array(trim($result), ['', '0'], true)) {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($result, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse ClickHouse JSON response: ' . json_last_error_msg());
        }

        if (!\is_array($decoded) || !isset($decoded['data']) || !\is_array($decoded['data'])) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $data */
        $data = $decoded['data'];
        $documents = [];

        foreach ($data as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $document = [];

            foreach ($row as $columnName => $value) {
                if ($columnName === 'data') {
                    // Decode JSON data column
                    $document[$columnName] = \is_string($value) ? json_decode($value, true) ?? [] : $value ?? [];
                } elseif ($columnName === 'tenant') {
                    // Parse tenant as integer or null
                    if ($value === null || $value === '') {
                        $document[$columnName] = null;
                    } elseif (is_numeric($value)) {
                        $document[$columnName] = (int) $value;
                    } else {
                        $document[$columnName] = null;
                    }
                } elseif ($columnName === 'time') {
                    // Convert ClickHouse timestamp format back to ISO 8601
                    // ClickHouse JSON: "2025-12-07 23:33:54.493"
                    // ISO 8601:        "2025-12-07T23:33:54.493+00:00"
                    $parsedTime = \is_string($value) ? $value : (\is_scalar($value) ? (string) $value : '');
                    if (!str_contains($parsedTime, 'T') && $parsedTime !== '') {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document[$columnName] = $parsedTime;
                } else {
                    // For other fields, handle null values
                    $document[$columnName] = $value;
                }
            }

            // Add special $id field if present
            if (isset($document['id'])) {
                $document['$id'] = $document['id'];
                unset($document['id']);
            }

            foreach (['actorId' => 'userId', 'actorType' => 'userType', 'actorInternalId' => 'userInternalId'] as $current => $legacy) {
                if (\array_key_exists($current, $document) && !\array_key_exists($legacy, $document)) {
                    $document[$legacy] = $document[$current];
                }
            }

            $documents[] = new Log($document);
        }

        return $documents;
    }

    /**
     * Get the SELECT column list for queries.
     * Dynamically builds the column list from attributes, excluding 'data' column.
     * Escapes all column names to prevent SQL injection.
     */
    private function getSelectColumns(): string
    {
        $columns = [];

        // Add id column first (not part of attributes)
        $columns[] = $this->escapeIdentifier('id');

        // Dynamically add all attribute columns except 'data'
        foreach ($this->getAttributes() as $attribute) {
            $id = $attribute['$id'];
            /** @var string $id */
            if ($id !== 'data') {
                $columns[] = $this->escapeIdentifier($id);
            }
        }

        // Add data column at the end
        $columns[] = $this->escapeIdentifier('data');

        // Add tenant column if shared tables are enabled
        if ($this->sharedTables) {
            $columns[] = $this->escapeIdentifier('tenant');
        }

        return implode(', ', $columns);
    }

    /**
     * Build tenant filter clause based on current tenant context.
     * Escapes column name to prevent SQL injection.
     */
    private function applyTenantFilter(ClickHouseBuilder $builder): void
    {
        if (!$this->sharedTables || $this->tenant === null) {
            return;
        }

        $builder->filter([Query::equal('tenant', [$this->tenant])]);
    }

    /**
     * Get ClickHouse-specific SQL column definition for a given attribute ID.
     *
     * Dynamically determines the ClickHouse type based on attribute metadata.
     * DateTime attributes use DateTime64(3), all others use String.
     *
     * @param string $id Attribute identifier
     * @return string ClickHouse column definition with appropriate types and nullability
     * @throws Exception
     */
    protected function getColumnDefinition(string $id): string
    {
        $attribute = $this->getAttribute($id);

        if (!$attribute) {
            throw new Exception("Attribute {$id} not found");
        }

        // Dynamically determine type based on attribute metadata
        // DateTime attributes use DateTime64(3), all others use String
        $type = (isset($attribute['type']) && $attribute['type'] === Database::VAR_DATETIME)
            ? 'DateTime64(3)'
            : 'String';

        $required = (bool) $attribute['required'];

        if ($type === 'String' && \in_array($id, self::LOW_CARDINALITY_COLUMNS, true)) {
            $columnType = $required
                ? 'LowCardinality(String)'
                : 'LowCardinality(Nullable(String))';

            return "{$id} {$columnType}";
        }

        $columnType = $required ? $type : 'Nullable(' . $type . ')';

        return "{$id} {$columnType}";
    }

    /**
     * Get logs by user ID.
     *
     * @throws Exception
     */
    public function getByUser(
        string $userId,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        $queries = [
            Query::equal('actorId', $userId),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        $queries[] = $ascending ? Query::orderAsc('time') : Query::orderDesc('time');
        $queries[] = Query::limit($limit);
        $queries[] = Query::offset($offset);

        return $this->find($queries);
    }

    /**
     * Count logs by user ID.
     *
     * @throws Exception
     */
    public function countByUser(
        string $userId,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        $queries = [
            Query::equal('actorId', $userId),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        return $this->count($queries, $max);
    }

    /**
     * Get logs by resource.
     *
     * @throws Exception
     */
    public function getByResource(
        string $resource,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        $queries = [
            Query::equal('resource', $resource),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        $queries[] = $ascending ? Query::orderAsc('time') : Query::orderDesc('time');
        $queries[] = Query::limit($limit);
        $queries[] = Query::offset($offset);

        return $this->find($queries);
    }

    /**
     * Count logs by resource.
     *
     * @throws Exception
     */
    public function countByResource(
        string $resource,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        $queries = [
            Query::equal('resource', $resource),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        return $this->count($queries, $max);
    }

    /**
     * Get logs by user and events.
     *
     * @throws Exception
     */
    public function getByUserAndEvents(
        string $userId,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        $queries = [
            Query::equal('actorId', $userId),
            Query::equal('event', $events),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        $queries[] = $ascending ? Query::orderAsc('time') : Query::orderDesc('time');
        $queries[] = Query::limit($limit);
        $queries[] = Query::offset($offset);

        return $this->find($queries);
    }

    /**
     * Count logs by user and events.
     *
     * @throws Exception
     */
    public function countByUserAndEvents(
        string $userId,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        $queries = [
            Query::equal('actorId', $userId),
            Query::equal('event', $events),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        return $this->count($queries, $max);
    }

    /**
     * Get logs by resource and events.
     *
     * @throws Exception
     */
    public function getByResourceAndEvents(
        string $resource,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        $queries = [
            Query::equal('resource', $resource),
            Query::equal('event', $events),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        $queries[] = $ascending ? Query::orderAsc('time') : Query::orderDesc('time');
        $queries[] = Query::limit($limit);
        $queries[] = Query::offset($offset);

        return $this->find($queries);
    }

    /**
     * Count logs by resource and events.
     *
     * @throws Exception
     */
    public function countByResourceAndEvents(
        string $resource,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        $queries = [
            Query::equal('resource', $resource),
            Query::equal('event', $events),
        ];

        if ($after instanceof \DateTime && $before instanceof \DateTime) {
            $queries[] = Query::between('time', $after, $before);
        } elseif ($after instanceof \DateTime) {
            $queries[] = Query::greaterThan('time', $after);
        } elseif ($before instanceof \DateTime) {
            $queries[] = Query::lessThan('time', $before);
        }

        return $this->count($queries, $max);
    }

    /**
     * Delete logs older than the specified datetime.
     *
     * @throws Exception
     */
    public function cleanup(\DateTime $datetime): bool
    {
        $tableName = $this->getTableName();
        $qualifiedTable = $this->database . '.' . $tableName;
        $escapedTimeColumn = $this->escapeIdentifier('time');
        $datetimeString = $datetime->format('Y-m-d H:i:s.v');

        $builder = $this->newBuilder()
            ->into($qualifiedTable)
            ->whereRaw($escapedTimeColumn . ' < {datetime:DateTime64(3)}');

        $this->applyTenantFilter($builder);

        if ($this->asyncCleanup) {
            $builder->settings(['lightweight_deletes_sync' => '0']);
        }

        $sql = $builder->delete()->query;

        $this->query($sql, ['datetime' => $datetimeString]);

        return true;
    }
}
