<?php

/**
 * Area `core`: the Database orchestration (db.*, database.*), the twin of
 * crates/tools/compat/src/libs/database/core/ (mod.rs). See tests/compat/README.md
 * (`database`: the core vocabulary) for every operation, its arguments and
 * its result.
 */

namespace Tests\Compat\Database;

use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None as NoneCache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\Cache\Codec\Igbinary;
use Utopia\Cache\Codec\Json;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Pool;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\ReadWritePool;
use Utopia\Database\Adapter\Redis as RedisAdapter;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Cache\Query as QueryCache;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Filter\Registry;
use Utopia\Database\Mirror;
use Utopia\Database\Mirror\Failure;
use Utopia\Database\Mirror\Filter as MirrorFilter;
use Utopia\Database\PDO;
use Utopia\Database\Profiler;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Query\Builder\Statement;

require_once \dirname(__DIR__) . '/Fixtures.php';
require_once \dirname(__DIR__) . '/Objects.php';

// The custom document types of the e2e suite (Support\User, Support\Post).
foreach (['User', 'Post'] as $support) {
    $file = \dirname(__DIR__, 4) . "/vendor/utopia-php/database/tests/e2e/Adapter/Support/{$support}.php";
    if (is_file($file)) {
        require_once $file;
    }
}

/**
 * One invocation of a callback an operation passed to the library: the
 * arguments it was called with and the results of its steps.
 */
final class Invocation
{
    /** @var list<mixed> encoded results of the steps (null for `$throw` / `$return`) */
    public array $steps = [];

    /**
     * @param list<mixed> $args the arguments, encoded as the call received them
     */
    public function __construct(public readonly array $args)
    {
    }
}

/**
 * The connection pool of a `db.open` with `pool`, which `pool.of` shares.
 */
final class PoolFixture
{
    /** @var UtopiaPool<Adapter>|null */
    public ?UtopiaPool $pool = null;
}

/**
 * What a session's databases share: the current database, each database's
 * PDO (for `db.sql`), and the log fixtures write to (`db.log`).
 */
final class CoreState
{
    public ?Database $db = null;

    /** @var \WeakMap<Database, PoolFixture> the pool of each Pool database */
    public \WeakMap $pools;

    /** @var \WeakMap<Mirror, array{source: Database, destination: ?Database}> the databases of each mirror */
    public \WeakMap $mirrors;

    /** @var \WeakMap<Database, PDO> */
    public \WeakMap $pdos;

    /** @var list<array<string, mixed>> */
    public array $log = [];

    public function __construct()
    {
        $this->pdos = new \WeakMap();
        $this->pools = new \WeakMap();
        $this->mirrors = new \WeakMap();
    }
}

final class Core
{
    /**
     * PDO attributes of the connections db.open makes (7.x's
     * SQL::getPDOAttributes(), which 8.x leaves to the caller).
     */
    public const PDO_ATTRIBUTES = [
        \PDO::ATTR_TIMEOUT => 3,
        \PDO::ATTR_PERSISTENT => true,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES => true,
        \PDO::ATTR_STRINGIFY_FETCHES => true,
    ];

    /** The hooks a database opens with unless `hooks` says otherwise (the e2e Base's setUp). */
    public const DEFAULT_HOOKS = ['Relationships', 'Permissions'];

    /** @var array<string, callable(array<string, mixed>, Session): mixed> operations returning PHP values */
    public static array $raw = [];

    /** @var \WeakMap<Session, CoreState>|null */
    private static ?\WeakMap $states = null;

    /** The session whose operation runs now: fixtures log into it. */
    private static ?Session $current = null;

    public static function state(Session $s): CoreState
    {
        self::$states ??= new \WeakMap();

        return self::$states[$s] ??= new CoreState();
    }

    public static function enter(Session $s): void
    {
        self::$states ??= new \WeakMap();
        if (!isset(self::$states[$s])) {
            self::fresh($s);
        }
        self::$current = $s;
    }

    /**
     * A case (its session's first step) starts with the library's registries as a fresh process has them (the
     * built-in filters, no formats) plus the canonical filter fixtures: what an earlier case registered or
     * overrode (a unit test replacing the built-in `datetime` filter) does not leak into it.
     */
    private static function fresh(Session $s): void
    {
        self::state($s);
        (new \ReflectionProperty(Database::class, 'filters'))->setValue(null, []);
        (new \ReflectionProperty(Database::class, 'defaultFiltersRegistered'))->setValue(null, false);
        (new \ReflectionProperty(Structure::class, 'formats'))->setValue(null, []);
        self::filters();
    }

    /**
     * Appends an entry to the log of the session running now.
     *
     * @param array<string, mixed> $entry
     */
    public static function log(array $entry): void
    {
        if (self::$current !== null) {
            self::state(self::$current)->log[] = $entry;
        }
    }

    /**
     * The database an operation acts on: `db` (a handle) or the session's current one.
     *
     * @param array<string, mixed> $a
     */
    public static function db(array $a, Session $s): Database
    {
        if (isset($a['db'])) {
            $db = $s->get($a['db']);
            if (!$db instanceof Database) {
                throw new Fault('`db` is not a database handle');
            }

            return $db;
        }

        return self::state($s)->db ?? throw new Fault('no database: run `db.open` first');
    }

    /**
     * Registers the canonical global filter fixtures (Database::addFilter).
     * They are process-wide and cache keys carry every registered filter's
     * signature: registering them all as a database opens keeps a case's
     * keys independent of the cases its driver ran before it.
     */
    public static function filters(): void
    {
        foreach (FilterFixtures::CANONICAL as $name) {
            $filter = FilterFixtures::get($name);
            Database::addFilter($name, $filter['encode'], $filter['decode']);
        }
    }

    /**
     * `db.open`: see the README (`database`: the core vocabulary).
     *
     * @param array<string, mixed> $a
     * @return array{'$handle': string}
     */
    public static function open(array $a, Session $s): array
    {
        $known = ['namespace', 'database', 'shared_tables', 'tenant', 'tenant_per_document', 'cache', 'create', 'roles', 'adapter', 'filters', 'pool', 'mirror', 'redis', 'engine', 'file', 'emulate_mysql', 'detached', 'driver', 'schemaless', 'hooks'];
        foreach (array_keys($a) as $key) {
            if (!\in_array($key, $known, true)) {
                throw new Fault("db.open: unknown argument `{$key}`");
            }
        }
        $state = self::state($s);
        self::filters();
        if (isset($a['adapter'])) {
            $peer = $s->get($a['adapter']);
            if (!$peer instanceof Database) {
                throw new Fault('db.open: `adapter` is not a database handle');
            }
            $adapter = $peer->getAdapter();
            $pdo = $state->pdos[$peer] ?? null;
            $poolFixture = $state->pools[$peer] ?? null;
        } elseif (isset($a['pool'])) {
            // A Pool adapter over connections of `driver`, with a connection
            // of its own for `db.sql`.
            $spec = (array) $a['pool'];
            $driver = (string) ($a['driver'] ?? 'postgres');
            $poolFixture = self::pool($spec, $driver, $s);
            if (isset($spec['read'])) {
                $read = self::pool((array) $spec['read'], $driver, $s);
                $adapter = new ReadWritePool($poolFixture->pool, $read->pool);
            } else {
                $adapter = new Pool($poolFixture->pool);
            }
            $pdo = ($spec['unreachable'] ?? false) ? null : self::connect($driver, $s)[1];
        } elseif ($a['redis'] ?? false) {
            // The Redis adapter, on compat Redis database
            // `database-redis-adapter` (keys carry the case namespace).
            $adapter = new RedisAdapter(self::redis($s, 'database-redis-adapter'));
            $pdo = null;
            $poolFixture = null;
        } elseif (($a['engine'] ?? 'postgres') === 'mongo') {
            // MongoDB service `database-mongo`: the client's database is the
            // one every collection lives in, `database` (default: the case
            // namespace).
            $adapter = new Mongo(self::mongo($s, (string) ($a['database'] ?? $s->ns)));
            $pdo = null;
            $poolFixture = null;
        } else {
            [$adapter, $pdo] = self::engine($a, $s);
            $poolFixture = null;
        }
        // A peer (on another database's adapter, or `detached` on an adapter
        // of its own) is only used through `db`, and set up by the case.
        $peer = isset($a['adapter']) || !empty($a['detached']);
        // A database of its own starts on an empty private Redis cache, as
        // the e2e suites flush theirs; a peer shares it.
        $cache = self::cache($a['cache'] ?? 'memory', $s, fresh: !$peer);
        // Instance filters: Filter\Codec fixtures.
        $codecs = array_map(Codecs::make(...), Values::list($a['filters'] ?? []));
        $db = isset($a['mirror'])
            ? self::mirror((array) $a['mirror'], $adapter, $cache, $pdo, $s)
            : new Database($adapter, $cache, $codecs);
        $authorization = new Authorization();
        foreach (Values::list($a['roles'] ?? ['any']) as $role) {
            $authorization->addRole((string) $role);
        }
        $db->setAuthorization($authorization);
        if ($a['schemaless'] ?? false) {
            $db->setSchemaless(true);
        }
        foreach (Values::list($a['hooks'] ?? self::DEFAULT_HOOKS) as $hook) {
            $db->addHook(Hooks::make($hook));
        }
        if (!$peer) {
            $db->setDatabase((string) ($a['database'] ?? $s->ns));
        }
        if (\array_key_exists('shared_tables', $a)) {
            $db->setSharedTables((bool) $a['shared_tables']);
        }
        if (\array_key_exists('tenant', $a)) {
            $db->setTenant($a['tenant']);
        }
        if (\array_key_exists('tenant_per_document', $a)) {
            $db->setTenantPerDocument((bool) $a['tenant_per_document']);
        }
        if (!$peer || \array_key_exists('namespace', $a)) {
            $db->setNamespace((string) ($a['namespace'] ?? 'compat'));
        }
        if ($pdo !== null) {
            $state->pdos[$db] = $pdo;
        }
        if ($poolFixture !== null) {
            $state->pools[$db] = $poolFixture;
        }
        if (!$peer) {
            $state->db = $db;
        }
        if (!$peer && ($a['create'] ?? true)) {
            $db->create();
        }

        return $s->handle($db);
    }

    /**
     * A client of MongoDB service `database-mongo` on database `$database`.
     */
    public static function mongo(Session $s, string $database): \Utopia\Mongo\Client
    {
        $url = parse_url($s->service('database-mongo'));
        $client = new \Utopia\Mongo\Client(
            $database,
            (string) ($url['host'] ?? ''),
            (int) ($url['port'] ?? 27017),
            urldecode($url['user'] ?? ''),
            urldecode($url['pass'] ?? ''),
            false,
        );
        $client->connect();

        return $client;
    }

    /**
     * The adapter (and its PDO) `db.open` builds for `driver`: `postgres`
     * (service `database-postgres`), `mariadb` (`database-mariadb`) or
     * `mysql` (`database-mysql`), with Core::PDO_ATTRIBUTES.
     *
     * @return array{Adapter, PDO}
     */
    public static function connect(string $driver, Session $s): array
    {
        if ($driver === 'postgres') {
            $pdo = self::pdo($s);

            return [new Postgres($pdo), $pdo];
        }
        if ($driver !== 'mariadb' && $driver !== 'mysql') {
            throw new Fault("db.open: unknown driver `{$driver}`");
        }
        $url = parse_url($s->service($driver === 'mariadb' ? 'database-mariadb' : 'database-mysql'));
        $dsn = 'mysql:host=' . ($url['host'] ?? '') . ';port=' . ($url['port'] ?? 3306) . ';charset=utf8mb4';
        $pdo = new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), self::PDO_ATTRIBUTES);

        return [$driver === 'mariadb' ? new MariaDB($pdo) : new MySQL($pdo), $pdo];
    }

    /**
     * The adapter (and its PDO, if any) `db.open` builds for `engine`.
     *
     * @param array<string, mixed> $a
     * @return array{0: Adapter, 1: ?PDO}
     */
    public static function engine(array $a, Session $s): array
    {
        switch ($a['engine'] ?? 'postgres') {
            case 'postgres':
                // `driver`: PostgreSQL, MariaDB or MySQL.
                return self::connect((string) ($a['driver'] ?? 'postgres'), $s);
            case 'sqlite':
                $dir = '/tmp/compat-fs/' . $s->ns;
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $file = (string) ($a['file'] ?? 'database.sqlite');
                if ($file === '' || str_contains($file, '/')) {
                    throw new Fault('db.open: `file` is a file name');
                }
                $pdo = new PDO('sqlite:' . $dir . '/' . $file, null, null, self::PDO_ATTRIBUTES);
                $adapter = ($a['emulate_mysql'] ?? false) ? new Fixture\EmulatedSQLite($pdo) : new SQLite($pdo);

                return [$adapter, $pdo];
            case 'memory':
                return [new MemoryAdapter(), null];
            default:
                throw new Fault('db.open: `engine` must be "postgres", "sqlite", "memory" or "mongo"');
        }
    }

    /**
     * A Mirror (`db.open` with `mirror`): `$adapter` and `$cache` make its
     * source; a destination on PostgreSQL service `database-postgres-mirror`
     * with cache `cache` (default `redis-mirror`, flushed) unless
     * `"destination": false`; `filters` names Mirror\Filter fixtures.
     *
     * @param array<string, mixed> $spec
     */
    public static function mirror(array $spec, Adapter $adapter, Cache $cache, ?PDO $pdo, Session $s): Mirror
    {
        $state = self::state($s);
        $source = new Database($adapter, $cache);
        if ($pdo !== null) {
            $state->pdos[$source] = $pdo;
        }
        $destination = null;
        if ($spec['destination'] ?? true) {
            $destinationPdo = self::pdo($s, 'database-postgres-mirror');
            $destination = new Database(new Postgres($destinationPdo), self::cache($spec['cache'] ?? 'redis-mirror', $s, fresh: true));
            $state->pdos[$destination] = $destinationPdo;
        }
        $filters = array_map(fn ($name) => self::mirrorFilter((string) $name), Values::list($spec['filters'] ?? []));
        $mirror = new Mirror($source, $destination, $filters);
        $state->mirrors[$mirror] = ['source' => $source, 'destination' => $destination];

        return $mirror;
    }

    /**
     * A Mirror\Filter fixture by name: `log` logs each hook it runs
     * (`{"mirror_filter": <hook>, "collection", ...}`).
     */
    public static function mirrorFilter(string $name): MirrorFilter
    {
        return match ($name) {
            'log' => new Fixture\LogMirrorFilter(),
            default => throw new Fault("no mirror filter fixture `{$name}`"),
        };
    }

    /**
     * A PDO on a PostgreSQL service (`database-postgres` by default).
     */
    public static function pdo(Session $s, string $service = 'database-postgres'): PDO
    {
        $url = parse_url($s->service($service));
        $dsn = 'pgsql:host=' . ($url['host'] ?? '') . ';port=' . ($url['port'] ?? 5432) . ';dbname=' . ltrim($url['path'] ?? '', '/');

        return new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), self::PDO_ATTRIBUTES);
    }

    /**
     * The pool of a `db.open` with `pool`: `{"size": n}` (default 1) new
     * connections of `driver` on demand, from a Stack, waiting 0 s; with
     * `"unreachable": true` every connection attempt fails as a refused
     * connection does; `{"of": <database handle>}` the pool of another Pool
     * database.
     *
     * @param array<string, mixed> $spec
     */
    public static function pool(array $spec, string $driver, Session $s): PoolFixture
    {
        if (isset($spec['of'])) {
            $peer = $s->get($spec['of']);

            return ($peer instanceof Database ? self::state($s)->pools[$peer] ?? null : null)
                ?? throw new Fault('db.open: `pool.of` is not a Pool database');
        }
        $fixture = new PoolFixture();
        $unreachable = (bool) ($spec['unreachable'] ?? false);
        $fixture->pool = new UtopiaPool(new Stack(), $driver, (int) ($spec['size'] ?? 1), function () use ($unreachable, $driver, $s) {
            if ($unreachable) {
                throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }

            return self::connect($driver, $s)[0];
        }, timeout: 0.0);

        return $fixture;
    }

    /**
     * The Redis caches by kind: the compat service each runtime's entries live
     * on (`redis`, `redis-mirror`: private to each runtime; `redis-shared`:
     * one both read and write), with Json entries, or igbinary ones with the
     * `-igbinary` suffix (Appwrite's configuration).
     */
    public const REDIS_CACHES = [
        'redis' => 'database-redis',
        'redis-mirror' => 'database-redis-mirror',
        'redis-shared' => 'database-redis-shared',
    ];

    /**
     * A cache by kind: `memory`, `none` or a Redis cache (REDIS_CACHES).
     * `$fresh` empties a private Redis cache's database first.
     */
    public static function cache(mixed $kind, ?Session $s = null, bool $fresh = false): Cache
    {
        if ($kind === 'memory') {
            return new Cache(new Memory());
        }
        if ($kind === 'none') {
            return new Cache(new NoneCache());
        }
        if ($kind === 'unreachable') {
            // A Redis cache whose server is gone: every command fails, no retries.
            Fixture\UnreachableRedisCommands::declare();

            return new Cache((new RedisCache(new Fixture\UnreachableRedis()))->setMaxRetries(0));
        }
        $kind = (string) $kind;
        $igbinary = str_ends_with($kind, '-igbinary');
        $store = $igbinary ? substr($kind, 0, -\strlen('-igbinary')) : $kind;
        $service = self::REDIS_CACHES[$store] ?? throw new Fault('cache must be "memory", "none" or one of "' . implode('", "', array_keys(self::REDIS_CACHES)) . '" (with "-igbinary" for igbinary entries)');
        if ($s === null) {
            throw new Fault("a {$kind} cache needs the session");
        }
        $redis = self::redis($s, $service);
        if ($fresh && $store !== 'redis-shared') {
            $redis->flushDB();
        }
        $cache = new Cache(new RedisCache($redis, $igbinary ? new Igbinary() : new Json()));
        self::$kinds ??= new \WeakMap();
        self::$kinds[$cache] = $kind;

        return $cache;
    }

    /** @var \WeakMap<Cache, string>|null the kind of each Redis cache `cache()` made (the recorder encodes caches by kind) */
    public static ?\WeakMap $kinds = null;

    /**
     * A phpredis client on a compat Redis service, its database selected.
     */
    public static function redis(Session $s, string $service): \Redis
    {
        $url = parse_url($s->service($service));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);
        $redis->select((int) ltrim($url['path'] ?? '', '/'));

        return $redis;
    }

    /**
     * `db.cache`: a method of the database's Cache. Saves report whether they
     * stored (PHP returns the data or false).
     *
     * @param array<string, mixed> $a
     */
    public static function cacheCall(Cache $cache, string $method, array $a): mixed
    {
        $data = fn () => Values::decode(self::tags($a['data'] ?? null));

        return match ($method) {
            'load' => $cache->load((string) ($a['key'] ?? ''), (int) ($a['ttl'] ?? Database::TTL), (string) ($a['hash'] ?? '')),
            'save' => $cache->save((string) ($a['key'] ?? ''), $data(), (string) ($a['hash'] ?? ''), (int) ($a['ttl'] ?? 0)) !== false,
            'save_with_lease' => $cache->saveWithLease((string) ($a['key'] ?? ''), $data(), (string) ($a['hash'] ?? ''), (string) ($a['generation'] ?? '')) !== false,
            'get_generation' => $cache->getGeneration((string) ($a['key'] ?? '')),
            'touch' => $cache->touch((string) ($a['key'] ?? ''), (string) ($a['hash'] ?? '')),
            'list' => $cache->list((string) ($a['key'] ?? '')),
            'purge' => $cache->purge((string) ($a['key'] ?? ''), (string) ($a['hash'] ?? '')),
            default => throw new Fault("db.cache: no method `{$method}`"),
        };
    }

    /**
     * Calls `$method` of `$target` (`__construct` of a class: builds it) with
     * named arguments decoded for its parameters, and encodes the result. Callbacks passed as `{"steps": [...]}`
     * (or `true` for one without steps) make the result
     * `{"result": ..., "calls": {"<param>": [{"args": [...], "steps": [...]}]}}`.
     *
     * @param array<string, mixed> $a
     */
    public static function invoke(object|string $target, string $method, array $a, Session $s): mixed
    {
        $class = \is_object($target) ? $target::class : $target;
        if (!method_exists($class, $method)) {
            throw new Fault("{$class} has no method `{$method}`");
        }
        $reflection = new \ReflectionMethod($class, $method);
        if (!$reflection->isPublic()) {
            throw new Fault("{$class}::{$method} is not public");
        }
        /** @var array<string, list<Invocation>> $calls */
        $calls = [];
        $hasCallbacks = false;
        $named = [];
        $params = [];
        foreach ($reflection->getParameters() as $p) {
            $params[$p->getName()] = $p;
        }
        foreach (Objects::resolve($a, $s) as $key => $value) {
            if ($key === 'db') {
                continue;
            }
            $p = $params[$key] ?? throw new Fault("{$class}::{$method} has no parameter `\${$key}`");
            if ((self::accepts($p, 'callable') || self::accepts($p, 'Closure')) && $value !== null) {
                $hasCallbacks = true;
                $calls[$key] = [];
                $named[$key] = self::callback($s, $value, $calls[$key]);
                continue;
            }
            $named[$key] = self::argument($p, $value, $s);
        }
        foreach ($params as $name => $p) {
            if (!\array_key_exists($name, $named) && !$p->isOptional()) {
                throw new Fault("{$class}::{$method}: missing `{$name}`");
            }
        }
        $result = match (true) {
            $reflection->isConstructor() => new $class(...$named),
            \is_object($target) && !$reflection->isStatic() => $target->{$method}(...$named),
            default => $class::{$method}(...$named),
        };
        if ($result instanceof \Generator) {
            $result = iterator_to_array($result, false);
        }
        if (!$hasCallbacks) {
            return $result;
        }

        return new Called($result, $calls);
    }

    /**
     * The class names a parameter's type accepts.
     *
     * @return list<string>
     */
    private static function types(\ReflectionParameter $p): array
    {
        $t = $p->getType();

        return match (true) {
            $t instanceof \ReflectionNamedType => [$t->getName()],
            $t instanceof \ReflectionUnionType => array_map(fn ($n) => $n instanceof \ReflectionNamedType ? $n->getName() : (string) $n, $t->getTypes()),
            default => [],
        };
    }

    private static function accepts(\ReflectionParameter $p, string $type): bool
    {
        return \in_array($type, self::types($p), true);
    }

    /**
     * Whether the method's docblock declares the parameter `array<Document>`
     * (its items may then be plain arrays).
     */
    private static function documentList(\ReflectionParameter $p): bool
    {
        $doc = str_replace("\t", ' ', (string) $p->getDeclaringFunction()->getDocComment());
        while (str_contains($doc, '  ')) {
            $doc = str_replace('  ', ' ', $doc);
        }
        foreach (['array<Document>', 'list<Document>', 'array<Document>|null', 'list<Document>|null', 'array<int, Document>'] as $type) {
            if (str_contains($doc, $type . ' $' . $p->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * An argument decoded for its parameter. See the README for each rule.
     */
    public static function argument(\ReflectionParameter $p, mixed $value, Session $s): mixed
    {
        $value = self::tags($value);
        if ($value === null) {
            return null;
        }
        $types = self::types($p);
        // A PDO: {"$pdo": {"dsn", "wrapper"?, "username"?, "password"?, "options"?}}.
        if (\is_array($value) && \count($value) === 1 && \is_array($value['$pdo'] ?? null)) {
            return self::pdoOf($value['$pdo']);
        }
        // An exception: {"$throwable": {"class", "message", "code"?, "errorInfo"?, "previous"?}}.
        if (\is_array($value) && \count($value) === 1 && \is_array($value['$throwable'] ?? null)) {
            return self::throwable($value['$throwable']);
        }
        // An object already (a stored `$obj`): as it is, when the parameter takes it.
        if (\is_object($value) && !$value instanceof \stdClass) {
            foreach ($types as $type) {
                if ($type === 'mixed' || $type === 'object' || is_a($value, $type)) {
                    return $value;
                }
            }
        }
        if (\in_array(Collection::class, $types, true) && !\in_array(Document::class, $types, true)) {
            $value = Values::decode($value);
            if ($value instanceof Collection) {
                return $value;
            }

            return Collection::fromDocument($value instanceof Document ? $value : Values::document($value));
        }
        if (\in_array(Document::class, $types, true)) {
            return Values::document($value);
        }
        if (\in_array(\Utopia\Query\Query::class, $types, true) || \in_array(Query::class, $types, true)) {
            return Values::queries([$value])[0];
        }
        if (\in_array(\DateTime::class, $types, true)) {
            $value = Values::decode($value);

            return $value instanceof \DateTime ? $value : new \DateTime((string) $value);
        }
        if (\in_array(Cache::class, $types, true)) {
            return self::cache($value, $s);
        }
        if (\in_array(QueryCache::class, $types, true)) {
            return self::queryCache($value, $s);
        }
        if (\in_array(Authorization::class, $types, true)) {
            if (isset($value['$handle'])) {
                // Another database's (shared) Authorization.
                $db = $s->get($value);
                if (!$db instanceof Database) {
                    throw new Fault('an authorization is {"roles": [...]} or a database handle');
                }

                return $db->getAuthorization();
            }
            $authorization = new Authorization();
            foreach (Values::list($value['roles'] ?? []) as $role) {
                $authorization->addRole((string) $role);
            }
            if (\array_key_exists('status', $value)) {
                $authorization->setStatus((bool) $value['status']);
            }

            return $authorization;
        }
        if (array_filter($types, static fn (string $type) => (interface_exists($type) || class_exists($type)) && is_a($type, \Utopia\Query\Hook::class, true)) !== []) {
            // A hook (addHook), or with `string` also a class name (removeHook).
            if (\is_string($value) && \in_array('string', $types, true)) {
                return Hooks::className($value);
            }
            $hook = Hooks::make($value);
            Objects::store($s, \is_array($value) ? $value['as'] ?? null : null, $hook);

            return $hook;
        }
        if (\in_array(\Utopia\Database\Filter\Codec::class, $types, true)) {
            $codec = Codecs::make($value);
            Objects::store($s, \is_array($value) ? $value['as'] ?? null : null, $codec);

            return $codec;
        }
        // The codecs a Database is built with.
        if ($p->getName() === 'filters' && $p->getDeclaringClass()?->getName() === Database::class && $p->getDeclaringFunction()->getName() === '__construct') {
            return array_map(fn ($codec) => \is_object($codec) ? $codec : Codecs::make($codec), Values::list(Objects::resolve($value, $s)));
        }
        if (\in_array(Registry::class, $types, true)) {
            $registry = new Registry();
            foreach (Values::list($value) as $codec) {
                $registry->register(Codecs::make($codec));
            }

            return $registry;
        }
        if (\in_array(Statement::class, $types, true)) {
            // A raw statement: a Query\Builder\Statement value, or {"query", "bindings"}.
            $statement = Values::decode($value);

            return $statement instanceof Statement ? $statement : new Statement((string) ($value['query'] ?? ''), Values::decodeArray($value['bindings'] ?? []));
        }
        if ($p->getName() === 'queries' && \in_array('array', $types, true)) {
            return Values::queries($value);
        }
        if (\in_array('array', $types, true) && self::documentList($p)) {
            return Values::documents($value);
        }
        if (\in_array(Adapter::class, $types, true) || \in_array(Database::class, $types, true)) {
            $db = $s->get($value);
            if (!$db instanceof Database) {
                throw new Fault("`\${$p->getName()}` takes a database handle");
            }

            return \in_array(Database::class, $types, true) ? $db : $db->getAdapter();
        }
        foreach ($types as $type) {
            // An enum parameter takes a `$enum` tag or a backed value.
            if (enum_exists($type) && \is_subclass_of($type, \BackedEnum::class) && (\is_string($value) || \is_int($value))) {
                return $type::from($value);
            }
        }
        if (\in_array('string', $types, true) && \is_string($value) && $p->getName() === 'name' && $p->getDeclaringFunction()->getName() === 'removeTransform') {
            return Hooks::className($value);
        }

        return Values::decode($value);
    }

    /**
     * An exception argument: `class` (any Throwable, `Exception` by default)
     * with `message`, `code` (a string code, such as an SQLSTATE, is set as
     * PDO sets it), a PDOException's `errorInfo`, and a `previous` one.
     *
     * @param array<string, mixed> $spec
     */
    public static function throwable(array $spec): \Throwable
    {
        $class = (string) ($spec['class'] ?? \Exception::class);
        if (!is_a($class, \Throwable::class, true)) {
            throw new Fault("{$class} is not a Throwable");
        }
        $previous = isset($spec['previous']) ? self::throwable((array) $spec['previous']) : null;
        $error = new $class((string) ($spec['message'] ?? ''), 0, $previous);
        if (\array_key_exists('code', $spec)) {
            (new \ReflectionProperty(\Exception::class, 'code'))->setValue($error, $spec['code']);
        }
        if ($error instanceof \PDOException && \array_key_exists('errorInfo', $spec)) {
            $error->errorInfo = Values::list($spec['errorInfo']);
        }

        return $error;
    }

    /**
     * A PDO a unit test built: `dsn` (an in-memory SQLite one), as a
     * Utopia\Database\PDO with `wrapper`, with `username`, `password` and
     * `options`.
     *
     * @param array<string, mixed> $spec
     */
    public static function pdoOf(array $spec): \PDO|PDO
    {
        $dsn = (string) ($spec['dsn'] ?? '');
        $username = isset($spec['username']) ? (string) $spec['username'] : null;
        $password = isset($spec['password']) ? (string) $spec['password'] : null;
        $options = Values::decodeArray($spec['options'] ?? []);

        return ($spec['wrapper'] ?? false) ? new PDO($dsn, $username, $password, $options) : new \PDO($dsn, $username, $password, $options);
    }

    /**
     * A query cache (setQueryCache): `{"cache": <cache kind or "database">,
     * "slots"?: n}`; `"database"` is the database's own cache.
     */
    private static function queryCache(mixed $value, Session $s): QueryCache
    {
        if (!\is_array($value)) {
            throw new Fault('a query cache is {"cache": <kind>|"database", "slots"?: n}');
        }
        $kind = $value['cache'] ?? 'database';
        $cache = $kind === 'database' ? (self::state($s)->db ?? throw new Fault('no database'))->getCache() : self::cache($kind, $s);

        $queryCache = isset($value['slots']) ? new QueryCache($cache, (int) $value['slots']) : new QueryCache($cache);
        Objects::store($s, $value['as'] ?? null, $queryCache);

        return $queryCache;
    }

    /**
     * Arguments tags decoded before Values: `{"$datetime": "<DateTime string>"}`
     * is a DateTime (relative forms such as "-3600 seconds" are from now),
     * `{"$now": <seconds>, "format": "<DateTime::format>"}` the string of now
     * plus the seconds, `{"$repeat": "<text>", "times": <n>}` the text
     * repeated (long fixtures such as the suite's longtext.txt).
     */
    public static function tags(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\count($value) === 1 && \array_key_exists('$datetime', $value) && \is_string($value['$datetime'])) {
            return new \DateTime($value['$datetime']);
        }
        if (\count($value) === 2 && \array_key_exists('$repeat', $value) && \array_key_exists('times', $value)) {
            return str_repeat((string) $value['$repeat'], (int) $value['times']);
        }
        if (\count($value) === 2 && \array_key_exists('$now', $value) && \array_key_exists('format', $value)) {
            $seconds = (int) $value['$now'];

            return (new \DateTime())->modify(($seconds < 0 ? '' : '+') . $seconds . ' seconds')->format((string) $value['format']);
        }

        return array_map(self::tags(...), $value);
    }

    /**
     * A callback from an argument: `true` (or `{}`) records its calls, `{"steps":
     * [...]}` also runs the steps at each call.
     *
     * @param list<Invocation> $calls
     */
    private static function callback(Session $s, mixed $spec, array &$calls): \Closure
    {
        if ($spec === true || $spec instanceof \stdClass) {
            $steps = [];
        } elseif (\is_array($spec) && \is_array($spec['steps'] ?? null)) {
            $steps = $spec['steps'];
        } else {
            throw new Fault('a callback is null, true or {"steps": [...]}');
        }

        return function (mixed ...$args) use ($s, $steps, &$calls) {
            // Encoded as the callback receives them: the library may change a
            // document after the call, which a Rust callback, given its own
            // copy, never sees.
            $invocation = new Invocation(array_map(self::result(...), array_values($args)));
            $calls[] = $invocation;

            return self::run($s, $steps, $invocation);
        };
    }

    /**
     * The step interpreter of callbacks. Each step is `{op, args, bind?,
     * catch?, return?}`; `{"$local": <bind>, "path"?}` in arguments is an
     * earlier step's encoded result, `{"$arg": <n>, "path"?}` the callback's
     * n-th argument (encoded). A step that throws records `{"$error": ...}`
     * and, unless it has `"catch": true`, the exception leaves the callback.
     * `{"op": "$throw", "args": {"class", "message"}}` throws; `{"op":
     * "$return", "args": {"value"}}` sets what the callback returns, as does
     * `"return": true` on a step (its result).
     *
     * @param list<mixed> $steps
     */
    public static function run(Session $s, array $steps, Invocation $invocation): mixed
    {
        $locals = [];
        $return = null;
        foreach ($steps as $step) {
            if (!\is_array($step) || !\is_string($step['op'] ?? null)) {
                throw new Fault('a step is {"op": ..., "args": {...}}');
            }
            $op = $step['op'];
            $args = self::locals($step['args'] ?? [], $locals, $invocation);
            $args = $args instanceof \stdClass ? [] : $args;
            if ($op === '$throw') {
                $invocation->steps[] = null;
                $class = (string) ($args['class'] ?? \Exception::class);
                if (!is_a($class, \Throwable::class, true)) {
                    throw new Fault("\$throw: {$class} is not a Throwable");
                }
                throw new $class((string) ($args['message'] ?? ''));
            }
            if ($op === '$return') {
                $invocation->steps[] = null;
                $return = Values::decode(self::tags($args['value'] ?? null));
                continue;
            }
            $fn = self::$raw[$op] ?? throw new Fault("unknown step operation `{$op}`");
            try {
                $raw = $fn($args, $s);
                $encoded = self::result($raw);
            } catch (Fault $fault) {
                throw $fault;
            } catch (\Throwable $error) {
                $invocation->steps[] = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
                if (!($step['catch'] ?? false)) {
                    throw $error;
                }
                continue;
            }
            $invocation->steps[] = $encoded;
            if (isset($step['bind'])) {
                $locals[(string) $step['bind']] = $encoded;
            }
            if ($step['return'] ?? false) {
                $return = $raw instanceof Called ? $raw->result : $raw;
            }
        }

        return $return;
    }

    /**
     * @param array<string, mixed> $locals
     */
    private static function locals(mixed $value, array $locals, Invocation $invocation): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (isset($value['$local']) && \is_string($value['$local'])) {
            $bound = $locals[$value['$local']] ?? throw new Fault("`\$local` to unknown step `{$value['$local']}`");

            return self::pointer($bound, (string) ($value['path'] ?? ''));
        }
        if (isset($value['$arg']) && \is_int($value['$arg'])) {
            $arg = $invocation->args[$value['$arg']] ?? throw new Fault("the callback has no argument {$value['$arg']}");

            return self::pointer($arg, (string) ($value['path'] ?? ''));
        }

        return array_map(fn ($v) => self::locals($v, $locals, $invocation), $value);
    }

    private static function pointer(mixed $value, string $path): mixed
    {
        if ($path === '') {
            return $value;
        }
        foreach (\array_slice(explode('/', $path), 1) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (\is_array($value) && \array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif ($value instanceof \stdClass && property_exists($value, $segment)) {
                $value = $value->{$segment};
            } else {
                throw new Fault("nothing at `{$path}`");
            }
        }

        return $value;
    }

    /**
     * The library class an object is an instance of (the nearest ancestor in
     * Utopia\Database), as a short name: fixture subclasses report their
     * library class.
     */
    public static function libraryClass(object $value): string
    {
        for ($class = new \ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            if (str_starts_with($class->getName(), 'Utopia\\Database\\')) {
                return $class->getShortName();
            }
        }

        return (new \ReflectionClass($value))->getShortName();
    }

    /**
     * A library value as it crosses the wire: Values tags, plus the objects a
     * Database hands out (itself for fluent setters, its adapter, cache,
     * authorization, profiler and builders) as markers, generators as lists.
     */
    public static function result(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Called => [
                'result' => self::result($value->result),
                'calls' => array_map(
                    fn (array $invocations) => array_map(fn (Invocation $i) => [
                        'args' => $i->args,
                        'steps' => $i->steps,
                    ], $invocations),
                    $value->calls,
                ),
            ],
            $value instanceof Database => ['$database' => true],
            $value instanceof MirrorFilter => ['$filter' => true],
            $value instanceof Adapter => ['$adapter' => self::libraryClass($value)],
            $value instanceof Cache => ['$cache' => true],
            $value instanceof QueryCache => ['$queryCache' => true],
            $value instanceof Authorization => ['$authorization' => true],
            $value instanceof Profiler => ['$profiler' => true],
            $value instanceof Registry => ['$registry' => array_keys($value->signatures())],
            $value instanceof \Utopia\Database\Filter\Codec => ['$codec' => true],
            $value instanceof \Utopia\Query\Builder => ['$builder' => true],
            $value instanceof \Utopia\Query\Schema => ['$schema' => true],
            $value instanceof \Utopia\Query\Hook && !$value instanceof \Utopia\Database\Query => ['$hook' => self::libraryClass($value)],
            $value instanceof \Generator => self::result(iterator_to_array($value, false)),
            \is_array($value) => array_map(self::result(...), $value),
            default => Values::encode($value),
        };
    }

    /**
     * Operations from functions returning PHP values: each also takes `as`
     * (its raw result stored for `{"$obj": <name>}`), is registered for the
     * callback step interpreter (Core::run), and returns its result encoded.
     *
     * @param array<string, callable(array<string, mixed>, Session): mixed> $raw
     * @return array<string, callable(array<string, mixed>, Session): mixed>
     */
    public static function operations(array $raw): array
    {
        $raw = array_map(
            fn (callable $fn) => function (array $a, Session $s) use ($fn) {
                if (!isset($a['as'])) {
                    return $fn($a, $s);
                }
                $as = $a['as'];
                unset($a['as']);
                $result = $fn($a, $s);
                Objects::store($s, $as, $result instanceof Called ? $result->result : $result);

                return $result;
            },
            $raw,
        );
        self::$raw = $raw + self::$raw;

        return array_map(
            fn (callable $fn) => function (array $a, Session $s) use ($fn) {
                self::enter($s);

                return self::result($fn($a, $s));
            },
            $raw,
        );
    }

    /**
     * `fooBarBaz` -> `foo_bar_baz`.
     */
    public static function snake(string $name): string
    {
        $out = '';
        foreach (str_split($name) as $c) {
            $out .= ctype_upper($c) ? '_' . strtolower($c) : $c;
        }

        return $out;
    }

    /**
     * `db.sql`: a statement on the database's own connection, in its schema
     * only (a quoted identifier qualifying a name must be the schema).
     *
     * @return list<array<string, mixed>>
     */
    public static function sql(Database $db, string $sql, Session $s): array
    {
        $schema = $db->getDatabase();
        // Quoted as PostgreSQL ("schema".) or as MariaDB/MySQL (`schema`.).
        foreach (['"', '`'] as $quote) {
            $at = 0;
            while (($at = strpos($sql, $quote . '.', $at)) !== false) {
                $start = strrpos(substr($sql, 0, $at), $quote);
                $name = $start === false ? '' : substr($sql, $start + 1, $at - $start - 1);
                if ($name !== $schema) {
                    throw new Fault("db.sql: only schema `{$schema}` may be named, not `{$name}`");
                }
                $at += 2;
            }
        }
        $pdo = self::state($s)->pdos[$db] ?? throw new Fault('db.sql: this database has no connection of its own');
        $statement = $pdo->query($sql);

        return $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}

/**
 * A result together with the calls its callbacks received.
 */
final class Called
{
    /**
     * @param array<string, list<Invocation>> $calls
     */
    public function __construct(public readonly mixed $result, public readonly array $calls)
    {
    }
}

$raw = [
    'db.open' => Core::open(...),
    'db.select' => function (array $a, Session $s) {
        Core::state($s)->db = Core::db($a, $s);

        return true;
    },
    'db.adapter' => fn (array $a, Session $s) => Core::invoke(Core::db($a, $s)->getAdapter(), (string) ($a['method'] ?? ''), (array) ($a['args'] ?? []), $s),
    'db.log' => function (array $a, Session $s) {
        $state = Core::state($s);
        $log = $state->log;
        $state->log = [];

        return $log;
    },
    'db.sql' => fn (array $a, Session $s) => Core::sql(Core::db($a, $s), (string) ($a['sql'] ?? ''), $s),
    'db.mirror' => function (array $a, Session $s) {
        $db = Core::db($a, $s);
        $parts = $db instanceof Mirror ? Core::state($s)->mirrors[$db] ?? null : null;
        if ($parts === null) {
            throw new Fault('db.mirror: not a mirror');
        }
        $part = $parts[(string) ($a['part'] ?? 'source')] ?? null;

        return $part === null ? null : $s->handle($part);
    },
    'database.on_error' => function (array $a, Session $s) {
        $db = Core::db($a, $s);
        if (!$db instanceof Mirror) {
            throw new Fault('database.on_error: not a mirror');
        }
        $name = (string) ($a['name'] ?? 'error');
        $db->onError(function (Failure $failure) use ($name) {
            Core::log(['mirror' => $failure->method, 'callback' => $name, 'event' => $failure->event?->value, 'error' => Values::encode($failure->error)]);
        });

        return ['$database' => true];
    },
    'db.redis' => function (array $a, Session $s) {
        // A raw command on the Redis adapter's database (keys naming the
        // case namespace only); its reply is not reported.
        $command = array_map('strval', Values::list($a['command'] ?? []));
        if ($command === []) {
            throw new Fault('db.redis: `command` is a non-empty list');
        }
        Core::redis($s, 'database-redis-adapter')->rawCommand(...$command);

        return;
    },
    'db.cache' => fn (array $a, Session $s) => Core::cacheCall(Core::db($a, $s)->getCache(), (string) ($a['method'] ?? ''), (array) ($a['args'] ?? [])),
    // The database's Profiler (setProfiling(true) made it): a method of it.
    'db.profiler' => static function (array $a, Session $s) {
        $profiler = Core::db($a, $s)->getProfiler() ?? throw new Fault('db.profiler: the database is not profiling');

        return Core::invoke($profiler, (string) ($a['method'] ?? ''), (array) ($a['args'] ?? []), $s);
    },
    'db.add_format' => function (array $a, Session $s) {
        $name = (string) ($a['name'] ?? '');
        $format = Formats::get($name);
        Structure::addFormat($name, $format['callback'], $format['type']);

        return true;
    },
    'database.add_filter' => function (array $a, Session $s) {
        $name = (string) ($a['name'] ?? '');
        // `fixture` registers another fixture's callables under the name:
        // a new signature for it, until the next `db.open` registers the
        // canonical ones again.
        $filter = FilterFixtures::get((string) ($a['fixture'] ?? $name));
        Database::addFilter($name, $filter['encode'], $filter['decode']);

        return;
    },
];

// database.<snake_case>: every other public method of Database (and those
// Mirror adds), by reflection.
foreach ([...(new \ReflectionClass(Database::class))->getMethods(\ReflectionMethod::IS_PUBLIC), ...(new \ReflectionClass(Mirror::class))->getMethods(\ReflectionMethod::IS_PUBLIC)] as $method) {
    if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
        continue;
    }
    $name = $method->getName();
    $op = 'database.' . Core::snake($name);
    $raw[$op] ??= fn (array $a, Session $s) => Core::invoke(Core::db($a, $s), $name, $a, $s);
}
// The static ones that take no code: collectionDefinition, internalAttributesFor.
foreach (['collectionDefinition', 'internalAttributesFor'] as $name) {
    $raw['database.' . Core::snake($name)] = fn (array $a, Session $s) => Core::invoke(Database::class, $name, $a, $s);
}

// db.authorization.<snake_case>: the database's Authorization.
foreach ((new \ReflectionClass(Authorization::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
        continue;
    }
    $name = $method->getName();
    $raw['db.authorization.' . Core::snake($name)] = fn (array $a, Session $s) => Core::invoke(Core::db($a, $s)->getAuthorization(), $name, $a, $s);
}

return Core::operations($raw);
