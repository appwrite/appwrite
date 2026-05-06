<?php

namespace Appwrite\Platform\Modules\Analytics\Storage;

use Exception;
use Utopia\Fetch\Client;
use Utopia\Query\Method;
use Utopia\Query\Query;

class ClickHouse
{
    protected string $namespace = Schema::DEFAULT_NAMESPACE;

    protected bool $sharedTables = false;

    protected ?string $tenant = null;

    private Client $client;
    private string $scheme;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        string $user,
        string $pass,
        private readonly string $database,
        bool $secure = false,
    ) {
        $this->scheme = $secure ? 'https' : 'http';

        $this->client = new Client();
        $this->client->addHeader('X-ClickHouse-User', $user);
        $this->client->addHeader('X-ClickHouse-Key', $pass);
        $this->client->setTimeout(30_000);
    }

    /**
     * Set the namespace prefix used for table names.
     */
    public function setNamespace(string $namespace): self
    {
        if ($namespace !== '' && !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $namespace)) {
            throw new \InvalidArgumentException('Invalid analytics namespace: ' . $namespace);
        }

        $this->namespace = $namespace === '' ? Schema::DEFAULT_NAMESPACE : $namespace;
        return $this;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Toggle shared-tables mode. When enabled, tables include a `tenant`
     * column and reads/writes scope by the configured tenant.
     */
    public function setSharedTables(bool $shared): self
    {
        $this->sharedTables = $shared;
        return $this;
    }

    public function isSharedTables(): bool
    {
        return $this->sharedTables;
    }

    /**
     * Set the active tenant identifier. Required for shared-tables reads and
     * writes; ignored otherwise.
     */
    public function setTenant(?string $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getTenant(): ?string
    {
        return $this->tenant;
    }

    /**
     * Bootstrap the analytics database and tables. Idempotent.
     *
     * @throws Exception
     */
    public function setup(): void
    {
        // Create database first (uses HTTP query without database header)
        $this->execute('CREATE DATABASE IF NOT EXISTS ' . $this->quoteIdentifier($this->database), useDatabase: false);

        // Create events table
        $this->execute(Schema::eventsTableSql($this->namespace, $this->sharedTables));
    }

    /**
     * Insert a single analytics event.
     *
     * @param  array<string, mixed>  $event
     * @throws Exception
     */
    public function insertEvent(array $event): void
    {
        if ($this->sharedTables) {
            if ($this->tenant === null) {
                throw new Exception('Analytics tenant must be set when sharedTables is enabled.');
            }
            $event['tenant'] = $this->tenant;
        } else {
            unset($event['tenant']);
        }

        $payload = \json_encode($event, JSON_THROW_ON_ERROR);

        $url = $this->buildUrl([
            'query' => 'INSERT INTO ' . $this->quoteIdentifier($this->eventsTable()) . ' FORMAT JSONEachRow',
        ]);

        $this->client->addHeader('X-ClickHouse-Database', $this->database);
        $this->client->addHeader('Content-Type', 'application/x-ndjson');

        try {
            $response = $this->client->fetch(
                url: $url,
                method: Client::METHOD_POST,
                body: $payload,
            );
        } finally {
            $this->client->removeHeader('Content-Type');
        }

        if ($response->getStatusCode() !== 200) {
            throw new Exception('ClickHouse insert failed (HTTP ' . $response->getStatusCode() . '): ' . $response->getBody());
        }
    }

    /**
     * Aggregate visitors and pageviews for the given app, scoped to the
     * configured tenant when shared tables are enabled.
     *
     * @param  Query[]  $queries
     * @return array{visitors: int, pageviews: int}
     * @throws Exception
     */
    public function aggregate(string $appId, array $queries): array
    {
        $where = ['app_id = {appId:String}'];
        $params = [
            'param_appId' => $appId,
        ];

        if ($this->sharedTables) {
            if ($this->tenant === null) {
                throw new Exception('Analytics tenant must be set when sharedTables is enabled.');
            }

            $where[] = 'tenant = {tenant:String}';
            $params['param_tenant'] = $this->tenant;
        }

        foreach ($queries as $i => $query) {
            $clause = $this->compileQuery($query, $i, $params);
            if ($clause !== null) {
                $where[] = $clause;
            }
        }

        $whereSql = \implode(' AND ', $where);
        $sql = 'SELECT '
            . 'uniqExact(visitor_id) AS visitors, '
            . 'countIf(name = \'pageview\') AS pageviews '
            . 'FROM ' . $this->quoteIdentifier($this->eventsTable()) . ' '
            . 'WHERE ' . $whereSql
            . ' FORMAT JSON';

        $result = $this->executeQuery($sql, $params);

        $row = $result['data'][0] ?? ['visitors' => 0, 'pageviews' => 0];

        return [
            'visitors' => (int) ($row['visitors'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
        ];
    }

    /**
     * Get the fully qualified events table name for the configured namespace.
     */
    private function eventsTable(): string
    {
        return Schema::eventsTable($this->namespace);
    }

    /**
     * Compile a Query object to a parameterized WHERE clause fragment.
     *
     * @param  array<string, mixed>  $params
     */
    private function compileQuery(Query $query, int $index, array &$params): ?string
    {
        $attribute = $this->sanitizeIdentifier($query->getAttribute());
        $values = $query->getValues();

        return match ($query->getMethod()) {
            Method::Equal => $this->compileIn($attribute, $values, $index, $params, negate: false),
            Method::NotEqual => $this->compileIn($attribute, $values, $index, $params, negate: true),
            Method::Between => $this->compileBetween($attribute, $values, $index, $params),
            Method::GreaterThanEqual,
            Method::GreaterThan,
            Method::LessThan,
            Method::LessThanEqual => $this->compileComparison($query->getMethod(), $attribute, $values, $index, $params),
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $params
     */
    private function compileIn(string $attribute, array $values, int $index, array &$params, bool $negate): ?string
    {
        if (empty($values)) {
            return null;
        }

        $placeholders = [];
        foreach ($values as $j => $value) {
            $key = "f{$index}_{$j}";
            $params['param_' . $key] = (string) $value;
            $placeholders[] = '{' . $key . ':String}';
        }

        $operator = $negate ? 'NOT IN' : 'IN';
        return $this->quoteIdentifier($attribute) . ' ' . $operator . ' (' . \implode(', ', $placeholders) . ')';
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $params
     */
    private function compileBetween(string $attribute, array $values, int $index, array &$params): ?string
    {
        if (\count($values) < 2) {
            return null;
        }

        $params["param_b{$index}_lo"] = (string) $values[0];
        $params["param_b{$index}_hi"] = (string) $values[1];

        return $this->quoteIdentifier($attribute)
            . ' BETWEEN {b' . $index . '_lo:DateTime}'
            . ' AND {b' . $index . '_hi:DateTime}';
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $params
     */
    private function compileComparison(Method $method, string $attribute, array $values, int $index, array &$params): ?string
    {
        if (empty($values)) {
            return null;
        }

        $operator = match ($method) {
            Method::GreaterThan => '>',
            Method::GreaterThanEqual => '>=',
            Method::LessThan => '<',
            Method::LessThanEqual => '<=',
            default => null,
        };

        if ($operator === null) {
            return null;
        }

        $key = "c{$index}";
        $params['param_' . $key] = (string) $values[0];

        return $this->quoteIdentifier($attribute) . ' ' . $operator . ' {' . $key . ':String}';
    }

    /**
     * @param  array<string, mixed>  $params
     * @throws Exception
     * @return array{data: array<int, array<string, mixed>>, rows?: int}
     */
    private function executeQuery(string $sql, array $params): array
    {
        $url = $this->buildUrl(['query' => $sql] + $params);
        $this->client->addHeader('X-ClickHouse-Database', $this->database);

        $response = $this->client->fetch(url: $url, method: Client::METHOD_GET);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('ClickHouse query failed (HTTP ' . $response->getStatusCode() . '): ' . $response->getBody());
        }

        $decoded = \json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        // ClickHouse JSON format wraps results in {data: [...], rows: N}
        return \is_array($decoded) ? $decoded : ['data' => []];
    }

    /**
     * @throws Exception
     */
    private function execute(string $sql, bool $useDatabase = true): void
    {
        $url = $this->buildUrl(['query' => $sql]);

        if ($useDatabase) {
            $this->client->addHeader('X-ClickHouse-Database', $this->database);
        } else {
            $this->client->removeHeader('X-ClickHouse-Database');
        }

        $response = $this->client->fetch(url: $url, method: Client::METHOD_POST);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('ClickHouse statement failed (HTTP ' . $response->getStatusCode() . '): ' . $response->getBody());
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function buildUrl(array $params): string
    {
        return $this->scheme . '://' . $this->host . ':' . $this->port . '/?' . \http_build_query($params);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $sanitized = $this->sanitizeIdentifier($identifier);
        return '`' . $sanitized . '`';
    }

    private function sanitizeIdentifier(string $identifier): string
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $identifier);
        }
        return $identifier;
    }
}
