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
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;
use Utopia\Validator\Range;

// The custom document types of the e2e suite (TestUser, TestPost), declared
// next to the trait that uses them.
require_once \dirname(__DIR__, 4) . '/vendor/utopia-php/database/tests/e2e/Adapter/Scopes/CustomDocumentTypeTests.php';

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
 * What a session's databases share: the current database, each database's
 * PDO (for `db.sql`), and the log fixtures write to (`db.log`).
 */
final class CoreState
{
    public ?Database $db = null;

    /** @var \WeakMap<Database, PDO> */
    public \WeakMap $pdos;

    /** @var list<array<string, mixed>> */
    public array $log = [];

    public function __construct()
    {
        $this->pdos = new \WeakMap();
    }
}

final class Core
{
    /** The filter fixtures (Core::filter), registered by every `db.open`. */
    public const FILTERS = ['encrypt', 'subQueryProbe', 'operator_double_decode'];

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
        self::$current = $s;
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
     * `db.open`: a Database on PostgreSQL service `database-postgres`, in
     * schema `database` (default: the case namespace).
     *
     * @param array<string, mixed> $a
     * @return array{'$handle': string}
     */
    public static function open(array $a, Session $s): array
    {
        $known = ['namespace', 'database', 'shared_tables', 'tenant', 'tenant_per_document', 'cache', 'create', 'roles', 'adapter', 'filters'];
        foreach (array_keys($a) as $key) {
            if (!\in_array($key, $known, true)) {
                throw new Fault("db.open: unknown argument `{$key}`");
            }
        }
        $state = self::state($s);
        // The fixture filters are process-wide (Database::$filters) and cache
        // keys carry every registered filter's signature: registering them all
        // as a database opens keeps a case's keys independent of the cases the
        // driver ran before it.
        foreach (self::FILTERS as $name) {
            $filter = self::filter($name);
            Database::addFilter($name, $filter['encode'], $filter['decode']);
        }
        if (isset($a['adapter'])) {
            $peer = $s->get($a['adapter']);
            if (!$peer instanceof Database) {
                throw new Fault('db.open: `adapter` is not a database handle');
            }
            $adapter = $peer->getAdapter();
            $pdo = $state->pdos[$peer] ?? null;
        } else {
            $url = parse_url($s->service('database-postgres'));
            $dsn = 'pgsql:host=' . ($url['host'] ?? '') . ';port=' . ($url['port'] ?? 5432) . ';dbname=' . ltrim($url['path'] ?? '', '/');
            $pdo = new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), Postgres::getPDOAttributes());
            $adapter = new Postgres($pdo);
        }
        $cache = self::cache($a['cache'] ?? 'memory');
        // Instance filters: name => the fixture it runs.
        $filters = [];
        foreach ((array) ($a['filters'] ?? []) as $name => $fixture) {
            $filters[(string) $name] = self::filter((string) $fixture);
        }
        $db = new Database($adapter, $cache, $filters);
        $authorization = new Authorization();
        foreach (Values::list($a['roles'] ?? ['any']) as $role) {
            $authorization->addRole((string) $role);
        }
        $db->setAuthorization($authorization);
        if (!isset($a['adapter'])) {
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
        if (!isset($a['adapter']) || \array_key_exists('namespace', $a)) {
            $db->setNamespace((string) ($a['namespace'] ?? 'compat'));
        }
        if ($pdo !== null) {
            $state->pdos[$db] = $pdo;
        }
        // A peer (on another database's adapter) is only used through `db`.
        if (!isset($a['adapter'])) {
            $state->db = $db;
        }
        if (!isset($a['adapter']) && ($a['create'] ?? true)) {
            $db->create();
        }

        return $s->handle($db);
    }

    public static function cache(mixed $kind): Cache
    {
        return match ($kind) {
            'memory' => new Cache(new Memory()),
            'none' => new Cache(new NoneCache()),
            default => throw new Fault('cache must be "memory" or "none"'),
        };
    }

    /**
     * Calls `$method` of `$target` with named arguments decoded for its
     * parameters, and encodes the result. Callbacks passed as `{"steps": [...]}`
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
        foreach ($a as $key => $value) {
            if ($key === 'db') {
                continue;
            }
            $p = $params[$key] ?? throw new Fault("{$class}::{$method} has no parameter `\${$key}`");
            if (self::accepts($p, 'callable') && $value !== null) {
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
        $result = \is_object($target) && !$reflection->isStatic()
            ? $target->{$method}(...$named)
            : $class::{$method}(...$named);
        if ($result instanceof \Generator) {
            $result = iterator_to_array($result, false);
        }
        if (!$hasCallbacks) {
            return $result;
        }

        return new Called($result, $calls);
    }

    private static function accepts(\ReflectionParameter $p, string $type): bool
    {
        $t = $p->getType();
        $names = match (true) {
            $t instanceof \ReflectionNamedType => [$t->getName()],
            $t instanceof \ReflectionUnionType => array_map(fn ($n) => (string) $n, $t->getTypes()),
            default => [],
        };

        return \in_array($type, $names, true);
    }

    /**
     * Whether the method's docblock declares the parameter `array<Document>`
     * (its items may then be plain arrays).
     */
    private static function documentList(\ReflectionParameter $p): bool
    {
        $doc = (string) $p->getDeclaringFunction()->getDocComment();

        return str_contains($doc, 'array<Document> $' . $p->getName())
            || str_contains($doc, 'array<Document>|null $' . $p->getName());
    }

    private static function argument(\ReflectionParameter $p, mixed $value, Session $s): mixed
    {
        $value = self::tags($value);
        if ($value === null) {
            return null;
        }
        if (self::accepts($p, Document::class)) {
            return Values::document($value);
        }
        if (self::accepts($p, Query::class)) {
            return Values::queries([$value])[0];
        }
        if (self::accepts($p, \DateTime::class)) {
            return $value instanceof \DateTime ? $value : new \DateTime((string) $value);
        }
        if (self::accepts($p, Cache::class)) {
            return self::cache($value);
        }
        if (self::accepts($p, Authorization::class)) {
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

            return $authorization;
        }
        if ($p->getName() === 'queries' && self::accepts($p, 'array')) {
            return Values::queries($value);
        }
        if (self::accepts($p, 'array') && self::documentList($p)) {
            return Values::documents($value);
        }
        if (self::accepts($p, Adapter::class) || self::accepts($p, Database::class)) {
            $db = $s->get($value);
            if (!$db instanceof Database) {
                throw new Fault("`\${$p->getName()}` takes a database handle");
            }

            return self::accepts($p, Database::class) ? $db : $db->getAdapter();
        }

        return Values::decode($value);
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
            // document after the call (iterate() encodes its last document in
            // place as the next page's cursor), which a Rust callback, given
            // its own copy, never sees.
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
     * A library value as it crosses the wire: Values tags, plus the objects a
     * Database hands out (itself for fluent setters, its adapter, cache and
     * authorization) as markers, generators as lists and DateTimes as tags.
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
            $value instanceof Adapter => ['$adapter' => (new \ReflectionClass($value))->getShortName()],
            $value instanceof Cache => ['$cache' => true],
            $value instanceof Authorization => ['$authorization' => true],
            $value instanceof \DateTimeInterface => ['$datetime' => $value->format('Y-m-d\TH:i:s.uP')],
            $value instanceof \Generator => self::result(iterator_to_array($value, false)),
            $value instanceof \Closure => ['$closure' => true],
            // onError callbacks receive the exception.
            $value instanceof \Throwable => ['$error' => ['class' => $value::class, 'message' => $value->getMessage()]],
            \is_array($value) => array_map(self::result(...), $value),
            default => Values::encode($value),
        };
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
     * The named filters of the e2e suite (Database::addFilter), ported from
     * the tests: methods of FilterFixtures, so their signatures (which cache
     * keys carry) are `Class::method` rather than a closure's `file:line`,
     * which every edit of this file would move.
     *
     * @return array{encode: callable, decode: callable}
     */
    public static function filter(string $name): array
    {
        $prefix = match ($name) {
            'encrypt' => 'encrypt',
            'subQueryProbe' => 'subQueryProbe',
            'operator_double_decode' => 'operatorDoubleDecode',
            default => throw new Fault("no filter fixture `{$name}`"),
        };

        return ['encode' => [FilterFixtures::class, "{$prefix}Encode"], 'decode' => [FilterFixtures::class, "{$prefix}Decode"]];
    }

    /**
     * The named Structure formats of the e2e suite (Structure::addFormat).
     *
     * @return array{callback: callable, type: string}
     */
    public static function format(string $name): array
    {
        return match ($name) {
            // AttributeTests::testUpdateAttributeFormat / testUpdateAttributeStructure
            'priceRange', 'priceRangeNew' => [
                'callback' => fn ($attribute) => new Range($attribute['formatOptions']['min'], $attribute['formatOptions']['max']),
                'type' => Database::VAR_INTEGER,
            ],
            default => throw new Fault("no format fixture `{$name}`"),
        };
    }

    /**
     * A query transformation (Database::before) from its description:
     * `{"append": "<sql>"}` appends to the statement; every result is logged
     * (`{"transform", "name", "sql"}`).
     */
    public static function transform(string $event, string $name, mixed $spec): ?callable
    {
        if ($spec === null) {
            return null;
        }
        if (!\is_array($spec)) {
            throw new Fault('a transformation is null or {"append": "..."}');
        }
        $append = (string) ($spec['append'] ?? '');

        return function (string $sql) use ($event, $name, $append) {
            $sql .= $append;
            Core::log(['transform' => $event, 'name' => $name, 'sql' => $sql]);

            return $sql;
        };
    }

    /**
     * A listener (Database::on) logging each event: `{"event", "listener", "data"}`.
     */
    public static function listener(string $name, mixed $spec): ?callable
    {
        if ($spec === null || $spec === false) {
            return null;
        }

        return function (string $event, mixed $data = null) use ($name) {
            Core::log(['event' => $event, 'listener' => $name, 'data' => Core::result($data)]);
        };
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
        $at = 0;
        while (($at = strpos($sql, '".', $at)) !== false) {
            $start = strrpos(substr($sql, 0, $at), '"');
            $name = $start === false ? '' : substr($sql, $start + 1, $at - $start - 1);
            if ($name !== $schema) {
                throw new Fault("db.sql: only schema `{$schema}` may be named, not `{$name}`");
            }
            $at += 2;
        }
        $pdo = self::state($s)->pdos[$db] ?? throw new Fault('db.sql: this database has no connection of its own');
        $statement = $pdo->query($sql);

        return $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}

/**
 * The filter fixtures of the e2e suite (Core::filter). Each call is logged
 * (`{"filter", "call"}`) for `db.log`.
 */
final class FilterFixtures
{
    // AttributeTests::testEncryptAttributes
    public static function encryptEncode(mixed $value): mixed
    {
        Core::log(['filter' => 'encrypt', 'call' => 'encode']);

        return json_encode(['data' => base64_encode($value), 'method' => 'base64', 'version' => 'v1']);
    }

    public static function encryptDecode(mixed $value): mixed
    {
        Core::log(['filter' => 'encrypt', 'call' => 'decode']);
        if (\is_null($value)) {
            return null;
        }
        $value = json_decode($value, true);

        return base64_decode($value['data']);
    }

    // AttributeTests::testFilterNotAppliedWhenAttributeNotSelected
    public static function subQueryProbeEncode(mixed $value): mixed
    {
        Core::log(['filter' => 'subQueryProbe', 'call' => 'encode']);

        return null;
    }

    public static function subQueryProbeDecode(mixed $value): mixed
    {
        Core::log(['filter' => 'subQueryProbe', 'call' => 'decode']);

        return ['fanned', 'out'];
    }

    // OperatorTests::testOperatorDoesNotDoubleDecode...
    public static function operatorDoubleDecodeEncode(mixed $value): mixed
    {
        Core::log(['filter' => 'operator_double_decode', 'call' => 'encode']);

        return json_encode(['data' => base64_encode((string) $value)]);
    }

    public static function operatorDoubleDecodeDecode(mixed $value): mixed
    {
        Core::log(['filter' => 'operator_double_decode', 'call' => 'decode']);
        if (\is_null($value)) {
            return null;
        }
        $decoded = json_decode($value, true);

        return base64_decode($decoded['data']);
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
    'db.add_format' => function (array $a, Session $s) {
        $name = (string) ($a['name'] ?? '');
        $format = Core::format($name);
        Structure::addFormat($name, $format['callback'], $format['type']);

        return true;
    },
    'database.add_filter' => function (array $a, Session $s) {
        $name = (string) ($a['name'] ?? '');
        $filter = Core::filter($name);
        Database::addFilter($name, $filter['encode'], $filter['decode']);

        return;
    },
    'database.on' => fn (array $a, Session $s) => Core::db($a, $s)->on((string) ($a['event'] ?? ''), (string) ($a['name'] ?? ''), Core::listener((string) ($a['name'] ?? ''), $a['callback'] ?? null)),
    'database.before' => fn (array $a, Session $s) => Core::db($a, $s)->before((string) ($a['event'] ?? ''), (string) ($a['name'] ?? ''), Core::transform((string) ($a['event'] ?? ''), (string) ($a['name'] ?? ''), $a['callback'] ?? null)),
];

// database.<snake_case>: every other public method of Database, by reflection.
foreach ((new \ReflectionClass(Database::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->isStatic() || $method->isConstructor() || $method->getDeclaringClass()->getName() !== Database::class) {
        continue;
    }
    $name = $method->getName();
    $op = 'database.' . Core::snake($name);
    $raw[$op] ??= fn (array $a, Session $s) => Core::invoke(Core::db($a, $s), $name, $a, $s);
}

// db.authorization.<snake_case>: the database's Authorization.
foreach ((new \ReflectionClass(Authorization::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->isStatic() || $method->isConstructor()) {
        continue;
    }
    $name = $method->getName();
    $raw['db.authorization.' . Core::snake($name)] = fn (array $a, Session $s) => Core::invoke(Core::db($a, $s)->getAuthorization(), $name, $a, $s);
}

Core::$raw = $raw;

return array_map(
    fn (callable $fn) => function (array $a, Session $s) use ($fn) {
        Core::enter($s);

        return Core::result($fn($a, $s));
    },
    $raw,
);
