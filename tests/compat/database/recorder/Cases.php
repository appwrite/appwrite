<?php

namespace Tests\Compat\Database\Recorder;

use Tests\Compat\Codec;

/**
 * Turns the recorded stream into self-contained cases, one per test method:
 *
 *   db.open, the settings in effect when the test started (when they differ
 *   from a fresh database's), the earlier steps it depends on (a dependency
 *   slice), then its own steps.
 *
 * - Slice: every earlier write touching a collection the test touches,
 *   closed over the collections those writes touch, plus every step whose
 *   result a kept step refers to, plus process-wide registrations (filters,
 *   formats).
 * - Ids: a uniqid the tests generated (it first appears in arguments) becomes
 *   a deterministic fake of the same length; one the library generated (it
 *   first appears in a result) becomes `{"$ref": "s<step>", "path": ...}` to
 *   the step that returned it, or `{"$local": ...}` inside a callback.
 * - The recorder's schema in arguments becomes `${ns}`.
 */
final class Cases
{
    /** @var list<string> */
    public array $problems = [];

    /** @var array<string, string> uniqid token => fake */
    private array $fakes = [];

    /** @var array<string, array{0: int, 1: string}> uniqid token => [step index, pointer] */
    private array $producers = [];

    /** @var array<int, array<string, mixed>> step index => rewritten args */
    private array $args = [];

    /** @var array<int, list<int>> step index => steps it refers to */
    private array $refs = [];

    /** @var array<int, true> steps referred to */
    private array $bound = [];

    private int $fake = 0;

    /** @var array<string, int> stored object (`as`) => the top-level step that defines it */
    private array $definers = [];

    /**
     * @param list<Step> $stream
     * @param list<array<string, mixed>> $tests
     * @param array<string, mixed>|\stdClass $open
     * @param array<int, list<array<string, mixed>>> $dirty steps restoring the settings each test started with, when they differ from a fresh database's
     */
    public function __construct(
        private Profile $profile,
        private array $stream,
        private array $tests,
        private array|\stdClass $open,
        private string $schema,
        private array $dirty = [],
        private bool $units = false,
    ) {
        self::$profileName = $this->profile->name;
        self::$innodb = \in_array($this->profile->engine(), ['mariadb', 'mysql'], true);
        self::$mysql = $this->profile->engine() === 'mysql';
        foreach ($this->stream as $step) {
            foreach ($step->defines as $name) {
                $this->definers[$name] ??= $step->index;
            }
        }
        foreach ($this->stream as $step) {
            $this->rewrite($step);
        }
    }

    private function rewrite(Step $step): void
    {
        $refs = [];
        $args = $this->value($step->args, $refs, null);
        foreach ($step->callbacks as $param => $invocations) {
            self::place($args, $step->op, $param, $this->callback($step, $param, $invocations, $refs));
        }
        $this->args[$step->index] = $args;
        $this->refs[$step->index] = array_values(array_unique($refs));
        foreach ($this->refs[$step->index] as $r) {
            $this->bound[$r] = true;
        }
        $this->produce($step->result, $step->index, '');
    }

    /** Operations whose method arguments are under `args` (the others take them directly). */
    private const NESTED_ARGS = ['object.new', 'object.call', 'adapter.call', 'db.adapter', 'db.profiler'];

    /**
     * Puts a callback argument where its operation reads the method's
     * arguments.
     *
     * @param array<string, mixed> $args
     */
    private static function place(array &$args, string $op, string $param, mixed $callback): void
    {
        if (\in_array($op, self::NESTED_ARGS, true)) {
            $args['args'][$param] = $callback;
        } else {
            $args[$param] = $callback;
        }
    }

    /**
     * A callback argument: `true`, or the steps of its first invocation
     * that has any (later invocations must make the same calls).
     *
     * @param list<array{invocation: \Tests\Compat\Database\Invocation, steps: list<Step>}> $invocations
     * @param list<int> $refs
     */
    private function callback(Step $step, string $param, array $invocations, array &$refs): mixed
    {
        $chosen = null;
        foreach ($invocations as $invocation) {
            if ($invocation['steps'] !== []) {
                $chosen = $invocation['steps'];
                break;
            }
        }
        if ($chosen === null) {
            return true;
        }
        $locals = [];
        $out = [];
        $shapes = [];
        foreach ($chosen as $k => $nested) {
            $nestedRefs = [];
            $entry = ['op' => $nested->op, 'args' => $this->value($nested->args, $nestedRefs, $locals)];
            foreach ($nested->callbacks as $p => $inner) {
                self::place($entry['args'], $nested->op, $p, $this->callback($nested, $p, $inner, $nestedRefs));
            }
            if ($entry['args'] === []) {
                $entry['args'] = new \stdClass();
            }
            $refs = array_merge($refs, $nestedRefs);
            if ($nested->catch) {
                $entry['catch'] = true;
            }
            if ($nested->return) {
                $entry['return'] = true;
            }
            $entry['bind'] = "l{$k}";
            $out[] = $entry;
            $shapes[] = $nested->op;
            $this->localProduce($nested->result, "l{$k}", '', $locals);
        }
        foreach ($invocations as $i => $invocation) {
            $ops = array_map(fn (Step $s) => $s->op, $invocation['steps']);
            if ($ops !== [] && $ops !== $shapes) {
                $this->problems[] = "{$step->op} `{$param}` invocation {$i} made other calls than the first (" . implode(', ', $ops) . ')';
                break;
            }
        }
        // Binds no later step uses are noise.
        $used = json_encode($out);
        foreach ($out as &$entry) {
            if (!str_contains((string) $used, '"$local":"' . $entry['bind'] . '"')) {
                unset($entry['bind']);
            }
        }

        return ['steps' => $out];
    }

    /**
     * Rewrites ids and the schema in an argument.
     *
     * @param list<int> $refs
     * @param array<string, array{0: string, 1: string}>|null $locals
     */
    private function value(mixed $value, array &$refs, ?array $locals): mixed
    {
        if (\is_string($value)) {
            if ($this->schema !== '' && str_contains($value, $this->schema)) {
                $value = str_replace($this->schema, '${ns}', $value);
            }
            if ($locals !== null && isset($locals[$value]) && !isset($this->fakes[$value])) {
                return ['$local' => $locals[$value][0], 'path' => $locals[$value][1]];
            }
            if (isset($this->producers[$value]) && !isset($this->fakes[$value])) {
                [$index, $path] = $this->producers[$value];
                $refs[] = $index;
                if (self::uuid7($value)) {
                    // A MongoDB `$sequence`: the value recorded stands in
                    // when the producing step fails on replay (a slice runs
                    // without the roles the suite had when it made it).
                    return ['$ref' => "s{$index}", 'path' => $path, 'default' => $value];
                }

                return ['$ref' => "s{$index}", 'path' => $path];
            }

            return $this->tokens($value, $locals);
        }
        if (\is_array($value)) {
            // A datetime near now (Recorder::now()) replays as now.
            if (self::stamp($value)) {
                unset($value['$was']);

                return $value;
            }
            // A stored object: the step defining it comes along.
            if (\count($value) === 1 && \is_string($value['$obj'] ?? null)) {
                if (isset($this->definers[$value['$obj']])) {
                    $refs[] = $this->definers[$value['$obj']];
                }

                return $value;
            }
            // A reference the recorder made itself (a peer database's handle).
            if (\count($value) === 1 && \is_string($value['$ref'] ?? null)) {
                if (str_starts_with($value['$ref'], 's')) {
                    $refs[] = (int) substr($value['$ref'], 1);
                }

                return $value;
            }
            $out = [];
            foreach ($value as $k => $v) {
                // A document's own timestamp a step returned, passed back
                // (an update that changes nothing must not see a later now).
                if (\in_array($k, self::STAMPS, true) && self::stamp($v) && \is_string($value['$id'] ?? null)
                    && isset($this->stamps[$value['$id'] . "\0" . $v['$was']])) {
                    [$index, $path] = $this->stamps[$value['$id'] . "\0" . $v['$was']];
                    $refs[] = $index;
                    unset($v['$was']);
                    // Now stands in when the producing step fails on replay.
                    $out[$k] = ['$ref' => "s{$index}", 'path' => $path, 'default' => $v];

                    continue;
                }
                $out[\is_string($k) ? $this->tokens($k, $locals) : $k] = $this->value($v, $refs, $locals);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Replaces the uniqid tokens of a string the library did not produce
     * with fakes (the tests generated them).
     *
     * @param array<string, mixed>|null $locals
     */
    private function tokens(string $value, ?array $locals): string
    {
        $out = '';
        $length = \strlen($value);
        $i = 0;
        while ($i < $length) {
            $j = $i;
            while ($j < $length && ctype_alnum($value[$j])) {
                $j++;
            }
            if ($j > $i) {
                $token = substr($value, $i, $j - $i);
                if (self::uniqid($token)) {
                    if (isset($this->producers[$token]) || isset($locals[$token])) {
                        if (!isset($this->fakes[$token])) {
                            $this->problems[] = "a generated id is part of a longer argument: {$value}";
                        }
                        $out .= $token;
                    } else {
                        $out .= $this->fakes[$token] ??= $this->fakeFor($token);
                    }
                } else {
                    $out .= $token;
                }
                $i = $j;
            } else {
                $out .= $value[$i];
                $i++;
            }
        }

        return $out;
    }

    private function fakeFor(string $token): string
    {
        $this->fake++;

        return 'u' . str_pad((string) $this->fake, \strlen($token) - 1, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a token is a uniqid made within a day of now.
     */
    public static function uniqid(string $token): bool
    {
        $length = \strlen($token);
        if ($length < 13 || $length > 36 || !ctype_xdigit($token) || strtolower($token) !== $token) {
            return false;
        }

        return abs(hexdec(substr($token, 0, 8)) - time()) <= 86400;
    }

    /**
     * Whether a string is a UUIDv7 made within a day of now (a MongoDB
     * document's `$sequence`, which the library generates).
     */
    public static function uuid7(string $value): bool
    {
        if (\strlen($value) !== 36 || $value[14] !== '7' || substr_count($value, '-') !== 4) {
            return false;
        }
        $hex = str_replace('-', '', $value);
        if (!ctype_xdigit($hex) || strtolower($hex) !== $hex) {
            return false;
        }

        return abs(intdiv(hexdec(substr($hex, 0, 12)), 1000) - time()) <= 86400;
    }

    private const STAMPS = ['$createdAt', '$updatedAt'];

    /** @var array<string, array{0: int, 1: string}> the step and path of each document's timestamp, by `$id` NUL timestamp (the latest) */
    private array $stamps = [];

    /**
     * Recorder::now()'s tag for a datetime near now.
     *
     * @phpstan-assert-if-true array{'$now': int, format: string, '$was': string} $value
     */
    private static function stamp(mixed $value): bool
    {
        return \is_array($value) && \count($value) === 3 && \is_string($value['$was'] ?? null) && \array_key_exists('$now', $value);
    }

    /**
     * Registers the uniqids in a step's result, and the timestamps of the
     * documents in it, as produced by it.
     */
    private function produce(mixed $value, int $index, string $path): void
    {
        if (\is_array($value) && \is_string($value['$id'] ?? null)) {
            foreach (self::STAMPS as $key) {
                if (\is_string($value[$key] ?? null)) {
                    $this->stamps[$value['$id'] . "\0" . $value[$key]] = [$index, $path . '/' . $key];
                }
            }
        }
        if (\is_string($value)) {
            if ((self::uniqid($value) || self::uuid7($value)) && !isset($this->fakes[$value]) && !isset($this->producers[$value])) {
                $this->producers[$value] = [$index, $path];
            }

            return;
        }
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                $this->produce($v, $index, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $k));
            }
        }
    }

    /**
     * @param array<string, array{0: string, 1: string}> $locals
     */
    private function localProduce(mixed $value, string $bind, string $path, array &$locals): void
    {
        if (\is_string($value)) {
            if ((self::uniqid($value) || self::uuid7($value)) && !isset($this->fakes[$value]) && !isset($locals[$value]) && !isset($this->producers[$value])) {
                $locals[$value] = [$bind, $path];
            }

            return;
        }
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                $this->localProduce($v, $bind, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $k), $locals);
            }
        }
    }

    /**
     * The steps of test `$t`'s case (indexes into the stream), in order.
     *
     * @return list<int>
     */
    private function slice(int $t): array
    {
        $own = [];
        foreach ($this->stream as $step) {
            if ($step->test === $t) {
                $own[] = $step->index;
            }
        }
        if ($own === []) {
            return [];
        }
        $start = $own[0];
        if ($this->units) {
            return $this->unitSlice($own);
        }
        $needed = [];
        foreach ($own as $i) {
            foreach ($this->collectionsOf($i) as $c) {
                $needed[$c] = true;
            }
        }
        $included = [];
        // A collection deleted (successfully) before the test starts afresh:
        // earlier writes to it are moot. Dropping the database resets all.
        $cuts = [];
        $reset = -1;
        for ($i = 0; $i < $start; $i++) {
            $s = $this->stream[$i];
            if ($s->test === -1 || self::failed($s)) {
                continue;
            }
            if ($s->op === 'database.delete_collection' && \count($s->collections) === 1) {
                $cuts[$s->collections[0]] = $i;
            } elseif ($s->resets) {
                $reset = $i;
            }
        }
        $live = fn (int $i, string $c) => $i > $reset && $i > ($cuts[$c] ?? -1);
        $changed = true;
        while ($changed) {
            $changed = false;
            for ($i = $start - 1; $i >= 0; $i--) {
                if (isset($included[$i])) {
                    continue;
                }
                $s = $this->stream[$i];
                if ($s->sticky) {
                    $included[$i] = true;
                    continue;
                }
                if (!$s->write || $s->setting !== null || $s->test === -1) {
                    continue;
                }
                foreach ($this->collectionsOf($i) as $c) {
                    if (isset($needed[$c]) && $live($i, $c)) {
                        $included[$i] = true;
                        foreach ($this->collectionsOf($i) as $more) {
                            $changed = $changed || !isset($needed[$more]);
                            $needed[$more] = true;
                        }
                        break;
                    }
                }
            }
            foreach (array_merge(array_keys($included), $own) as $i) {
                foreach ($this->refs[$i] as $r) {
                    if ($r < $start && !isset($included[$r])) {
                        $included[$r] = true;
                        $changed = true;
                        foreach ($this->collectionsOf($r) as $more) {
                            $needed[$more] = true;
                        }
                    }
                }
            }
        }
        $all = array_keys($included);
        sort($all);

        return array_merge($all, $own);
    }

    /**
     * A unit test's steps: its own (setUp, the test, tearDown) and the
     * earlier ones they refer to, closed over their references, plus
     * process-wide registrations. Unit tests build their objects afresh, so
     * collections tie nothing together.
     *
     * @param list<int> $own
     * @return list<int>
     */
    private function unitSlice(array $own): array
    {
        $start = $own[0];
        $included = [];
        for ($i = 0; $i < $start; $i++) {
            if ($this->stream[$i]->sticky) {
                $included[$i] = true;
            }
        }
        $queue = $own;
        while ($queue !== []) {
            $i = array_pop($queue);
            foreach ($this->refs[$i] as $r) {
                if ($r < $start && !isset($included[$r])) {
                    $included[$r] = true;
                    $queue[] = $r;
                }
            }
        }
        $all = array_keys($included);
        sort($all);

        return array_merge($all, $own);
    }

    /**
     * A step whose result legitimately differs between runs or runtimes.
     *
     * @return array{paths: list<string>, reason: string}|null
     */
    /** The recording profile's name. */
    private static string $profileName = '';

    /**
     * Why a raw read of a table's columns from pg_attribute is masked: the
     * tests read it into a map, and the postgres snapshot compares the
     * columns in attnum order.
     */
    private const string UNORDERED_COLUMNS = 'PostgreSQL returns the pg_attribute rows the test reads without an ORDER BY in the order of the plan it picks (the catalog index, by attnum, or the heap, where altering a column moves its row), so the column order depends on the server, not the runtime';

    /** Whether the cases run on MariaDB/MySQL (InnoDB). */
    private static bool $innodb = false;

    /** Whether the cases run on MySQL. */
    private static bool $mysql = false;

    /** @var list<string> the stored objects that are random-order queries */
    private static array $random = [];

    private const UNORDERED_DISTINCT = 'a distinct() read without an order returns its rows in the order the engine deduplicates them in (a hash on PostgreSQL), so its rows are compared as a set';

    private static function mask(Step $step): ?array
    {
        $args = (string) json_encode($step->args);
        // Unit tests build the query first (`Query::orderRandom()` stored under a name), then pass it.
        if ($step->op === 'object.call' && ($step->args['method'] ?? '') === 'orderRandom' && isset($step->args['as'])) {
            self::$random[] = (string) $step->args['as'];
        }
        foreach (self::$random as $name) {
            if (str_contains($args, '"$obj":"' . $name . '"')) {
                return ['paths' => [''], 'reason' => 'Query::orderRandom() returns the documents in a random order'];
            }
        }
        if (str_contains($args, '"method":"orderRandom"')) {
            return ['paths' => [''], 'reason' => 'Query::orderRandom() returns the documents in a random order'];
        }
        if ($step->op === 'database.find' && str_contains($args, '"method":"distinct"') && !str_contains($args, '"method":"order')) {
            return ['paths' => [''], 'rows' => true, 'reason' => self::UNORDERED_DISTINCT];
        }
        if (str_contains($args, '"method":"cursor') && (str_contains($args, '"attribute":"$createdAt"') || str_contains($args, '"attribute":"$updatedAt"'))) {
            return ['paths' => [''], 'reason' => 'documents written within one millisecond tie on $createdAt/$updatedAt, so the page after a cursor depends on timing'];
        }
        if (self::$innodb && \in_array($step->op, ['database.get_size_of_collection', 'database.get_size_of_collection_on_disk'], true)) {
            return ['paths' => [''], 'reason' => 'InnoDB updates the table statistics these sizes come from in the background, so a size read right after a write depends on timing'];
        }
        if (self::$mysql && $step->op === 'database.get_schema_attributes') {
            return ['paths' => [''], 'reason' => 'MySQL 8 lists INFORMATION_SCHEMA.COLUMNS, which getSchemaAttributes() reads without an ORDER BY, in no fixed order'];
        }
        $query = $step->op === 'db.adapter' && ($step->args['method'] ?? '') === 'rawQuery' ? (string) ($step->args['args']['query'] ?? '') : '';
        if (str_contains($query, 'FROM pg_attribute') && !str_contains($query, 'ORDER BY')) {
            return ['paths' => [''], 'reason' => self::UNORDERED_COLUMNS];
        }
        if (self::lists($step)) {
            return ['paths' => [''], 'reason' => 'a MongoDB listing names every database on the server, other runs\' included'];
        }
        if ($step->op === 'database.get_size_of_collection_on_disk' && str_starts_with(self::$profileName, 'mongo')) {
            return ['paths' => [''], 'reason' => 'a MongoDB collection\'s storage size depends on when WiredTiger last checkpointed it'];
        }
        if (\in_array($step->op, ['database.get_hostname', 'database.get_connection_id'], true) || ($step->op === 'db.adapter' && \in_array($step->args['method'] ?? '', ['hostname', 'id'], true))
            || ($step->op === 'object.call' && ($step->args['method'] ?? '') === 'getConnectionId')) {
            return ['paths' => [''], 'reason' => 'each runtime reaches the server under its own host name, on its own connection'];
        }
        if ($step->op === 'db.profiler' && \in_array($step->args['method'] ?? '', ['getLogs', 'getSlowQueries'], true)) {
            return ['paths' => ['/*/fields/durationMs', '/*/fields/backtrace'], 'reason' => 'a profiled statement\'s duration and the PHP stack it ran from are the runtime\'s own'];
        }
        // A profiler's logs read by unit tests (object.call), directly or in a callback.
        if (str_contains($args, '"method":"getLogs"') || str_contains($args, '"method":"getSlowQueries"')) {
            return ['paths' => ['/*/fields/durationMs', '/*/fields/backtrace', '/calls/*/*/steps/*/*/fields/durationMs', '/calls/*/*/steps/*/*/fields/backtrace'], 'reason' => 'a profiled statement\'s duration and the PHP stack it ran from are the runtime\'s own'];
        }
        if ($step->op === 'db.profiler' && ($step->args['method'] ?? '') === 'getTotalTime') {
            return ['paths' => [''], 'reason' => 'the time the profiled statements took'];
        }

        return null;
    }

    /**
     * Whether a step (or a callback of it) lists the MongoDB server's
     * databases (`Database::list()` on MongoDB).
     */
    private static function lists(Step $step): bool
    {
        if (!str_starts_with(self::$profileName, 'mongo')) {
            return false;
        }

        return $step->op === 'database.list' || str_contains((string) json_encode($step->callbacks), '"op":"database.list"');
    }

    private static function failed(Step $step): bool
    {
        return \is_array($step->result) && \array_key_exists('$error', $step->result);
    }

    /**
     * Long strings as `<long:length:sha256>`, as the runner's `long` scrub
     * compares them.
     */
    public static function compact(mixed $value): mixed
    {
        if (\is_string($value) && \strlen($value) > 65536) {
            return '<long:' . \strlen($value) . ':' . hash('sha256', $value) . '>';
        }
        if (\is_array($value)) {
            return array_map(self::compact(...), $value);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function collectionsOf(int $i): array
    {
        return $this->stream[$i]->collections;
    }

    /**
     * Writes one case file per trait; returns counts per file.
     *
     * @return array<string, array{tests: int, cases: int, steps: int}>
     */
    public function write(string $dir, ?string $filter): array
    {
        $files = [];
        foreach ($this->tests as $t => $test) {
            $file = $test['file'];
            $trait = basename($file, '.php');
            $files[$file] ??= ['tests' => 0, 'cases' => [], 'steps' => 0, 'statuses' => []];
            $files[$file]['tests']++;
            // A test left out (a unit test a case cannot carry): counted only.
            if ($test['skip'] ?? false) {
                $files[$file]['statuses'][$test['status']] = ($files[$file]['statuses'][$test['status']] ?? 0) + 1;
                continue;
            }
            $files[$file]['statuses'][$test['status']] = ($files[$file]['statuses'][$test['status']] ?? 0) + 1;
            $indexes = $this->slice($t);
            if ($indexes === []) {
                $this->problems[] = "{$trait}::{$test['name']} made no calls ({$test['status']}: {$test['message']})";
                continue;
            }
            // Restoring steps name the recorder's schema as the tests left it.
            $dirty = $this->renamed($this->dirty[$t] ?? []);
            $steps = $this->units ? [] : [['op' => 'db.open', 'args' => $this->open, 'bind' => 'db'], ...$this->profile->opening, ...$dirty];
            $present = array_flip($indexes);
            foreach ($indexes as $i) {
                $s = $this->stream[$i];
                $entry = ['op' => $s->op, 'args' => $this->args[$i] === [] ? new \stdClass() : $this->args[$i]];
                if (isset($this->bound[$i])) {
                    $entry['bind'] = "s{$i}";
                }
                foreach ($this->refs[$i] as $r) {
                    if (!isset($present[$r])) {
                        $this->problems[] = "{$trait}::{$test['name']}: step {$i} refers to step {$r} outside the case";
                    }
                }
                if (($mask = self::mask($s)) !== null) {
                    $entry['mask'] = $mask;
                }
                if (\in_array($s->op, ['database.get_size_of_collection', 'database.get_size_of_collection_on_disk'], true) && \in_array($this->profile->name, ['redis', 'shared-redis'], true)) {
                    $entry['mask'] = ['paths' => [''], 'reason' => "the Redis adapter sizes a collection by MEMORY USAGE of its keys, whose names carry the case namespace, so a size depends on the namespace's length"];
                }
                // A list of the databases (in the step or a callback of it) under
                // the shared Redis adapter, and what listeners logged of it.
                $lists = $this->profile->name === 'shared-redis' && str_contains((string) json_encode($entry['args']), '"op":"database.list"') || ($s->op === 'database.list' && $this->profile->name === 'shared-redis');
                $listMask = ['paths' => [''], 'reason' => 'the shared Redis adapter lists every database of the empty namespace, those of other cases and runtimes included'];
                if ($lists) {
                    $entry['mask'] = $listMask;
                }
                $steps[] = $entry;
                if ($s->logged) {
                    $log = $lists ? ['op' => 'db.log', 'args' => new \stdClass(), 'mask' => $listMask] : ['op' => 'db.log', 'args' => new \stdClass()];
                    // A MongoDB listing's event carries the listing.
                    if (self::lists($s)) {
                        $log['mask'] = $mask;
                    }
                    $steps[] = $log;
                }
            }
            $case = ['name' => "{$trait}::{$test['name']}", 'state' => $this->profile->state, 'steps' => $steps];
            $files[$file]['cases'][] = $case;
            $files[$file]['steps'] += \count($steps);
        }
        $written = [];
        foreach ($files as $file => $data) {
            $adapter = 'tests/e2e/Adapter/' . $this->profile->suite;
            $base = 'vendor/utopia-php/database/';
            if ($this->units) {
                // A unit test class: Unit-<its path under tests/unit>.json.
                $name = 'Unit-' . str_replace('/', '-', substr($file, 0, -4)) . '.json';
                $source = [$base . 'tests/unit/' . $file];
            } elseif (!str_starts_with($file, 'Scopes/')) {
                // The suite's own tests (the MongoDB ones under the profile's
                // prefix: Mongo-[SharedTables-|Schemaless-]MongoDBTest.json).
                $name = (str_starts_with($this->profile->name, 'mongo') ? $this->profile->prefix : '') . basename($file, '.php') . '.json';
                $source = [$base . 'tests/e2e/Adapter/' . $file];
            } else {
                $relative = substr($file, \strlen('Scopes/'));
                $name = $this->profile->scope() . str_replace('/', '-', substr($relative, 0, -4)) . '.json';
                $source = [$base . 'tests/e2e/Adapter/' . $file, $base . $adapter];
            }
            if ($filter !== null && array_filter(explode(',', $filter), fn (string $f) => str_contains($name, $f)) === []) {
                continue;
            }
            // A suite's own tests that all skip (RedisTest's) make no file.
            if ($data['cases'] === []) {
                continue;
            }
            fwrite(STDERR, "writing {$name}: " . \count($data["cases"]) . " cases, {$data["steps"]} steps\n");
            $json = ['source' => $source, 'cases' => $data['cases']];
            $text = json_encode(Codec::encode($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            file_put_contents($dir . '/' . $name, $text . "\n");
            $written[$name] = ['tests' => $data['tests'], 'statuses' => $data['statuses'], 'cases' => \count($data['cases']), 'steps' => $data['steps']];
        }

        return $written;
    }

    /**
     * `$value` with the recorder's schema in its strings as `${ns}`.
     */
    private function renamed(mixed $value): mixed
    {
        return match (true) {
            \is_string($value) && $this->schema !== '' => str_replace($this->schema, '${ns}', $value),
            \is_array($value) => array_map($this->renamed(...), $value),
            default => $value,
        };
    }

    /**
     * What PHP returned while recording each test's own steps (ids rewritten),
     * for fidelity checks against `bin/compat record`.
     *
     * @return array<string, list<mixed>>
     */
    public function originals(): array
    {
        $out = [];
        foreach ($this->tests as $t => $test) {
            $trait = basename($test['file'], '.php');
            foreach ($this->stream as $step) {
                if ($step->test === $t) {
                    $refs = [];
                    $out["{$trait}::{$test['name']}"][] = ['op' => $step->op, 'result' => self::compact($this->value($step->result, $refs, null))];
                }
            }
        }

        return $out;
    }
}
