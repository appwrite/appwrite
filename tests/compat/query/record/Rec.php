<?php

namespace Tests\Compat\Query\Record;

use Tests\Compat\Query\Replay;

/**
 * The runtime of a rewritten PHP test (see Rewriter): every call the test
 * makes on the library goes through here, runs for real, and is appended to
 * the program being recorded. Results are exported through a Replay, so
 * objects get the numbers the replay will give them.
 */
final class Rec
{
    private static ?Replay $replay = null;

    /** @var list<array<string, mixed>> the event list being appended to */
    private static array $events = [];

    /** Frames: 'event' while the library runs, 'cb' while test code runs inside a callback. */
    private static array $frames = [];

    /** @var array<int, int> spl_object_id of a test closure => k */
    private static array $fnIds = [];

    /** @var array<int, \Closure> k => recording wrapper */
    private static array $wrappers = [];

    /** @var array<int, \Closure> k => original (kept alive) */
    private static array $originals = [];

    /** @var array<int, int> spl_object_id of a test object => k */
    private static array $implIds = [];

    /** @var array<int, object> */
    private static array $implObjects = [];

    /** @var array<int, list<string>> */
    private static array $impls = [];

    /** @var list<string> */
    public static array $problems = [];

    public static bool $soft = false;

    public static function begin(): void
    {
        self::$replay = new Replay();
        self::$events = [];
        self::$collectors = [];
        self::$frames = [];
        self::$fnIds = [];
        self::$wrappers = [];
        self::$originals = [];
        self::$implIds = [];
        self::$implObjects = [];
        self::$impls = [];
        self::$problems = [];
    }

    /**
     * @return array{events: list<array<string, mixed>>, impls: array<int, list<string>>}
     */
    public static function end(): array
    {
        return ['events' => self::$events, 'impls' => self::$impls];
    }

    public static function isLibrary(mixed $value): bool
    {
        if (!\is_object($value) || $value instanceof \Closure) {
            return false;
        }
        $class = $value::class;
        if (\str_starts_with($class, 'Utopia\\Query\\Tests\\Fixture\\QuotesIdentifiersHarness')) {
            return true;
        }

        return \str_starts_with($class, Replay::NS) && !\str_starts_with($class, 'Utopia\\Query\\Tests\\')
            && !\str_contains($class, '@anonymous');
    }

    public static function isLibraryClass(string $class): bool
    {
        $class = \ltrim($class, '\\');

        return (\str_starts_with($class, Replay::NS) && !\str_starts_with($class, 'Utopia\\Query\\Tests\\'))
            || $class === 'Utopia\\Query\\Tests\\Fixture\\QuotesIdentifiersHarness';
    }

    private static function relative(string $class): string
    {
        return Replay::relative(\ltrim($class, '\\'));
    }

    /**
     * Runs one library event: appends it, executes it with callbacks recorded
     * into it, and exports its result (or error) to number new objects.
     *
     * @param array<string, mixed> $event
     */
    private static function record(array $event, \Closure $run): mixed
    {
        self::$events[] = $event;
        $index = \count(self::$events) - 1;
        self::$collectors[] = [];
        self::$frames[] = 'event';
        try {
            $result = $run();
        } catch (\Throwable $error) {
            \array_pop(self::$frames);
            self::attach($index, \array_pop(self::$collectors));
            self::$replay->error($error);
            throw $error;
        }
        \array_pop(self::$frames);
        self::attach($index, \array_pop(self::$collectors));
        self::$replay->export($result);

        return $result;
    }

    /** @var list<list<array<string, mixed>>> callbacks of each executing event */
    private static array $collectors = [];

    /**
     * @param list<array<string, mixed>> $cbs
     */
    private static function attach(int $index, array $cbs): void
    {
        if ($cbs !== []) {
            self::$events[$index]['cb'] = $cbs;
        }
    }

    /** Keys that mean something to the runner or the codecs: an array using one is sent as pairs. */
    private const RESERVED = ['$ref', '$bytes', '$float', '$o', '$fn', '$impl', '$enum', '$std', '$throwable', '$arrayobject', '$generator', '$map'];

    public static function encode(mixed $value): mixed
    {
        if (\is_array($value)) {
            $out = [];
            $reserved = false;
            foreach ($value as $k => $v) {
                $out[$k] = self::encode($v);
                $reserved = $reserved || \in_array($k, self::RESERVED, true);
            }
            if ($reserved) {
                $pairs = [];
                foreach ($out as $k => $v) {
                    $pairs[] = [$k, $v];
                }

                return ['$map' => $pairs];
            }

            return $out;
        }
        if (\is_float($value) && !\is_finite($value)) {
            return ['$float' => \is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')];
        }
        if (\is_string($value) && !\mb_check_encoding($value, 'UTF-8')) {
            return ['$bytes' => \base64_encode($value)];
        }
        if (!\is_object($value)) {
            return $value;
        }
        if ($value instanceof \UnitEnum) {
            return ['$enum' => self::relative($value::class) . '::' . $value->name];
        }
        if ($value instanceof \Closure) {
            return ['$fn' => self::fnId($value)];
        }
        if ($value instanceof \stdClass) {
            $out = [];
            foreach ((array) $value as $k => $v) {
                $out[$k] = self::encode($v);
            }

            return ['$std' => $out];
        }
        if ($value instanceof \Throwable && !self::isLibrary($value) && self::$replay->idOf($value) === null) {
            return ['$throwable' => ['class' => '\\' . $value::class, 'message' => $value->getMessage(), 'code' => $value->getCode()]];
        }
        if (self::isLibrary($value) || $value instanceof \Throwable) {
            $id = self::$replay->idOf($value);
            if ($id === null) {
                self::$problems[] = 'unregistered ' . $value::class;

                return ['$o' => 0];
            }

            return ['$o' => $id];
        }
        if ($value instanceof \ArrayObject) {
            return ['$arrayobject' => self::encode($value->getArrayCopy())];
        }
        if ($value instanceof \Generator) {
            return ['$generator' => self::encode(self::$generators[\spl_object_id($value)] ?? [])];
        }
        $interfaces = \array_values(\array_filter(
            \class_implements($value) ?: [],
            fn (string $i) => self::isLibraryClass($i),
        ));
        if ($interfaces !== []) {
            return ['$impl' => self::implId($value, $interfaces)];
        }
        self::$problems[] = 'cannot encode ' . $value::class;

        return ['$o' => 0];
    }

    private static function fnId(\Closure $fn): int
    {
        $key = \spl_object_id($fn);
        if (isset(self::$fnIds[$key]) && self::$originals[self::$fnIds[$key]] === $fn) {
            return self::$fnIds[$key];
        }
        $k = \count(self::$originals) + 1;
        self::$fnIds[$key] = $k;
        self::$originals[$k] = $fn;
        self::$wrappers[$k] = static function (mixed ...$args) use ($k, $fn): mixed {
            return self::callback(['fn' => $k], $args, fn () => $fn(...$args));
        };

        return $k;
    }

    /**
     * @param list<string> $interfaces
     */
    private static function implId(object $object, array $interfaces): int
    {
        $key = \spl_object_id($object);
        if (isset(self::$implIds[$key]) && self::$implObjects[self::$implIds[$key]] === $object) {
            return self::$implIds[$key];
        }
        $k = \count(self::$implObjects) + 1;
        self::$implIds[$key] = $k;
        self::$implObjects[$k] = $object;
        \sort($interfaces);
        self::$impls[$k] = \array_map(self::relative(...), $interfaces);

        return $k;
    }

    /**
     * Replaces test closures in arguments by recording wrappers.
     */
    private static function wrap(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return self::$wrappers[self::fnId($value)];
        }
        if ($value instanceof \Generator) {
            return (static function (array $items) {
                foreach ($items as $k => $v) {
                    yield $k => $v;
                }
            })(self::$generators[\spl_object_id($value)] ?? []);
        }
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                if ($v instanceof \Closure || \is_array($v)) {
                    $value[$k] = self::wrap($v);
                }
            }
        }

        return $value;
    }

    /**
     * The library calling back into test code.
     *
     * @param array<string, mixed> $record fn or impl+m
     * @param list<mixed> $args
     */
    private static function callback(array $record, array $args, \Closure $body): mixed
    {
        $top = \count(self::$frames) - 1;
        if ($top < 0 || self::$frames[$top] !== 'event') {
            return $body();
        }
        self::$replay->export($args);
        $record['do'] = [];
        // Inside the callback, events go to its own list.
        $outer = self::$events;
        self::$events = [];
        self::$frames[] = 'cb';
        try {
            $result = $body();
        } finally {
            \array_pop(self::$frames);
            $record['do'] = self::$events;
            self::$events = $outer;
        }
        $record['ret'] = self::encode($result);
        if ($record['do'] === []) {
            unset($record['do']);
        }
        self::$collectors[\count(self::$collectors) - 1][] = $record;

        return $result;
    }

    /**
     * A method of a test object implementing library interfaces.
     *
     * @param list<mixed> $args
     */
    public static function implCall(object $object, string $method, array $args, \Closure $body): mixed
    {
        $top = \count(self::$frames) - 1;
        if ($top < 0 || self::$frames[$top] !== 'event') {
            return $body();
        }
        $interfaces = \array_values(\array_filter(\class_implements($object) ?: [], fn (string $i) => self::isLibraryClass($i)));

        return self::callback(['impl' => self::implId($object, $interfaces), 'm' => $method], $args, $body);
    }

    /** @var array<int, array<mixed>> generator => the items it yields */
    private static array $generators = [];

    /**
     * Generators in arguments are read once here, so the call and the
     * program both see their items.
     *
     * @param array<int|string, mixed> $args
     * @return array<int|string, mixed>
     */
    private static function prepare(array $args): array
    {
        foreach ($args as $k => $v) {
            if ($v instanceof \Generator) {
                $items = [];
                foreach ($v as $key => $item) {
                    $items[$key] = $item;
                }
                $fresh = (static function (array $items) {
                    foreach ($items as $key => $item) {
                        yield $key => $item;
                    }
                })($items);
                self::$generators[\spl_object_id($fresh)] = $items;
                $args[$k] = $fresh;
            }
        }

        return $args;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function m(mixed $target, string $method, array $args): mixed
    {
        $args = self::prepare($args);
        if (!self::isLibrary($target) && !($target instanceof \Throwable && self::$replay->idOf($target) !== null)) {
            return $target->{$method}(...$args);
        }

        self::symbol($target::class, $method);

        return self::record(
            ['call' => $method, 'on' => self::encode($target), 'args' => self::encode($args)],
            fn () => $target->{$method}(...self::wrap($args)),
        );
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function nm(mixed $target, string $method, array $args): mixed
    {
        return $target === null ? null : self::m($target, $method, $args);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function s(string $class, string $method, array $args): mixed
    {
        $args = self::prepare($args);
        if (!self::isLibraryClass($class)) {
            return $class::{$method}(...$args);
        }

        self::symbol($class, $method);

        return self::record(
            ['static' => $method, 'class' => self::relative($class), 'args' => self::encode($args)],
            fn () => $class::{$method}(...self::wrap($args)),
        );
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function n(string $class, array $args): object
    {
        $args = self::prepare($args);
        if (!self::isLibraryClass($class)) {
            return new $class(...$args);
        }

        self::symbol($class, '__construct');

        return self::record(
            ['new' => self::relative($class), 'args' => self::encode($args)],
            fn () => new $class(...self::wrap($args)),
        );
    }

    /** @var array<string, true> the library symbols the tests call, across tests */
    public static array $symbols = [];

    /**
     * Records `Class::method` as the declaring class or trait names it (what
     * the inventory of bin/compat lists).
     */
    private static function symbol(string $class, string $method): void
    {
        try {
            $reflection = new \ReflectionMethod(\ltrim($class, '\\'), $method);
        } catch (\ReflectionException) {
            return;
        }
        $declaring = $reflection->getDeclaringClass();
        $name = $declaring->getName();
        // The inventory lists a trait's method under the trait and under
        // each class using it.
        self::$symbols[$name . '::' . $method] = true;
        $file = $reflection->getFileName();
        if ($file !== $declaring->getFileName()) {
            $queue = [$declaring];
            while ($queue !== []) {
                $current = \array_shift($queue);
                foreach ($current->getTraits() as $trait) {
                    if ($trait->hasMethod($method) && $trait->getFileName() === $file) {
                        $name = $trait->getName();
                        $queue = [];
                        break;
                    }
                    $queue[] = $trait;
                }
            }
        }
        self::$symbols[$name . '::' . $method] = true;
    }

    public static function g(mixed $target, string $property): mixed
    {
        if (!self::isLibrary($target) && !$target instanceof \UnitEnum) {
            return $target->{$property};
        }
        if ($target instanceof \UnitEnum && !self::isLibraryClass($target::class)) {
            return $target->{$property};
        }

        return self::record(['get' => $property, 'on' => self::encode($target)], fn () => $target->{$property});
    }

    public static function ng(mixed $target, string $property): mixed
    {
        return $target === null ? null : self::g($target, $property);
    }

    public static function c(string $class, string $name): mixed
    {
        $value = \constant($class . '::' . $name);
        if (!self::isLibraryClass($class) || $value instanceof \UnitEnum) {
            return $value;
        }

        return self::record(['const' => $name, 'class' => self::relative($class)], fn () => $value);
    }

    public static function is(mixed $value, string $class): bool
    {
        if (!self::isLibrary($value) || !self::isLibraryClass($class)) {
            return $value instanceof $class;
        }

        return self::record(['is' => self::relative($class), 'on' => self::encode($value)], fn () => $value instanceof $class);
    }

    /**
     * @return array<string, string>
     */
    public static function impls(object|string $value): array
    {
        $result = \class_implements($value) ?: [];
        if (\is_object($value) && self::isLibrary($value)) {
            self::record(['implements' => self::encode($value)], function () use ($result) {
                $interfaces = \array_values($result);
                \sort($interfaces);

                return \array_map(self::relative(...), $interfaces);
            });
        }

        return $result;
    }

    public static function cls(object $value): string
    {
        if (self::isLibrary($value)) {
            self::record(['class' => self::encode($value)], fn () => self::relative($value::class));
        }

        return $value::class;
    }

    public static function cl(object $value): object
    {
        if (!self::isLibrary($value)) {
            return clone $value;
        }

        return self::record(['clone' => self::encode($value)], fn () => clone $value);
    }

    /**
     * assertSame/assertNotSame arguments: identity of two library objects is
     * an event.
     *
     * Returns $a; Rec::second() then returns $b.
     */
    public static function pair(mixed $a, mixed $b): mixed
    {
        if (\is_object($a) && \is_object($b) && (self::isLibrary($a) || self::isLibrary($b)) && !$a instanceof \UnitEnum) {
            self::record(['same' => [self::encode($a), self::encode($b)]], fn () => $a === $b);
        }
        self::$second = $b;

        return $a;
    }

    private static mixed $second = null;

    public static function second(): mixed
    {
        return self::$second;
    }

    public static function soft(mixed ...$args): void
    {
    }
}
