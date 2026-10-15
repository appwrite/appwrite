<?php

namespace Tests\Compat\Database\Recorder;

use Tests\Compat\Database\Called;
use Tests\Compat\Database\Core;
use Tests\Compat\Database\Invocation;
use Tests\Compat\Database\Values;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;

/**
 * One call the tests made on the Database, its adapter, its Authorization or
 * its Profiler, as a compat step: operation, named arguments (encoded before
 * the call) and result (encoded after it).
 */
final class Step
{
    /** @var array<string, mixed> */
    public array $args = [];

    public mixed $result = null;

    /** The exception the call threw, if it did. */
    public ?\Throwable $thrown = null;

    /** Raw result, while the step is in a callback frame (for return detection). */
    public mixed $raw = null;

    /** @var array<string, list<array{invocation: Invocation, steps: list<Step>}>> callbacks per parameter */
    public array $callbacks = [];

    /** @var list<string> collections the call reads or writes */
    public array $collections = [];

    public bool $write = false;

    /** Settings key (global state the call sets), or null. */
    public ?string $setting = null;

    /** Registers something process-wide (a filter, a format): every later case repeats it. */
    public bool $sticky = false;

    /** The log (events, filter calls, transformations) grew during the call. */
    public bool $logged = false;

    /** Set on steps of a callback frame: an error it threw did not leave the callback. */
    public bool $catch = false;

    /** Set on a step of a callback frame whose result the callback returned. */
    public bool $return = false;

    /** Global position (top-level steps only). */
    public int $index = -1;

    /** Test the step belongs to (-1: setUp/tearDown, between tests). */
    public int $test = -1;

    /** @var list<string> stored objects (`$obj`) the step defines, in its arguments or with `as` */
    public array $defines = [];

    /** It deletes the suite's own database: what earlier steps wrote is gone. */
    public bool $resets = false;

    public function __construct(public string $op)
    {
    }
}

/**
 * Records the calls test code makes, at depth 0 only: the library's own
 * calls to its public methods (and to its adapter and authorization) happen
 * at depth > 0 and are not steps. A callback the library runs opens a frame
 * of its own at depth 0, whose steps become the callback's `steps`.
 */
final class Recorder
{
    public static bool $on = false;

    /** The suite's database (`{"$ref": "db"}` in a case). */
    public static ?Database $main = null;

    /** @var array<string, Database> other databases the cases bind as they open (a mirror's `src` and `dst`) */
    public static array $parts = [];

    public static int $depth = 0;

    /** Test the steps belong to (-1 between tests). */
    public static int $test = -1;

    /** @var list<list<Step>> frames; the first is the global stream */
    private static array $frames = [[]];

    /** @var list<Step> every top-level step */
    public static array $stream = [];

    public static int $logCount = 0;

    /** Recorder's schema, replaced by `${ns}` in arguments. */
    public static string $schema = '';

    /** @var array<string, true> Structure formats already seen */
    private static array $formats = [];

    /** @var list<string> problems to report */
    public static array $problems = [];

    /**
     * Adapters the tests built themselves (`new Memory()` for a peer), by
     * object id: the step that opened the first database on each.
     *
     * @var array<int, int>
     */
    private static array $adapters = [];

    /** @var \WeakMap<object, string>|null objects stored under a name (`as`), referred to as `{"$obj": name}` */
    private static ?\WeakMap $objects = null;

    private static int $objectCount = 0;

    /** @var \WeakMap<\Utopia\Database\Profiler, RecordingProfiler>|null the wrapper handed out for each profiler */
    private static ?\WeakMap $profilers = null;

    /** @var \WeakMap<\Utopia\Database\Profiler, Database>|null the database each wrapped profiler belongs to */
    private static ?\WeakMap $profilerOwners = null;

    /** Known global filters of the tests (file:line of the encode callback => fixture). */
    private const FILTERS = [
        'AttributeTests.php:1485' => 'encrypt',
        'AttributeTests.php:1560' => 'subQueryProbe',
        'OperatorTests.php:566' => 'operator_double_decode',
        'OperatorTests.php:709' => 'encrypt_operator',
        'JoinTests.php:5854' => 'joinedSeal',
    ];

    private const READS = ['get', 'find', 'count', 'sum', 'exists', 'list', 'is', 'has', 'ping', 'encode', 'decode', 'casting', 'convert', 'check', 'cursor', 'aggregate', 'in', 'profile', 'resolve', 'collection', 'internal', 'snapshot', 'from', 'schema', 'query', 'authorize', 'capabilities', 'supports', 'limits'];

    private const WRAPPERS = ['with', 'skip', 'silent', 'ignore'];

    /** Methods whose first parameter names a database (schema). */
    private const SCHEMA_NAMES = ['setDatabase', 'exists', 'create', 'delete', 'update'];

    /**
     * Prefixes the database (schema) names the tests use besides the
     * suite's own (`sharedTables`, `schema1`, `hellodb`, ...) with the
     * recorder's schema, so they record as `${ns}_<name>`: each runtime's
     * run then works in schemas of its own, which the runner's cleanup of
     * `${ns}` drops with it.
     *
     * @param list<mixed> $positional
     * @return list<mixed>
     */
    private static function isolate(string $kind, string $method, array $positional): array
    {
        if (!\in_array($kind, ['database', 'adapter'], true) || !\in_array($method, [...self::SCHEMA_NAMES, 'collectionExists'], true)) {
            return $positional;
        }
        // collectionExists(collection, ?database) names the database second
        // on Database, first on the adapter; update(database, new) both.
        $at = match (true) {
            $method === 'collectionExists' && $kind === 'database' => [1],
            $method === 'collectionExists' => [0],
            $method === 'update' => [0, 1],
            default => [0],
        };
        foreach ($at as $i) {
            $name = $positional[$i] ?? null;
            if (\is_string($name) && $name !== '' && $name !== self::$schema && !str_starts_with($name, self::$schema . '_')) {
                $positional[$i] = self::$schema . '_' . $name;
            }
        }

        return $positional;
    }

    /**
     * Drops what the steps of a finished test kept only while it ran (their
     * raw results), to bound a long recording's memory.
     */
    public static function release(int $test): void
    {
        for ($i = \count(self::$stream) - 1; $i >= 0 && self::$stream[$i]->test === $test; $i--) {
            self::forget(self::$stream[$i]);
        }
    }

    private static function forget(Step $step): void
    {
        $step->raw = null;
        $step->thrown = null;
        foreach ($step->callbacks as $invocations) {
            foreach ($invocations as $invocation) {
                foreach ($invocation['steps'] as $nested) {
                    self::forget($nested);
                }
            }
        }
    }

    public static function log(): void
    {
        self::$logCount++;
    }

    /** @var array<int, int> the test that registered each hook of the suite (by object id) */
    private static array $hookTests = [];

    /**
     * A call of a hook of the suite: logged while the test that registered
     * it runs (its later calls are not part of other tests' cases).
     */
    public static function logHook(object $hook): void
    {
        if ((self::$hookTests[spl_object_id($hook)] ?? null) === self::$test) {
            self::$logCount++;
        }
    }

    /**
     * A call of `$method` on `$target` (kind database|adapter|authorization|profiler).
     *
     * @param list<mixed> $positional
     */
    public static function call(object $target, string $kind, string $method, array $positional, \Closure $parent): mixed
    {
        if (!self::$on || self::$depth > 0) {
            return self::wrapResult($kind, $method, $target, $parent(...$positional));
        }
        $positional = self::isolate($kind, $method, $positional);
        self::formats();
        if ($method === 'addHook' && \is_object($positional[0] ?? null)) {
            self::$hookTests[spl_object_id($positional[0])] = self::$test;
        }
        $reflection = new \ReflectionMethod($kind === 'profiler' ? \Utopia\Database\Profiler::class : get_parent_class($target), $method);
        $params = $reflection->getParameters();
        $named = [];
        $step = new Step(match ($kind) {
            'database' => 'database.' . Core::snake($method),
            'adapter' => 'db.adapter',
            'profiler' => 'db.profiler',
            default => 'db.authorization.' . Core::snake($method),
        });
        foreach ($params as $i => $p) {
            if (!\array_key_exists($i, $positional)) {
                break;
            }
            $value = $positional[$i];
            if ($p->isDefaultValueAvailable() && $value === $p->getDefaultValue()) {
                continue;
            }
            if (self::callable($p) && ($value instanceof \Closure || (\is_callable($value) && !\is_string($value)))) {
                $step->callbacks[$p->getName()] = [];
                $positional[$i] = self::wrap($step, $p->getName(), $value);
                $named[$p->getName()] = true; // replaced by the steps at the end
                continue;
            }
            $named[$p->getName()] = $value;
        }
        $encoded = [];
        foreach ($named as $key => $value) {
            $encoded[$key] = $step->callbacks !== [] && \array_key_exists($key, $step->callbacks) ? true : self::encode($value, $step);
        }
        if ($kind === 'database' && $target !== self::$main) {
            $encoded = ['db' => self::encode($target)] + $encoded;
        }
        $step->args = \in_array($kind, ['adapter', 'profiler'], true) ? ['method' => $method, 'args' => $encoded === [] ? new \stdClass() : $encoded] : $encoded;
        if ($kind === 'adapter' && $target !== self::$main?->getAdapter()) {
            // An adapter the test built itself (`new Memory()`): through the
            // detached database `db.open` made for it.
            $step->args = ['db' => self::encode($target)] + $step->args;
        }
        if ($kind === 'profiler' && $target instanceof RecordingProfiler && (self::$profilerOwners[$target->inner] ?? self::$main) !== self::$main) {
            $step->args = ['db' => self::encode(self::$profilerOwners[$target->inner])] + $step->args;
        }
        $step->collections = self::collections($method, $named);
        $step->write = self::writes($kind, $method);
        if ($kind === 'database' && $method === 'delete' && $target === self::$main) {
            self::$depth++;
            try {
                $step->resets = ($named['database'] ?? $target->getDatabase()) === self::$schema;
            } finally {
                self::$depth--;
            }
        }
        // A peer's settings are its own: they are restored by replaying its steps.
        $step->setting = $kind === 'database' && $target !== self::$main ? null : self::setting($kind, $method, $named);
        self::push($step);
        $before = self::$logCount;
        self::$depth++;
        try {
            $result = $parent(...$positional);
            if ($result instanceof \Generator) {
                $list = iterator_to_array($result, false);
                $result = (fn () => yield from $list)();
                $value = $list;
            } else {
                $value = $result;
            }
            $result = self::wrapResult($kind, $method, $target, $result);
            if (!$result instanceof \Generator) {
                $value = $result;
            }
            $step->raw = $value;
            $step->result = Core::result($step->callbacks === [] ? $value : new Called($value, self::invocations($step)));
            if ($step->setting !== null && $value instanceof Database) {
                $step->result = ['$database' => true];
            }
            // An object the tests may hand back later (a cache, a hook):
            // stored under a name.
            if (\is_object($value) && self::storable($value)) {
                $name = self::name($value);
                if ($name === null) {
                    $name = self::assign($value);
                    $step->defines[] = $name;
                    if (\in_array($kind, ['adapter', 'profiler'], true)) {
                        $step->args['as'] = $name;
                    } else {
                        $step->args = $step->args + ['as' => $name];
                    }
                }
            }

            return $result;
        } catch (\Throwable $error) {
            $step->thrown = $error;
            $step->result = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
            throw $error;
        } finally {
            self::$depth--;
            $step->logged = self::$logCount > $before;
            foreach ($step->callbacks as $invocations) {
                foreach ($invocations as $invocation) {
                    foreach ($invocation['steps'] as $nested) {
                        $step->collections = array_values(array_unique([...$step->collections, ...$nested->collections]));
                        $step->write = $step->write || $nested->write;
                        $step->logged = $step->logged || $nested->logged;
                        $step->defines = [...$step->defines, ...$nested->defines];
                    }
                }
            }
        }
    }

    /**
     * What a call returns to the tests: the Profiler a database hands out
     * wrapped, so its calls are recorded.
     */
    private static function wrapResult(string $kind, string $method, object $target, mixed $result): mixed
    {
        if ($kind === 'database' && $method === 'getProfiler' && $result instanceof \Utopia\Database\Profiler && !$result instanceof RecordingProfiler) {
            self::$profilers ??= new \WeakMap();
            self::$profilerOwners ??= new \WeakMap();
            self::$profilerOwners[$result] = $target;

            return self::$profilers[$result] ??= new RecordingProfiler($result);
        }

        return $result;
    }

    /** Whether an object a step returned can be handed back to the library by the tests. */
    private static function storable(object $value): bool
    {
        return $value instanceof \Utopia\Cache\Cache
            || $value instanceof \Utopia\Database\Cache\Query
            || $value instanceof \Utopia\Database\Filter\Registry
            || ($value instanceof \Utopia\Query\Hook && !$value instanceof Query);
    }

    private static function name(object $value): ?string
    {
        return self::$objects[$value] ?? null;
    }

    private static function assign(object $value): string
    {
        self::$objects ??= new \WeakMap();
        $name = 'o' . ++self::$objectCount;
        self::$objects[$value] = $name;

        return $name;
    }

    /**
     * @return array<string, list<Invocation>>
     */
    private static function invocations(Step $step): array
    {
        $out = [];
        foreach ($step->callbacks as $param => $invocations) {
            $out[$param] = array_map(fn (array $i) => $i['invocation'], $invocations);
        }

        return $out;
    }

    private static function callable(\ReflectionParameter $p): bool
    {
        $t = $p->getType();
        if ($t === null) {
            return false;
        }

        return str_contains((string) $t, 'callable') || str_contains((string) $t, 'Closure');
    }

    /**
     * The callback handed to the library in place of the test's: runs it in
     * a frame of its own, at depth 0.
     */
    private static function wrap(Step $step, string $param, callable $callback): \Closure
    {
        return function (mixed ...$args) use ($step, $param, $callback) {
            $invocation = new Invocation(array_map(Core::result(...), array_values($args)));
            $at = \count($step->callbacks[$param]);
            $step->callbacks[$param][] = ['invocation' => $invocation, 'steps' => []];
            self::$frames[] = [];
            $depth = self::$depth;
            self::$depth = 0;
            $frame = [];
            try {
                $returned = $callback(...$args);
                $frame = self::$frames[\count(self::$frames) - 1];
                foreach ($frame as $nested) {
                    $nested->catch = $nested->thrown !== null;
                }
                if ($returned !== null) {
                    $match = null;
                    foreach ($frame as $nested) {
                        if ($nested->thrown === null && $nested->raw === $returned) {
                            $match = $nested;
                        }
                    }
                    if ($match !== null) {
                        $match->return = true;
                    } else {
                        // `$return` gives back a value: an object the test built (an
                        // Authorization, a hook, ...) would come back as its encoding.
                        if (\is_object($returned) && !$returned instanceof Document && !$returned instanceof Query && !$returned instanceof Operator
                            && !$returned instanceof \UnitEnum && !$returned instanceof \DateTimeInterface && !$returned instanceof \stdClass) {
                            self::problem('a callback returns an object of type ' . $returned::class . ' that no step returned');
                        }
                        $pseudo = new Step('$return');
                        $pseudo->args = ['value' => self::encode($returned)];
                        $frame[] = $pseudo;
                    }
                }

                return $returned;
            } catch (\Throwable $error) {
                $frame = self::$frames[\count(self::$frames) - 1];
                $last = $frame === [] ? null : $frame[\count($frame) - 1];
                foreach ($frame as $nested) {
                    $nested->catch = $nested->thrown !== null && $nested->thrown !== $error;
                }
                if ($last === null || $last->thrown !== $error) {
                    $pseudo = new Step('$throw');
                    $pseudo->args = ['class' => $error::class, 'message' => $error->getMessage()];
                    $frame[] = $pseudo;
                }
                throw $error;
            } finally {
                array_pop(self::$frames);
                self::$depth = $depth;
                $step->callbacks[$param][$at]['steps'] = $frame;
                $invocation->steps = array_map(fn (Step $s) => str_starts_with($s->op, '$') ? null : $s->result, $frame);
            }
        };
    }

    private static function push(Step $step): void
    {
        $top = \count(self::$frames) - 1;
        self::$frames[$top][] = $step;
        if ($top === 0) {
            $step->index = \count(self::$stream);
            $step->test = self::$test;
            self::$stream[] = $step;
        }
    }

    /**
     * Steps for Structure formats the tests registered since the last call
     * (Structure::addFormat is static and called directly).
     */
    public static function formats(): void
    {
        $formats = (new \ReflectionProperty(Structure::class, 'formats'))->getValue();
        // A format removed since is seen anew when registered again.
        self::$formats = array_intersect_key(self::$formats, $formats);
        foreach (array_keys($formats) as $name) {
            if (isset(self::$formats[$name])) {
                continue;
            }
            self::$formats[$name] = true;
            if (!self::$on) {
                continue;
            }
            $step = new Step('db.add_format');
            $step->args = ['name' => $name];
            $step->sticky = true;
            $step->result = true;
            self::push($step);
        }
    }

    /**
     * Database::addFilter from the tests: recorded by fixture (Fixtures.php
     * has them, identified by where the test declares the encode callback),
     * each call of the filter logged.
     */
    public static function addFilter(string $name, callable $encode, callable $decode, \Closure $parent): void
    {
        $logged = fn (callable $fn) => function (mixed ...$args) use ($fn) {
            self::log();

            return $fn(...$args);
        };
        if (self::$on && self::$depth === 0) {
            $r = new \ReflectionFunction(\Closure::fromCallable($encode));
            $key = basename((string) $r->getFileName()) . ':' . $r->getStartLine();
            $fixture = self::FILTERS[$key] ?? null;
            if ($fixture === null) {
                self::$problems[] = "unknown filter {$name} at {$key}";
                $fixture = $name;
            }
            $step = new Step('database.add_filter');
            $step->args = $fixture === $name ? ['name' => $name] : ['name' => $name, 'fixture' => $fixture];
            $step->sticky = true;
            $step->result = null;
            self::push($step);
        }
        $parent($name, $logged($encode), $logged($decode));
    }

    /**
     * A raw statement the tests ran (deleteColumn/deleteIndex helpers), as `db.sql`.
     *
     * @param list<string> $collections
     */
    public static function sql(\Utopia\Database\PDO $pdo, string $sql, array $collections, ?string $db = null): void
    {
        $step = new Step('db.sql');
        $step->args = $db === null ? ['sql' => $sql] : ['db' => ['$ref' => $db], 'sql' => $sql];
        $step->collections = $collections;
        $step->write = true;
        self::push($step);
        try {
            $pdo->exec($sql);
            $step->result = [];
        } catch (\Throwable $error) {
            $step->result = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $named
     * @return list<string>
     */
    private static function collections(string $method, array $named): array
    {
        $out = [];
        foreach ($named as $key => $value) {
            $is = \in_array($key, ['collection', 'collectionId', 'relatedCollection'], true)
                || ($key === 'id' && str_ends_with($method, 'Collection'));
            if (!$is) {
                continue;
            }
            if ($value instanceof Document) {
                $value = $value->getId();
            }
            if (\is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }
        // A relationship names its related collection.
        foreach ($named as $value) {
            if ($value instanceof \Utopia\Database\Relationship) {
                $out[] = $value->relatedCollection;
            }
        }

        return array_values(array_unique($out));
    }

    private static function writes(string $kind, string $method): bool
    {
        foreach (self::WRAPPERS as $prefix) {
            if (str_starts_with($method, $prefix)) {
                return false;
            }
        }
        if ($kind === 'profiler') {
            return false;
        }
        if ($kind === 'authorization') {
            return !\in_array($method, ['getRoles', 'hasRole', 'getStatus', 'isValid', 'getDescription', 'isArray', 'getType'], true);
        }
        foreach (self::READS as $prefix) {
            if (str_starts_with($method, $prefix) && ($method === $prefix || ctype_upper($method[\strlen($prefix)]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The global state a call sets (a key; the last call per key restores
     * it), for database and authorization settings.
     *
     * @param array<string, mixed> $named
     */
    private static function setting(string $kind, string $method, array $named): ?string
    {
        if ($kind === 'adapter' || $kind === 'profiler') {
            return null;
        }
        if ($kind === 'authorization') {
            if (!self::writes($kind, $method) || $method === 'skip') {
                return null;
            }

            return "authorization.{$method}:" . (string) ($named['role'] ?? '');
        }
        $settings = [
            'setNamespace' => null, 'setDatabase' => null, 'setCacheName' => null, 'setMetadata' => 'key',
            'resetMetadata' => null, 'setTimeout' => 'event', 'clearTimeout' => 'event', 'setFiltering' => null,
            'setValidation' => null, 'setSharedTables' => null, 'setTenant' => null, 'setTenantPerDocument' => null,
            'setLocks' => null, 'setDocumentType' => 'collection', 'clearDocumentType' => 'collection',
            'clearDocumentTypes' => null, 'setDropUnknownAttributes' => null, 'setPreserveDates' => null,
            'setMigrating' => null, 'setPreserveSequence' => null, 'setMaxQueryValues' => null,
            'setGlobalCollections' => null, 'resetGlobalCollections' => null, 'setCache' => null,
            'setAuthorization' => null, 'setCheckGrants' => null, 'setSchemaless' => null, 'setProfiling' => null,
            'setQueryCache' => null, 'setFilters' => null, 'setCacheWriterTimeout' => null, 'addHook' => null,
            'removeHook' => null, 'removeTransform' => null,
        ];
        if (!\array_key_exists($method, $settings)) {
            return null;
        }
        $key = $settings[$method];
        $value = $key === null ? null : ($named[$key] ?? null);
        $suffix = $key === null ? '' : ':' . (\is_scalar($value) ? (string) $value : ($value instanceof \BackedEnum ? $value->value : ''));
        // Setters and their resets share a key, so only the last one counts.
        $group = match ($method) {
            'setMetadata', 'resetMetadata' => 'metadata',
            'setTimeout', 'clearTimeout' => 'timeout',
            'setGlobalCollections', 'resetGlobalCollections' => 'globalCollections',
            'addHook', 'removeHook', 'removeTransform' => 'hooks',
            default => $method,
        };

        return $group . $suffix;
    }

    /**
     * A value as a step argument: Values tags, DateTimes as `$datetime`,
     * datetimes near now (as objects or strings) relative to the clock, and
     * the objects the library takes as code or by identity (hooks, caches,
     * query caches, filter registries) as fixtures or `{"$obj": <name>}`.
     */
    public static function encode(mixed $value, ?Step $step = null): mixed
    {
        if (\is_object($value) && ($name = self::name($value)) !== null) {
            return ['$obj' => $name];
        }
        if (\is_resource($value)) {
            self::problem('cannot encode a resource');

            return ['$unsupported' => 'resource'];
        }
        if ((\is_array($value) || $value instanceof \Utopia\Query\Query) && $step !== null && self::oversized($value)) {
            return self::tooLarge();
        }

        return match (true) {
            $value instanceof Document => self::document($value, $step),
            $value instanceof Query => ['$query' => self::query($value, $step)],
            $value instanceof Operator => ['$operator' => ['method' => $value->getMethod()->value, 'attribute' => $value->getAttribute(), 'values' => self::encode($value->getValues(), $step)]],
            $value instanceof \DateTimeInterface => ['$datetime' => self::relative($value) ?? $value->format('Y-m-d\TH:i:s.uP')],
            $value instanceof \UnitEnum => Values::encode($value),
            $value instanceof \stdClass => (array) $value === [] ? $value : ['$object' => self::encode((array) $value, $step)],
            $value instanceof \MongoDB\BSON\Type => Values::encode($value),
            $value instanceof PeerDatabase && $value->opened !== null => ['$ref' => "s{$value->opened}"],
            self::$main !== null && $value === self::$main => ['$ref' => 'db'],
            $value instanceof Database && \in_array($value, self::$parts, true) => ['$ref' => (string) array_search($value, self::$parts, true)],
            $value instanceof \Utopia\Database\Adapter && $value === self::$main?->getAdapter() => ['$ref' => 'db'],
            $value instanceof \Utopia\Database\Adapter && isset(self::$adapters[spl_object_id($value)]) => ['$ref' => 's' . self::$adapters[spl_object_id($value)]],
            $value instanceof Authorization && $value === self::$main?->getAuthorization() => ['$ref' => 'db'],
            $value instanceof Authorization => ['roles' => $value->getRoles()],
            $value instanceof \Utopia\Cache\Cache => self::cache($value),
            $value instanceof \Utopia\Database\Cache\Query => self::queryCache($value, $step),
            $value instanceof \Utopia\Query\Hook => self::hook($value, $step),
            $value instanceof \Utopia\Database\Filter\Registry => self::unsupported($value),
            $value instanceof \Utopia\Database\Filter\Codec && str_starts_with($value::class, 'Tests\\') => self::codec($value, $step),
            $value instanceof \Utopia\Query\Builder\Statement => ['$value' => 'Query\\Builder\\Statement', 'fields' => ['query' => self::encode($value->query, $step), 'bindings' => self::encode($value->bindings, $step)]],
            \is_string($value) && \strlen($value) > 65536 => self::repeat($value),
            \is_array($value) => array_map(fn ($v) => self::encode($v, $step), $value),
            \is_string($value) => self::now($value) ?? $value,
            $value instanceof \PDO || $value instanceof \Utopia\Database\PDO => isset(self::$pdos[$value]) ? ['$pdo' => self::$pdos[$value]] : self::unsupported($value),
            $value instanceof \Throwable => ['$throwable' => self::throwable($value)],
            \is_object($value) && Values::shortName($value::class) !== null && self::plain($value) => self::valueObject($value, $step),
            \is_object($value) => self::unsupported($value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(Document $value, ?Step $step): array
    {
        $out = ['$document' => $value->isEmpty() ? new \stdClass() : self::encode($value->getArrayCopy(), $step)];
        if ($value::class !== Document::class) {
            $out['$class'] = Values::shortName($value::class) ?? $value::class;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(Query $value, ?Step $step): array
    {
        $out = ['method' => $value->getMethod()->value, 'attribute' => $value->getAttribute(), 'values' => self::encode($value->getValues(), $step)];
        if ($value->getAlias() !== '') {
            $out['alias'] = $value->getAlias();
        }

        return $out;
    }

    /**
     * A value object of the library as Values encodes it, with its nested
     * values encoded as arguments (datetimes near now relative, ...).
     *
     * @return array<string, mixed>
     */
    private static function valueObject(object $value, ?Step $step): array
    {
        $fields = [];
        for ($class = new \ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            $own = [];
            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                if ($p->isStatic() || $p->getDeclaringClass()->getName() !== $class->getName() || !$p->isInitialized($value)) {
                    continue;
                }
                $own[$p->getName()] = self::encode($p->getValue($value), $step);
            }
            $fields = $own + $fields;
        }

        return ['$value' => Values::shortName($value::class), 'fields' => $fields === [] ? new \stdClass() : $fields];
    }

    /**
     * A hook as its fixture (Fixtures.php), stored under a name the first
     * time so later steps can hand back the same instance.
     *
     * @return array<string, mixed>
     */
    private static function hook(\Utopia\Query\Hook $hook, ?Step $step): array
    {
        $spec = self::hookSpec($hook);
        if (!\is_array($spec)) {
            self::problem("cannot encode the hook {$spec}");
            self::$problems[] = "cannot encode the hook {$spec}";

            return ['$unsupported' => $spec];
        }
        $name = self::assign($hook);
        if ($step !== null) {
            $step->defines[] = $name;
        }
        $spec['as'] = $name;

        return $spec;
    }

    /**
     * A hook's fixture spec (Fixtures.php: Hooks), or its class when it has none.
     *
     * @return array<string, mixed>|string
     */
    public static function hookSpec(object $hook): array|string
    {
        $prop = static function (object $object, string $name): mixed {
            for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
                if ($class->hasProperty($name)) {
                    return $class->getProperty($name)->getValue($object);
                }
            }

            return null;
        };
        $class = $hook::class;
        $file = (string) (new \ReflectionClass($hook))->getFileName();
        $spec = match (true) {
            $hook instanceof \Utopia\Database\Hook\Relationships => $prop($hook, 'prepare') === false ? ['$hook' => 'Relationships', 'prepare' => false] : ['$hook' => 'Relationships'],
            $hook instanceof \Utopia\Database\Hook\Permissions => ['$hook' => 'Permissions'],
            $hook instanceof \Utopia\Database\Hook\Tenancy => ['$hook' => 'Tenancy'],
            $class === 'Tests\\E2E\\Adapter\\Support\\EventRecorder' => ['$hook' => 'Log', 'name' => $prop($hook, 'name')],
            $hook instanceof \Utopia\Database\Hook\Transform && str_contains($file, 'CollectionTests.php') => ['$hook' => 'Transform', 'table' => self::encode($prop($hook, 'table')), 'event' => 'document_read', 'append' => ' AND 1 = 0'],
            $hook instanceof \Utopia\Database\Hook\Nested && str_contains($file, 'NestedPreparationTests.php') => ['$hook' => 'Nested', 'marker' => $prop($hook, 'marker')],
            default => null,
        };

        return $spec ?? $class;
    }

    /**
     * A codec of the unit tests as its fixture (Fixtures.php: Codecs), stored
     * under a name the first time.
     *
     * @return array<string, mixed>
     */
    private static function codec(\Utopia\Database\Filter\Codec $codec, ?Step $step): array
    {
        $prop = static fn (string $name) => (new \ReflectionProperty($codec, $name))->getValue($codec);
        $spec = match ($codec::class) {
            'Tests\\Unit\\Filter\\Reversed' => ['$codec' => 'reversed', 'name' => $prop('name')],
            'Tests\\Unit\\Filter\\Rot13' => ['$codec' => 'rot13', 'name' => $prop('name')],
            'Tests\\Unit\\Filter\\CodecTestPrefixed' => ['$codec' => 'prefixed', 'prefix' => $prop('prefix')],
            default => null,
        };
        if ($spec === null) {
            return self::unsupported($codec);
        }
        $name = self::assign($codec);
        if ($step !== null) {
            $step->defines[] = $name;
        }
        $spec['as'] = $name;

        return $spec;
    }

    /**
     * A query cache (Cache\Query) as `{"cache": <kind>|"database", "slots"?}`.
     *
     * @return array<string, mixed>
     */
    private static function queryCache(\Utopia\Database\Cache\Query $cache, ?Step $step): array
    {
        $inner = (new \ReflectionProperty(\Utopia\Database\Cache\Query::class, 'cache'))->getValue($cache);
        $slots = (new \ReflectionProperty(\Utopia\Database\Cache\Query::class, 'slots'))->getValue($cache);
        $out = ['cache' => self::$main !== null && $inner === self::$main->getCache() ? 'database' : self::cache($inner)];
        if ($slots !== \Utopia\Database\Cache\Query::SLOTS) {
            $out['slots'] = $slots;
        }
        $name = self::assign($cache);
        if ($step !== null) {
            $step->defines[] = $name;
        }
        $out['as'] = $name;

        return $out;
    }

    /**
     * A second Database the tests built on the suite's adapter (a peer):
     * `db.open` with `adapter`, its calls then naming it with `db`.
     *
     * @param array<mixed> $filters
     */
    public static function peer(PeerDatabase $peer, \Utopia\Database\Adapter $adapter, \Utopia\Cache\Cache $cache, array $filters = []): void
    {
        if (!self::$on || self::$depth > 0) {
            return;
        }
        if ($filters !== []) {
            self::$problems[] = 'a peer database with instance filters';
        }
        $step = new Step('db.open');
        $step->args = ['adapter' => self::encode($adapter), 'cache' => self::encode($cache), 'hooks' => []];
        $step->result = ['$handle' => '*'];
        $step->write = true;
        self::push($step);
        $peer->opened = $step->index;
        self::$adapters[spl_object_id($adapter)] ??= $step->index;
    }

    /**
     * An adapter the test built itself (`new Memory()`, a PeerMemory): a
     * detached database on a fresh adapter of the engine (`db.open` with
     * `engine` and `detached`), its calls then naming it with `db`.
     */
    public static function freshAdapter(\Utopia\Database\Adapter $adapter, string $engine): void
    {
        if (!self::$on || self::$depth > 0) {
            return;
        }
        $step = new Step('db.open');
        $step->args = ['engine' => $engine, 'detached' => true, 'hooks' => []];
        $step->result = ['$handle' => '*'];
        $step->write = true;
        self::push($step);
        self::$adapters[spl_object_id($adapter)] = $step->index;
    }

    /** The most values a step's argument or result may hold (a query tree sharing its nodes grows exponentially). */
    private const MAX_VALUES = 200000;

    /**
     * Whether a value holds more than MAX_VALUES values once its shared
     * parts are counted at every place they appear.
     */
    private static function oversized(mixed $value): bool
    {
        $budget = self::MAX_VALUES;
        $walk = static function (mixed $v) use (&$walk, &$budget): bool {
            if (--$budget < 0) {
                return true;
            }
            $children = match (true) {
                \is_array($v) => $v,
                $v instanceof Document => $v->getArrayCopy(),
                $v instanceof \Utopia\Query\Query => $v->getValues(),
                $v instanceof Operator => $v->getValues(),
                default => [],
            };
            foreach ($children as $child) {
                if ($walk($child)) {
                    return true;
                }
            }

            return false;
        };

        return $walk($value);
    }

    /**
     * @return array{'$unsupported': string}
     */
    private static function tooLarge(): array
    {
        self::problem('a value of more than ' . self::MAX_VALUES . ' values');

        return ['$unsupported' => 'more than ' . self::MAX_VALUES . ' values'];
    }

    /**
     * An exception argument as Core::throwable() builds it back.
     *
     * @return array<string, mixed>
     */
    private static function throwable(\Throwable $error): array
    {
        if (str_contains($error::class, '@anonymous') || $error instanceof \PHPUnit\Framework\MockObject\MockObject) {
            self::problem('cannot encode an exception of type ' . $error::class);
        }
        $out = ['class' => $error::class, 'message' => $error->getMessage()];
        if ($error->getCode() !== 0) {
            $out['code'] = $error->getCode();
        }
        if ($error instanceof \PDOException && $error->errorInfo !== null) {
            $out['errorInfo'] = $error->errorInfo;
        }
        if ($error->getPrevious() !== null) {
            $out['previous'] = self::throwable($error->getPrevious());
        }

        return $out;
    }

    /**
     * Whether an object of the library is a value (every property public):
     * one a step can carry as a `$value` tag.
     */
    private static function plain(object $value): bool
    {
        for ($class = new \ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $p) {
                if (!$p->isStatic() && !$p->isPublic()) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function unsupported(object $value): array
    {
        self::problem('cannot encode an argument of type ' . $value::class);
        self::$problems[] = 'cannot encode an argument of type ' . $value::class;

        return ['$unsupported' => $value::class];
    }

    private static function cache(\Utopia\Cache\Cache $cache): mixed
    {
        $adapter = (new \ReflectionProperty(\Utopia\Cache\Cache::class, 'adapter'))->getValue($cache);
        if ($adapter instanceof \Utopia\Cache\Adapter\Redis) {
            $client = (new \ReflectionProperty(\Utopia\Cache\Adapter\Redis::class, 'redis'))->getValue($adapter);
            // A Redis client a test stubbed to fail every command.
            if ($client instanceof \PHPUnit\Framework\MockObject\Stub) {
                return 'unreachable';
            }
        }

        return match (true) {
            $adapter instanceof \Utopia\Cache\Adapter\Memory => 'memory',
            $adapter instanceof \Utopia\Cache\Adapter\None => 'none',
            isset(Core::$kinds[$cache]) => Core::$kinds[$cache],
            // The suite's other Redis caches (a test's own client): the
            // cases run them on a Memory cache, as for the suite's database.
            $adapter instanceof \Utopia\Cache\Adapter\Redis => 'memory',
            // A test's counting wrapper of a Redis cache.
            str_starts_with($adapter::class, 'Tests\\') => 'memory',
            default => self::unsupported($adapter),
        };
    }

    /**
     * A long string as `{"$repeat": <period>, "times": n}` when it repeats a
     * shorter text exactly (the suite's longtext.txt).
     */
    private static function repeat(string $value): mixed
    {
        $length = \strlen($value);
        for ($period = 1; $period <= 65536; $period++) {
            if ($length % $period !== 0) {
                continue;
            }
            $chunk = substr($value, 0, $period);
            if (substr_count($value, $chunk) * $period === $length && str_repeat($chunk, intdiv($length, $period)) === $value) {
                return ['$repeat' => $chunk, 'times' => intdiv($length, $period)];
            }
        }
        // Random filler (bin2hex(random_bytes(...))): the same length of a
        // periodic text made from its start, so a case stays small.
        $period = 1;
        for ($d = 32; $d <= 4096; $d++) {
            if ($length % $d === 0) {
                $period = $d;
                break;
            }
        }
        self::$problems[] = "a {$length}-byte argument that repeats nothing became a repetition of its first {$period} bytes";

        return ['$repeat' => substr($value, 0, $period), 'times' => intdiv($length, $period)];
    }

    // ----- Unit tests (units.php): what test code does with any object of the library -----

    /** @var \WeakMap<object, array<string, mixed>>|null the PDOs the tests built, as `$pdo` specs */
    private static ?\WeakMap $pdos = null;

    /** @var list<string> why the test recording now cannot be ported (it is left out when it has any) */
    public static array $testProblems = [];

    /**
     * Whether a value is an object of the library whose calls are recorded:
     * a class of Utopia\Database or Utopia\Query (an enum case too), not a
     * test's own subclass, an exception or a closure.
     */
    public static function library(mixed $value): bool
    {
        if (!\is_object($value) || $value instanceof \Throwable || $value instanceof \Closure || $value instanceof \Generator) {
            return false;
        }
        $class = $value::class;

        return !str_contains($class, '@anonymous') && Rewriter::library($class);
    }

    private static function problem(string $problem): void
    {
        if (self::$on && self::$test >= 0) {
            self::$testProblems[] = $problem;
        }
    }

    /**
     * Named arguments of a call from the test's (positional, named and
     * spread) ones, callables wrapped as callbacks of the step.
     *
     * @param array<int|string, mixed> $args
     * @return array{0: array<string, mixed>, 1: array<int|string, mixed>} the named ones and the arguments to call with
     */
    private static function named(?\ReflectionFunctionAbstract $function, array $args, Step $step): array
    {
        $params = $function?->getParameters() ?? [];
        $named = [];
        $call = [];
        $position = 0;
        foreach ($args as $key => $value) {
            if (\is_int($key)) {
                $p = $params[$position] ?? null;
                if ($p !== null && $p->isVariadic()) {
                    $named[$p->getName()][] = $value;
                    $call[] = $value;
                    continue;
                }
                $position++;
            } else {
                $p = null;
                foreach ($params as $candidate) {
                    if ($candidate->getName() === $key) {
                        $p = $candidate;
                    }
                }
            }
            if ($p === null) {
                self::problem("an argument {$key} no parameter of " . ($function?->getName() ?? '?') . ' takes');
                $call[$key] = $value;
                continue;
            }
            if (self::callable($p) && ($value instanceof \Closure || (\is_callable($value) && !\is_string($value)))) {
                $step->callbacks[$p->getName()] = [];
                $value = self::wrap($step, $p->getName(), $value);
                $named[$p->getName()] = true;
            } else {
                if (self::callable($p) && \is_string($value) && \is_callable($value) && self::$on && self::$depth === 0) {
                    self::problem("passes the function name `{$value}` as a callable");
                }
                $named[$p->getName()] = $value;
            }
            $call[$key] = $value;
        }

        return [$named, $call];
    }

    /**
     * @param array<string, mixed> $named
     * @return array<string, mixed>|\stdClass
     */
    private static function encodeNamed(array $named, Step $step): array|\stdClass
    {
        $encoded = [];
        foreach ($named as $key => $value) {
            $encoded[$key] = \array_key_exists($key, $step->callbacks) ? true : self::encode($value, $step);
        }

        return $encoded === [] ? new \stdClass() : $encoded;
    }

    /**
     * Runs a recorded unit step: its result encoded (callbacks' calls with
     * it), a library object it returns stored under a name.
     */
    private static function runUnit(Step $step, \Closure $call): mixed
    {
        self::push($step);
        $before = self::$logCount;
        self::$depth++;
        try {
            $result = $call();
            if ($result instanceof \Generator) {
                $list = iterator_to_array($result, false);
                $value = $list;
                $result = (fn () => yield from $list)();
            } else {
                $value = $result;
            }
            $step->raw = $value;
            $step->result = self::oversized($value)
                ? self::tooLarge()
                : Core::result($step->callbacks === [] ? $value : new Called($value, self::invocations($step)));
            if (self::library($value) && !$value instanceof \UnitEnum && self::name($value) === null) {
                $name = self::assign($value);
                $step->defines[] = $name;
                $step->args['as'] = $name;
            }

            return $result;
        } catch (\Throwable $error) {
            $step->thrown = $error;
            $step->result = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
            throw $error;
        } finally {
            self::$depth--;
            $step->logged = self::$logCount > $before;
            foreach ($step->callbacks as $invocations) {
                foreach ($invocations as $invocation) {
                    foreach ($invocation['steps'] as $nested) {
                        $step->defines = [...$step->defines, ...$nested->defines];
                        $step->logged = $step->logged || $nested->logged;
                    }
                }
            }
        }
    }

    /**
     * `new <class>(...)` in test code.
     *
     * @param array<int|string, mixed> $args
     */
    public static function create(string|object $class, array $args): object
    {
        $class = \is_object($class) ? $class::class : ltrim($class, '\\');
        if ($class === 'PDO' || $class === \Utopia\Database\PDO::class) {
            return self::pdo($class, $args);
        }
        self::formats();
        if (!Rewriter::library($class) || is_a($class, \Throwable::class, true)) {
            return new $class(...$args);
        }
        if (!self::$on || self::$depth > 0) {
            if (self::$depth > 0) {
                self::problem("the library ran test code that built a {$class}");
            }

            return new $class(...$args);
        }
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor !== null && !$constructor->isPublic()) {
            // Tests that the constructor is not callable: PHP visibility, which object.new refuses.
            self::problem("builds a {$class}, whose constructor is not public");

            return new $class(...$args);
        }
        $step = new Step('object.new');
        [$named, $call] = self::named($constructor, $args, $step);
        $step->args = ['class' => Values::shortName($class), 'args' => self::encodeNamed($named, $step)];
        $object = self::runUnit($step, fn () => new $class(...$call));
        if (self::name($object) === null) {
            $name = self::assign($object);
            $step->defines[] = $name;
            $step->args['as'] = $name;
        }
        $step->result = Core::result($object);

        return $object;
    }

    /**
     * `<class>::method(...)` in test code.
     *
     * @param array<int|string, mixed> $args
     */
    public static function callStatic(string $class, string $method, array $args): mixed
    {
        self::formats();
        // A Structure format is code: registered by name (db.add_format, a
        // fixture of Fixtures.php), which formats() records at the next step.
        if ($class === Structure::class && $method === 'addFormat') {
            return $class::{$method}(...$args);
        }
        if (!self::$on || self::$depth > 0 || !method_exists($class, $method)) {
            if (self::$on && self::$depth > 0) {
                self::problem("the library ran test code that called {$class}::{$method}");
            }

            return $class::{$method}(...$args);
        }
        $step = new Step('object.call');
        [$named, $call] = self::named(new \ReflectionMethod($class, $method), $args, $step);
        $step->args = ['class' => Values::shortName($class), 'method' => $method, 'args' => self::encodeNamed($named, $step)];

        return self::runUnit($step, fn () => $class::{$method}(...$call));
    }

    /**
     * `$target->method(...)` in test code.
     *
     * @param array<int|string, mixed> $args
     */
    public static function callObject(mixed $target, string $method, array $args): mixed
    {
        self::formats();
        if (!self::library($target) || !method_exists($target, $method)) {
            return $target->{$method}(...$args);
        }
        if (!self::$on || self::$depth > 0) {
            if (self::$on && self::$depth > 0) {
                self::problem('the library ran test code that called ' . $target::class . "::{$method}");
            }

            return $target->{$method}(...$args);
        }
        if ($target instanceof RecordingProfiler) {
            return $target->{$method}(...$args);
        }
        if ($target instanceof \Utopia\Database\PDO || $target instanceof \Utopia\Database\PDOStatement) {
            self::problem('calls ' . $target::class . '::' . $method . ' (waived PHP runtime plumbing)');

            return $target->{$method}(...$args);
        }
        $step = new Step('object.call');
        [$named, $call] = self::named(new \ReflectionMethod($target, $method), $args, $step);
        $self = self::encode($target, $step);
        if (!\is_array($self) || array_intersect(array_keys($self), ['$obj', '$document', '$query', '$operator', '$value', '$enum', '$datetime']) === []) {
            self::problem('calls a ' . $target::class . ' no step built or returned');
        }
        $step->args = ['this' => $self, 'method' => $method, 'args' => self::encodeNamed($named, $step)];

        return self::runUnit($step, fn () => $target->{$method}(...$call));
    }

    /**
     * `$target?->method(...)` in test code.
     *
     * @param array<int|string, mixed> $args
     */
    public static function callNullsafe(mixed $target, string $method, array $args): mixed
    {
        return $target === null ? null : self::callObject($target, $method, $args);
    }

    /**
     * A PDO the test builds: built as asked, remembered as a `$pdo` spec
     * (`{"dsn", "class"?, "username"?, "password"?, "options"?}`).
     *
     * @param array<int|string, mixed> $args
     */
    private static function pdo(string $class, array $args): object
    {
        $pdo = new $class(...$args);
        $params = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $spec = [];
        foreach (array_values(\array_slice(array_keys($args), 0)) as $i => $key) {
            $name = \is_int($key) ? ($params[$key]->name ?? (string) $key) : $key;
            $spec[$name] = $args[$key];
        }
        $out = ['dsn' => (string) ($spec['dsn'] ?? '')];
        if ($class !== 'PDO') {
            $out['wrapper'] = true;
        }
        foreach (['username', 'password'] as $k) {
            if (isset($spec[$k])) {
                $out[$k] = $spec[$k];
            }
        }
        $options = $spec['options'] ?? $spec['config'] ?? null;
        if (\is_array($options) && $options !== []) {
            $out['options'] = $options;
        }
        self::$pdos ??= new \WeakMap();
        self::$pdos[$pdo] = $out;

        return $pdo;
    }

    /** Seconds of the window in which a datetime counts as "now" (as the `now` scrub). */
    private const WINDOW = 180000;

    private static function relative(\DateTimeInterface $value): ?string
    {
        $offset = (int) round(((float) $value->format('U.u')) - microtime(true));
        if (abs($offset) > self::WINDOW) {
            return null;
        }

        return $offset === 0 ? 'now' : ($offset > 0 ? '+' : '') . $offset . ' seconds';
    }

    /**
     * `{"$now": <seconds>, "format": ...}` for a datetime string near now.
     *
     * @return array{'$now': int, format: string}|null
     */
    private static function now(string $value): ?array
    {
        $length = \strlen($value);
        if ($length < 19 || $length > 32 || !ctype_digit(substr($value, 0, 4)) || $value[4] !== '-') {
            return null;
        }
        foreach (['Y-m-d H:i:s.v', 'Y-m-d\TH:i:s.vP', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d H:i:s.u'] as $format) {
            $parsed = \DateTime::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            if ($parsed === false || $parsed->format($format) !== $value) {
                continue;
            }
            $offset = (int) round(((float) $parsed->format('U.u')) - microtime(true));
            if (abs($offset) > self::WINDOW) {
                return null;
            }

            return ['$now' => $offset, 'format' => $format];
        }

        return null;
    }
}
