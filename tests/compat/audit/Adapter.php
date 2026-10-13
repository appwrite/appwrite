<?php

namespace Tests\Compat\Audit;

use PDO;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Audit\Adapter as AuditAdapter;
use Utopia\Audit\Adapter\ClickHouse;
use Utopia\Audit\Adapter\Database as DatabaseAdapter;
use Utopia\Audit\Adapter\SQL;
use Utopia\Audit\Audit;
use Utopia\Audit\Log;
use Utopia\Audit\Query;
use Utopia\Audit\Tests\Adapter\Client as FakeClient;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Psr7\Method as HttpMethod;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

require_once __DIR__ . '/../../../packages/audit/tests/Adapter/Client.php';

/**
 * Answers every request with 200 and the same body, for the benchmarks:
 * `StubClient` in crates/tools/compat/src/libs/audit.rs.
 */
final readonly class StubClient implements ClientInterface
{
    public function __construct(private string $body)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, body: new Stream($this->body));
    }
}

/**
 * Maps tests/compat/audit/spec.json operations onto the PHP library. Glue only: no logic.
 *
 * Values: `{"$now": <seconds>, "format"?: "db"|"tz"}` in a log is the time
 * now plus the seconds (`DateTime::format()` or `DateTime::formatTz()`), and
 * `{"$log": {...}}` in a query's values is a `Log`. A date argument
 * (`after`, `before`, `datetime`) is a string for `new \DateTime()`.
 */
final class Adapter implements Base, Benchmarks
{
    public function operations(): array
    {
        $ops = [
            'clickhouse.new' => fn (array $a, Session $s) => $s->handle(self::clickhouse($a, $s)),
            'database.new' => fn (array $a, Session $s) => $s->handle(self::database($a, $s)),
            'clickhouse.call' => fn (array $a, Session $s) => self::call($a),

            'fake.respond' => function (array $a, Session $s) {
                self::fake($a, $s)->respond($a['status'], $a['body'] ?? '');

                return;
            },
            'fake.fail' => function (array $a, Session $s) {
                self::fake($a, $s)->fail(new ConnectionException(new RequestFactory()->createRequest(HttpMethod::POST, 'http://clickhouse:8124/'), $a['message']));

                return;
            },
            'clock.sleep' => function (array $a, Session $s) {
                usleep($a['ms'] * 1000);

                return;
            },
            'fake.requests' => fn (array $a, Session $s) => array_map(self::request(...), self::fake($a, $s)->requests),

            'audit.new' => fn (array $a, Session $s) => $s->handle(new Audit(self::adapter($a, $s))),
            'audit.get_adapter' => fn (array $a, Session $s) => self::audit($a, $s)->getAdapter()->getName(),
            'audit.setup' => function (array $a, Session $s) {
                self::audit($a, $s)->setup();

                return;
            },
            'audit.log' => fn (array $a, Session $s) => self::log(self::audit($a, $s)->log($a['user_id'] ?? null, $a['event'], $a['resource'], $a['user_agent'], $a['ip'], self::values($a['data'] ?? []))),
            'audit.log_batch' => fn (array $a, Session $s) => self::audit($a, $s)->logBatch(self::values($a['events'])),
            'audit.get_log_by_id' => fn (array $a, Session $s) => self::maybe(self::audit($a, $s)->getLogById($a['id'])),
            'audit.get_logs_by_user' => fn (array $a, Session $s) => self::logs(self::audit($a, $s)->getLogsByUser($a['user_id'], ...self::page($a))),
            'audit.count_logs_by_user' => fn (array $a, Session $s) => self::audit($a, $s)->countLogsByUser($a['user_id'], ...self::range($a)),
            'audit.get_logs_by_resource' => fn (array $a, Session $s) => self::logs(self::audit($a, $s)->getLogsByResource($a['resource'], ...self::page($a))),
            'audit.count_logs_by_resource' => fn (array $a, Session $s) => self::audit($a, $s)->countLogsByResource($a['resource'], ...self::range($a)),
            'audit.get_logs_by_user_and_events' => fn (array $a, Session $s) => self::logs(self::audit($a, $s)->getLogsByUserAndEvents($a['user_id'], $a['events'], ...self::page($a))),
            'audit.count_logs_by_user_and_events' => fn (array $a, Session $s) => self::audit($a, $s)->countLogsByUserAndEvents($a['user_id'], $a['events'], ...self::range($a)),
            'audit.get_logs_by_resource_and_events' => fn (array $a, Session $s) => self::logs(self::audit($a, $s)->getLogsByResourceAndEvents($a['resource'], $a['events'], ...self::page($a))),
            'audit.count_logs_by_resource_and_events' => fn (array $a, Session $s) => self::audit($a, $s)->countLogsByResourceAndEvents($a['resource'], $a['events'], ...self::range($a)),
            'audit.cleanup' => fn (array $a, Session $s) => self::audit($a, $s)->cleanup(new \DateTime($a['datetime'])),
            'audit.find' => fn (array $a, Session $s) => self::logs(self::audit($a, $s)->find(self::queries($a['queries'] ?? []))),
            'audit.count' => fn (array $a, Session $s) => self::audit($a, $s)->count(self::queries($a['queries'] ?? []), $a['max'] ?? null),
            'audit.ping' => fn (array $a, Session $s) => self::audit($a, $s)->ping(),

            'log.read' => fn (array $a, Session $s) => self::read(new Log(self::values($a['attributes']))),
            'log.attribute' => fn (array $a, Session $s) => new Log(self::values($a['attributes']))->getAttribute($a['key'], $a['default'] ?? null),
            'log.set' => fn (array $a, Session $s) => new Log(self::values($a['attributes']))->setAttribute($a['key'], self::values($a['value']))->getArrayCopy(),
            'log.remove' => fn (array $a, Session $s) => new Log(self::values($a['attributes']))->removeAttribute($a['key'])->getArrayCopy(),
            'log.is_set' => fn (array $a, Session $s) => new Log(self::values($a['attributes']))->isSet($a['key']),

            'query.new' => fn (array $a, Session $s) => self::query(new Query($a['method'], $a['attribute'] ?? '', self::values($a['values'] ?? []))),
            'query.equal' => fn (array $a, Session $s) => self::query(Query::equal($a['attribute'], self::values($a['value']))),
            'query.less_than' => fn (array $a, Session $s) => self::query(Query::lessThan($a['attribute'], self::values($a['value']))),
            'query.greater_than' => fn (array $a, Session $s) => self::query(Query::greaterThan($a['attribute'], self::values($a['value']))),
            'query.between' => fn (array $a, Session $s) => self::query(Query::between($a['attribute'], self::values($a['start']), self::values($a['end']))),
            'query.parse' => fn (array $a, Session $s) => self::query(Query::parse($a['query'])),
            'query.parse_queries' => fn (array $a, Session $s) => array_map(self::query(...), Query::parseQueries($a['queries'])),
            'query.to_string' => fn (array $a, Session $s) => self::queries([$a['query']])[0]->toString(),
            'query.get_value' => fn (array $a, Session $s) => self::queries([$a['query']])[0]->getValue($a['default'] ?? null),
        ];
        foreach (self::METHODS as $op => $method) {
            $ops['adapter.' . $op] = fn (array $a, Session $s) => self::invoke(self::adapter($a, $s), $method, $a);
        }

        return $ops;
    }

    /** Adapter operations (`adapter.<op>`) and the methods they call. */
    private const array METHODS = [
        'get_name' => 'getName',
        'setup' => 'setup',
        'ping' => 'ping',
        'create' => 'create',
        'create_batch' => 'createBatch',
        'get_by_id' => 'getById',
        'get_by_user' => 'getByUser',
        'count_by_user' => 'countByUser',
        'get_by_resource' => 'getByResource',
        'count_by_resource' => 'countByResource',
        'get_by_user_and_events' => 'getByUserAndEvents',
        'count_by_user_and_events' => 'countByUserAndEvents',
        'get_by_resource_and_events' => 'getByResourceAndEvents',
        'count_by_resource_and_events' => 'countByResourceAndEvents',
        'cleanup' => 'cleanup',
        'find' => 'find',
        'count' => 'count',
        'get_attributes' => 'getAttributes',
        'get_attribute_documents' => 'getAttributeDocuments',
        'get_indexes' => 'getIndexes',
        'get_index_documents' => 'getIndexDocuments',
        'get_collection_name' => 'getCollectionName',
        'get_attribute' => 'getAttribute',
        'get_column_definition' => 'getColumnDefinition',
        'get_all_column_definitions' => 'getAllColumnDefinitions',
        'parse_resource' => 'parseResource',
        'set_namespace' => 'setNamespace',
        'get_namespace' => 'getNamespace',
        'set_database' => 'setDatabase',
        'set_table' => 'setTable',
        'get_table' => 'getTable',
        'set_secure' => 'setSecure',
        'set_tenant' => 'setTenant',
        'get_tenant' => 'getTenant',
        'set_shared_tables' => 'setSharedTables',
        'is_shared_tables' => 'isSharedTables',
        'set_async_cleanup' => 'setAsyncCleanup',
        'is_async_cleanup' => 'isAsyncCleanup',
        'set_retention' => 'setRetention',
        'get_retention' => 'getRetention',
    ];

    /**
     * `$adapter->{$method}(...)` with the operation's named arguments, the
     * protected `SQL` helpers through reflection; fluent setters report `true`.
     *
     * @param array<string, mixed> $a
     */
    private static function invoke(AuditAdapter $adapter, string $method, array $a): mixed
    {
        $args = match ($method) {
            'create' => [self::values($a['log'])],
            'createBatch' => [self::values($a['logs'])],
            'getById' => [$a['id']],
            'getByUser' => [$a['user_id'], ...self::page($a)],
            'countByUser' => [$a['user_id'], ...self::range($a)],
            'getByResource' => [$a['resource'], ...self::page($a)],
            'countByResource' => [$a['resource'], ...self::range($a)],
            'getByUserAndEvents' => [$a['user_id'], $a['events'], ...self::page($a)],
            'countByUserAndEvents' => [$a['user_id'], $a['events'], ...self::range($a)],
            'getByResourceAndEvents' => [$a['resource'], $a['events'], ...self::page($a)],
            'countByResourceAndEvents' => [$a['resource'], $a['events'], ...self::range($a)],
            'cleanup' => [new \DateTime($a['datetime'])],
            'find' => [self::queries($a['queries'] ?? [])],
            'count' => [self::queries($a['queries'] ?? []), $a['max'] ?? null],
            'getAttribute', 'getColumnDefinition' => [$a['id']],
            'parseResource' => [$a['resource']],
            'setNamespace', 'setDatabase', 'setTable' => [$a['value']],
            'setSecure', 'setSharedTables', 'setAsyncCleanup' => [$a['value']],
            'setTenant', 'setRetention' => [$a['value'] ?? null],
            default => [],
        };
        $call = new \ReflectionMethod($adapter, $method);
        $result = $call->invokeArgs($adapter, $args);

        return match (true) {
            $result instanceof Log => self::log($result),
            $result === null && $method === 'getById' => null,
            $result instanceof AuditAdapter => true,
            \is_array($result) && \in_array($method, ['getAttributeDocuments', 'getIndexDocuments'], true) => array_map(static fn (Document $d): array => $d->getArrayCopy(), $result),
            \is_array($result) && $result !== [] && array_is_list($result) && $result[0] instanceof Log => self::logs($result),
            default => $result,
        };
    }

    /**
     * The ClickHouse adapter: on service `audit-clickhouse` (database
     * `${ns}`) unless `host` is given, through the test suite's recording
     * client with `fake`.
     *
     * @param array<string, mixed> $a
     */
    private static function clickhouse(array $a, Session $s): ClickHouse
    {
        $service = isset($a['host']) ? [] : parse_url($s->service('audit-clickhouse'));
        $client = ($a['fake'] ?? false) ? new FakeClient() : null;
        $adapter = new ClickHouse(
            $a['host'] ?? $service['host'],
            $a['username'] ?? urldecode($service['user'] ?? 'default'),
            $a['password'] ?? urldecode($service['pass'] ?? ''),
            $a['port'] ?? $service['port'] ?? 8123,
            $a['secure'] ?? false,
            $client,
        );
        if (!isset($a['host'])) {
            self::createDatabase($service, $s->ns);
            $adapter->setDatabase($s->ns);
        }

        return $adapter;
    }

    /**
     * `CREATE DATABASE IF NOT EXISTS` the case's database on the service,
     * outside the library: the adapter names its database in every request
     * (`X-ClickHouse-Database`), so it cannot create its own.
     *
     * @param array<string, mixed> $service
     */
    private static function createDatabase(array $service, string $ns): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'X-ClickHouse-User: ' . urldecode($service['user'] ?? 'default') . "\r\nX-ClickHouse-Key: " . urldecode($service['pass'] ?? '') . "\r\n",
            'content' => "CREATE DATABASE IF NOT EXISTS `{$ns}`",
            'ignore_errors' => true,
        ]]);
        $out = file_get_contents("http://{$service['host']}:{$service['port']}/", false, $context);
        $status = http_get_last_response_headers()[0] ?? '';
        if ($out === false || !str_contains($status, ' 200 ')) {
            throw new Fault('clickhouse.new: ' . $out);
        }
    }

    /**
     * The Database adapter over a `Utopia\Database\Database` (no cache) on
     * MariaDB (`database-mariadb`), SQLite (a file under
     * `/tmp/compat-fs/${ns}/`) or Memory; the database created unless it
     * exists, as `DatabaseTest` does.
     *
     * @param array<string, mixed> $a
     */
    private static function database(array $a, Session $s): DatabaseAdapter
    {
        $engine = $a['engine'] ?? 'mariadb';
        $adapter = match ($engine) {
            'mariadb' => (function () use ($s): MariaDB {
                $url = parse_url($s->service('database-mariadb'));
                $dsn = "mysql:host={$url['host']};port={$url['port']};charset=utf8mb4";

                return new MariaDB(new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), MariaDB::getPDOAttributes()));
            })(),
            'sqlite' => (function () use ($s): SQLite {
                $dir = '/tmp/compat-fs/' . $s->ns;
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }

                return new SQLite(new PDO('sqlite:' . $dir . '/audit.sqlite', null, null, SQLite::getPDOAttributes()));
            })(),
            'memory' => new Memory(),
            default => throw new Fault("unknown engine {$engine}"),
        };
        $db = new Database($adapter, new Cache(new NoCache()));
        $db->setDatabase($a['database'] ?? $s->ns);
        $db->setNamespace($a['namespace'] ?? 'namespace');
        if (($a['create'] ?? true) && !$db->exists($db->getDatabase())) {
            $db->create();
        }

        return new DatabaseAdapter($db);
    }

    /**
     * One call on a fresh ClickHouse adapter over the recording client, with
     * `config` applied and `responses` queued: its result or error, and the
     * requests it sent.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function call(array $a): array
    {
        $client = new FakeClient();
        foreach ($a['responses'] ?? [] as $response) {
            if (isset($response['fail'])) {
                $client->fail(new ConnectionException(new RequestFactory()->createRequest(HttpMethod::POST, 'http://clickhouse:8124/'), $response['fail']));
            } else {
                $client->respond($response['status'] ?? 200, $response['body'] ?? '');
            }
        }
        $out = ['result' => null, 'error' => null];
        try {
            $adapter = new ClickHouse('clickhouse', 'user', 'secret', 8124, client: $client);
            foreach ($a['config'] ?? [] as $setting => $value) {
                self::invoke($adapter, self::METHODS[$setting], ['value' => $value]);
            }
            $out['result'] = self::invoke($adapter, self::METHODS[$a['method']], $a['args'] ?? []);
        } catch (\Throwable $e) {
            $out['error'] = ['class' => $e::class, 'message' => $e->getMessage()];
        }
        $out['requests'] = array_map(self::request(...), $client->requests);

        return $out;
    }

    /**
     * A recorded request: method, URI, headers (without `Content-Type`),
     * the content type without its boundary, and the multipart form fields
     * (as the test suite's `fields()` reads them) or the raw body.
     *
     * @return array<string, mixed>
     */
    private static function request(RequestInterface $request): array
    {
        $headers = $request->getHeaders();
        unset($headers['Content-Type']);
        $type = $request->getHeaderLine('Content-Type');
        $body = (string) $request->getBody();
        $out = ['method' => $request->getMethod(), 'uri' => (string) $request->getUri(), 'headers' => $headers];
        if (str_starts_with($type, 'multipart/form-data; boundary=')) {
            $boundary = substr($type, \strlen('multipart/form-data; boundary='));
            $fields = [];
            foreach (explode('--' . $boundary, $body) as $part) {
                $sections = explode("\r\n\r\n", $part, 2);
                if (\count($sections) !== 2) {
                    continue;
                }
                $name = explode('"', explode('name="', $sections[0], 2)[1] ?? '', 2)[0];
                $fields[$name] = substr($sections[1], 0, -2);
            }
            $out['content_type'] = 'multipart/form-data';
            $out['fields'] = $fields;
        } else {
            $out['content_type'] = $type;
            $out['body'] = $body;
        }

        return $out;
    }

    /**
     * Every getter of a log.
     *
     * @return array<string, mixed>
     */
    private static function read(Log $log): array
    {
        return [
            'id' => $log->getId(),
            'userId' => $log->getUserId(),
            'actorId' => $log->getActorId(),
            'actorType' => $log->getActorType(),
            'actorInternalId' => $log->getActorInternalId(),
            'event' => $log->getEvent(),
            'resource' => $log->getResource(),
            'sdk' => $log->getSdk(),
            'sdkVersion' => $log->getSdkVersion(),
            'osCode' => $log->getOsCode(),
            'osName' => $log->getOsName(),
            'osVersion' => $log->getOsVersion(),
            'clientType' => $log->getClientType(),
            'clientCode' => $log->getClientCode(),
            'clientName' => $log->getClientName(),
            'clientVersion' => $log->getClientVersion(),
            'clientEngine' => $log->getClientEngine(),
            'clientEngineVersion' => $log->getClientEngineVersion(),
            'deviceName' => $log->getDeviceName(),
            'deviceBrand' => $log->getDeviceBrand(),
            'deviceModel' => $log->getDeviceModel(),
            'userAgent' => $log->getUserAgent(),
            'ip' => $log->getIp(),
            'time' => $log->getTime(),
            'data' => $log->getData(),
            'tenant' => $log->getTenant(),
            'array' => $log->getArrayCopy(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function log(Log $log): array
    {
        return $log->getArrayCopy();
    }

    /**
     * @param array<Log> $logs
     * @return list<array<string, mixed>>
     */
    private static function logs(array $logs): array
    {
        return array_values(array_map(self::log(...), $logs));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function maybe(?Log $log): ?array
    {
        return $log === null ? null : self::log($log);
    }

    /**
     * @return array{method: string, attribute: string, values: array<mixed>}
     */
    private static function query(\Utopia\Query\Query $query): array
    {
        $values = [];
        foreach ($query->getValues() as $value) {
            $values[] = $value instanceof Log ? ['$log' => $value->getArrayCopy()] : $value;
        }

        return ['method' => $query->getMethod()->value, 'attribute' => $query->getAttribute(), 'values' => $values];
    }

    /**
     * Queries from `{method, attribute?, values?}` objects.
     *
     * @param array<mixed> $queries
     * @return list<Query>
     */
    private static function queries(array $queries): array
    {
        $out = [];
        foreach ($queries as $query) {
            $out[] = new Query($query['method'], $query['attribute'] ?? '', self::values($query['values'] ?? []));
        }

        return $out;
    }

    /**
     * `$now` and `$log` tags decoded, recursively.
     */
    private static function values(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (isset($value['$now']) && \is_int($value['$now']) && \count($value) <= 2) {
            $date = DatabaseDateTime::addSeconds(new \DateTime(), $value['$now']);

            return ($value['format'] ?? 'db') === 'tz' ? DatabaseDateTime::formatTz($date) : $date;
        }
        if (\array_key_exists('$log', $value) && \count($value) === 1) {
            return new Log(self::values($value['$log']));
        }

        return array_map(self::values(...), $value);
    }

    /**
     * `after`, `before` (dates) and `max`.
     *
     * @param array<string, mixed> $a
     * @return array{after: \DateTime|null, before: \DateTime|null, max: int|null}
     */
    private static function range(array $a): array
    {
        return [
            'after' => isset($a['after']) ? new \DateTime($a['after']) : null,
            'before' => isset($a['before']) ? new \DateTime($a['before']) : null,
            'max' => $a['max'] ?? null,
        ];
    }

    /**
     * `after`, `before` (dates), `limit`, `offset` and `ascending`.
     *
     * @param array<string, mixed> $a
     * @return array{after: \DateTime|null, before: \DateTime|null, limit: int, offset: int, ascending: bool}
     */
    private static function page(array $a): array
    {
        return [
            'after' => isset($a['after']) ? new \DateTime($a['after']) : null,
            'before' => isset($a['before']) ? new \DateTime($a['before']) : null,
            'limit' => $a['limit'] ?? 25,
            'offset' => $a['offset'] ?? 0,
            'ascending' => $a['ascending'] ?? false,
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function adapter(array $a, Session $s): AuditAdapter
    {
        $adapter = $s->get($a['adapter']);

        return $adapter instanceof AuditAdapter ? $adapter : throw new Fault('not an audit adapter');
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function audit(array $a, Session $s): Audit
    {
        $audit = $s->get($a['audit']);

        return $audit instanceof Audit ? $audit : throw new Fault('not an audit');
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function fake(array $a, Session $s): FakeClient
    {
        $adapter = self::adapter($a, $s);
        $client = (new \ReflectionProperty(ClickHouse::class, 'client'))->getValue($adapter);

        return $client instanceof FakeClient ? $client : throw new Fault('not a ClickHouse adapter over the recording client');
    }

    public function benchmarks(): array
    {
        return [
            'clickhouse_find' => ['Find a page of logs on ClickHouse: filters and an order compiled to typed parameters, the multipart request, and 10 JSON rows parsed into logs (a stub transport answers)', function (Session $s) {
                $adapter = new ClickHouse('clickhouse', 'user', 'secret', 8123, client: new StubClient(self::rows()));
                $queries = [
                    Query::equal('actorId', 'user1'),
                    Query::greaterThan('time', '2025-06-15 00:00:00.000'),
                    Query::equal('event', ['documents.update', 'documents.create']),
                    Query::orderDesc('time'),
                    Query::limit(10),
                ];

                return fn () => $adapter->find($queries);
            }],
            'clickhouse_create_batch' => ['Insert 10 logs on ClickHouse: rows built from the logs (legacy names, data, parsed resources) and the JSONEachRow request (a stub transport answers)', function (Session $s) {
                $adapter = new ClickHouse('clickhouse', 'user', 'secret', 8123, client: new StubClient(''));
                $logs = self::batch();

                return fn () => $adapter->createBatch($logs);
            }],
            'database_get_by_user' => ['Read a page of 25 logs of a user through the Database adapter, on the in-memory database', function (Session $s) {
                $adapter = self::database(['engine' => 'memory'], $s);
                $adapter->setup();
                $logs = [];
                for ($i = 0; $i < 50; $i++) {
                    $logs[] = ['userId' => 'ada', 'event' => 'documents.update', 'resource' => "database/db1/collection/c1/document/{$i}", 'userAgent' => 'Mozilla/5.0', 'ip' => '203.0.113.7', 'time' => \sprintf('2025-06-15 12:%02d:00.000', $i), 'data' => ['key' => 'value']];
                }
                $adapter->createBatch($logs);

                return fn () => $adapter->getByUser('ada');
            }],
            'log_getters' => ['Read the fields of a log through its getters', function (Session $s) {
                $attributes = json_decode(self::rows(), true)['data'][0];

                return function () use ($attributes): int {
                    $log = new Log($attributes);

                    return \strlen($log->getId() . $log->getEvent() . $log->getResource() . $log->getUserAgent() . $log->getIp() . $log->getTime() . ($log->getActorId() ?? '') . ($log->getSdk() ?? '')) + \count($log->getData()) + ($log->getTenant() ?? 0);
                };
            }],
            'parse_resource' => ['Split a resource path into its type, id and parent', function (Session $s) {
                $adapter = new ClickHouse('clickhouse');
                $parse = \Closure::bind(fn (string $resource): array => $this->parseResource($resource), $adapter, SQL::class);

                return fn () => $parse('database/6978484940ff05762e1a/collection/watch_history/document/6a06d9a7001c3cd05d20');
            }],
            'clickhouse_count' => ['Count a user\'s logs on the ClickHouse service: one HTTP round trip', function (Session $s) {
                $adapter = self::clickhouse([], $s);
                $adapter->setup();
                $adapter->createBatch(self::batch());
                $queries = [Query::equal('actorId', 'user1')];

                return fn () => $adapter->count($queries);
            }],
        ];
    }

    /**
     * A ClickHouse `FORMAT JSON` answer of 10 rows.
     */
    private static function rows(): string
    {
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [
                'id' => \sprintf('6a%011x.%08d', $i, $i), 'actorId' => "user{$i}", 'event' => 'documents.update',
                'resource' => "database/db1/collection/c1/document/{$i}", 'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6)',
                'ip' => "203.0.113.{$i}", 'time' => \sprintf('2025-06-15 12:%02d:00.000', $i), 'actorType' => 'user', 'resourceType' => 'document',
                'resourceId' => (string) $i, 'projectId' => 'p1', 'country' => 'us', 'data' => json_encode(['key' => 'value', 'n' => $i]),
            ];
        }

        return json_encode(['meta' => [], 'data' => $rows, 'rows' => 10], JSON_THROW_ON_ERROR);
    }

    /**
     * Batch logs with the ClickHouse columns.
     *
     * @return list<array<string, mixed>>
     */
    private static function batch(int $count = 10): array
    {
        $logs = [];
        for ($i = 0; $i < $count; $i++) {
            $logs[] = [
                'id' => "log-{$i}", 'actorId' => 'user1', 'event' => 'documents.update', 'resource' => "database/db1/collection/c1/document/{$i}",
                'userAgent' => 'Mozilla/5.0', 'ip' => '203.0.113.7', 'time' => \sprintf('2025-06-15 12:%02d:00.000', $i % 60),
                'data' => ['key' => 'value', 'projectInternalId' => '1', 'teamId' => 't1', 'teamInternalId' => '1', 'hostname' => 'example.org'],
                'actorType' => 'user', 'projectId' => 'p1',
            ];
        }

        return $logs;
    }
}
