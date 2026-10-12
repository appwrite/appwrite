<?php

namespace Tests\Compat\Database\Recorder;

use Tests\Compat\Database\Called;
use Tests\Compat\Database\Core;
use Tests\Compat\Database\Invocation;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;

/**
 * One call the tests made on the Database, its adapter or its
 * Authorization, as a compat step: operation, named arguments (encoded
 * before the call) and result (encoded after it).
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

    /** Known `before` transformations of the tests (file:line => fixture). */
    private const TRANSFORMS = [
        'CollectionTests.php:1679' => ['append' => ' AND 1=0'],
    ];

    private const READS = ['get', 'find', 'count', 'sum', 'exists', 'list', 'is', 'has', 'ping', 'encode', 'decode', 'casting', 'convert', 'check', 'iterate', 'foreach', 'in'];

    private const WRAPPERS = ['with', 'skip', 'silent'];

    /** Methods whose first parameter names a database (schema). */
    private const SCHEMA_NAMES = ['setDatabase', 'exists', 'create', 'delete'];

    /**
     * Prefixes the database (schema) names the tests use besides the
     * suite's own (`sharedTables`, `schema1`, `hellodb`, `stMulti*`, ...)
     * with the recorder's schema, so they record as `${ns}_<name>`: each
     * runtime's run then works in schemas of its own, which the runner's
     * cleanup of `${ns}` drops with it.
     *
     * @param list<mixed> $positional
     * @return list<mixed>
     */
    private static function isolate(string $kind, string $method, array $positional): array
    {
        if (!\in_array($kind, ['database', 'adapter'], true) || !\in_array($method, self::SCHEMA_NAMES, true)) {
            return $positional;
        }
        $name = $positional[0] ?? null;
        if (\is_string($name) && $name !== '' && $name !== self::$schema && !str_starts_with($name, self::$schema . '_')) {
            $positional[0] = self::$schema . '_' . $name;
        }

        return $positional;
    }

    public static function log(): void
    {
        self::$logCount++;
    }

    /**
     * A call of `$method` on `$target` (kind database|adapter|authorization).
     *
     * @param list<mixed> $positional
     */
    public static function call(object $target, string $kind, string $method, array $positional, \Closure $parent): mixed
    {
        if (!self::$on || self::$depth > 0) {
            return $parent(...$positional);
        }
        $positional = self::isolate($kind, $method, $positional);
        self::formats();
        $reflection = new \ReflectionMethod(get_parent_class($target), $method);
        $params = $reflection->getParameters();
        $named = [];
        $step = new Step(match ($kind) {
            'database' => 'database.' . Core::snake($method),
            'adapter' => 'db.adapter',
            default => 'db.authorization.' . Core::snake($method),
        });
        foreach ($params as $i => $p) {
            if (!\array_key_exists($i, $positional)) {
                break;
            }
            $value = $positional[$i];
            if ($p->isDefaultValueAvailable() && $value === $p->getDefaultValue() && !($kind === 'database' && \in_array($method, ['on', 'before'], true))) {
                continue;
            }
            if ($kind === 'database' && $method === 'on' && $p->getName() === 'callback') {
                $named['callback'] = $value === null ? null : true;
                if ($value !== null) {
                    $positional[$i] = function (string $event, mixed $data = null) use ($value) {
                        self::log();

                        return $value($event, $data);
                    };
                }
                continue;
            }
            if ($kind === 'database' && $method === 'before' && $p->getName() === 'callback') {
                $named['callback'] = $value === null ? null : self::transform($value);
                if ($value !== null) {
                    $positional[$i] = function (string $sql) use ($value) {
                        self::log();

                        return $value($sql);
                    };
                }
                continue;
            }
            if (\is_callable($value) && !\is_string($value) && self::callable($p)) {
                $step->callbacks[$p->getName()] = [];
                $positional[$i] = self::wrap($step, $p->getName(), $value);
                $named[$p->getName()] = true; // replaced by the steps at the end
                continue;
            }
            $named[$p->getName()] = $value;
        }
        $encoded = [];
        foreach ($named as $key => $value) {
            $encoded[$key] = $step->callbacks !== [] && \array_key_exists($key, $step->callbacks) ? true : self::encode($value);
        }
        if ($kind === 'database' && $target !== self::$main) {
            $encoded = ['db' => self::encode($target)] + $encoded;
        }
        $step->args = $kind === 'adapter' ? ['method' => $method, 'args' => $encoded === [] ? new \stdClass() : $encoded] : $encoded;
        $step->collections = self::collections($method, $named);
        $step->write = self::writes($kind, $method);
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
            $step->raw = $value;
            $step->result = Core::result($step->callbacks === [] ? $value : new Called($value, self::invocations($step)));
            if ($step->setting !== null && $value instanceof Database) {
                $step->result = ['$database' => true];
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
                    }
                }
            }
        }
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
            $invocation = new Invocation(array_values($args));
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
     * Database::addFilter from the tests: recorded by name (core.php has the
     * fixture), each call of the filter logged.
     */
    public static function addFilter(string $name, callable $encode, callable $decode, \Closure $parent): void
    {
        $logged = fn (callable $fn) => function (mixed $value) use ($fn) {
            self::log();

            return $fn($value);
        };
        if (self::$on && self::$depth === 0) {
            $step = new Step('database.add_filter');
            $step->args = ['name' => $name];
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
     * A recorded settings step outside the tests' own calls (between tests).
     */
    private static function transform(callable $callback): mixed
    {
        $r = new \ReflectionFunction(\Closure::fromCallable($callback));
        $key = basename((string) $r->getFileName()) . ':' . $r->getStartLine();
        if (!isset(self::TRANSFORMS[$key])) {
            self::$problems[] = "unknown before() transformation at {$key}";

            return ['append' => ''];
        }

        return self::TRANSFORMS[$key];
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
                || ($key === 'id' && str_ends_with($method, 'Collection'))
                || ($key === 'name' && $method === 'createCollection');
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

        return $out;
    }

    private static function writes(string $kind, string $method): bool
    {
        foreach (self::WRAPPERS as $prefix) {
            if (str_starts_with($method, $prefix)) {
                return false;
            }
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
        if ($kind === 'adapter') {
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
            'resetMetadata' => null, 'setTimeout' => 'event', 'clearTimeout' => 'event', 'enableFilters' => null,
            'disableFilters' => null, 'enableValidation' => null, 'disableValidation' => null,
            'setSharedTables' => null, 'setTenant' => null, 'setTenantPerDocument' => null, 'enableLocks' => null,
            'setDocumentType' => 'collection', 'clearDocumentType' => 'collection', 'clearAllDocumentTypes' => null,
            'setDropUnknownAttributes' => null, 'setPreserveDates' => null, 'setMigrating' => null,
            'setPreserveSequence' => null, 'setMaxQueryValues' => null, 'setGlobalCollections' => null,
            'resetGlobalCollections' => null, 'on' => 'name', 'before' => 'name', 'setCache' => null,
            'setAuthorization' => null,
        ];
        if (!\array_key_exists($method, $settings)) {
            return null;
        }
        $key = $settings[$method];
        $suffix = $key === null ? '' : ':' . (\is_scalar($named[$key] ?? null) ? (string) $named[$key] : '');
        if (\in_array($method, ['on', 'before'], true)) {
            $suffix = ':' . (string) ($named['event'] ?? '') . $suffix;
        }
        // Setters and their resets share a key, so only the last one counts.
        $group = match ($method) {
            'enableFilters', 'disableFilters' => 'filters',
            'enableValidation', 'disableValidation' => 'validation',
            'setMetadata', 'resetMetadata' => 'metadata',
            'setTimeout', 'clearTimeout' => 'timeout',
            'setGlobalCollections', 'resetGlobalCollections' => 'globalCollections',
            default => $method,
        };

        return $group . $suffix;
    }

    /**
     * A value as a step argument: Values tags, DateTimes as `$datetime`, and
     * datetimes near now (as objects or strings) relative to the clock.
     */
    public static function encode(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Document => ['$document' => $value->isEmpty() ? new \stdClass() : self::encode($value->getArrayCopy())],
            $value instanceof Query => ['$query' => ['method' => $value->getMethod(), 'attribute' => $value->getAttribute(), 'values' => self::encode($value->getValues())]],
            $value instanceof Operator => ['$operator' => ['method' => $value->getMethod(), 'attribute' => $value->getAttribute(), 'values' => self::encode($value->getValues())]],
            $value instanceof \DateTimeInterface => ['$datetime' => self::relative($value) ?? $value->format('Y-m-d\TH:i:s.uP')],
            $value instanceof \stdClass => (array) $value === [] ? $value : ['$object' => self::encode((array) $value)],
            $value instanceof PeerDatabase && $value->opened !== null => ['$ref' => "s{$value->opened}"],
            $value === self::$main => ['$ref' => 'db'],
            $value instanceof Database && \in_array($value, self::$parts, true) => ['$ref' => (string) array_search($value, self::$parts, true)],
            $value instanceof \Utopia\Database\Adapter && $value === self::$main?->getAdapter() => ['$ref' => 'db'],
            $value instanceof Authorization && $value === self::$main?->getAuthorization() => ['$ref' => 'db'],
            $value instanceof Authorization => ['roles' => $value->getRoles()],
            $value instanceof \Utopia\Cache\Cache => self::cache($value),
            \is_string($value) && \strlen($value) > 65536 => self::repeat($value),
            \is_array($value) => array_map(self::encode(...), $value),
            \is_string($value) => self::now($value) ?? $value,
            \is_object($value) => self::unsupported($value),
            default => $value,
        };
    }

    /**
     * A second Database the tests built on the suite's adapter (a peer):
     * `db.open` with `adapter`, its calls then naming it with `db`.
     */
    public static function peer(PeerDatabase $peer, \Utopia\Database\Adapter $adapter, \Utopia\Cache\Cache $cache): void
    {
        if (!self::$on || self::$depth > 0) {
            return;
        }
        $step = new Step('db.open');
        $step->args = ['adapter' => self::encode($adapter), 'cache' => self::encode($cache)];
        $step->result = ['$handle' => '*'];
        $step->write = true;
        self::push($step);
        $peer->opened = $step->index;
    }

    private static function unsupported(object $value): array
    {
        self::$problems[] = 'cannot encode an argument of type ' . $value::class;

        return ['$unsupported' => $value::class];
    }

    private static function cache(\Utopia\Cache\Cache $cache): mixed
    {
        $adapter = (new \ReflectionProperty(\Utopia\Cache\Cache::class, 'adapter'))->getValue($cache);

        return match (true) {
            $adapter instanceof \Utopia\Cache\Adapter\Memory => 'memory',
            $adapter instanceof \Utopia\Cache\Adapter\None => 'none',
            isset(Core::$kinds[$cache]) => Core::$kinds[$cache],
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
