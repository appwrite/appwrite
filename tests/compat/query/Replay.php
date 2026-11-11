<?php

namespace Tests\Compat\Query;

use Tests\Compat\Fault;

/**
 * Replays a program: the calls a PHP test made on the library, recorded by
 * tests/compat/query/record (see its README). Generic glue: every event is
 * one call on the library (`new`, a method, a static method, a property
 * read, a constant, `instanceof`, `class_implements`, identity, `clone`),
 * and every result is exported to JSON. Objects the library returns are
 * numbered in the order they first appear in the exported results, so a
 * later event names them by that number; the Rust adapter numbers them the
 * same way.
 *
 * Values in a program: JSON, plus `{"$o": n}` (object n), `{"$fn": k}`
 * (closure k of the test), `{"$impl": k}` (object k of a test class
 * implementing library interfaces, declared in `impls`), `{"$enum":
 * "Class::Case"}` and `{"$std": {...}}` (stdClass). Classes are relative to
 * `Utopia\Query\`.
 *
 * When the library calls back into the test (a closure, or a method of a
 * test object), the event lists those invocations in `cb`, in order: the
 * calls the test made inside each (`do`) and what it returned (`ret`). The
 * output then has `{"$cb": "fn:k" | "impl:k:method", "args": [...]}` before
 * the event's own result.
 */
final class Replay
{
    public const string NS = 'Utopia\\Query\\';

    /** @var array<int, object> export id => object */
    private array $objects = [];

    /** @var array<int, int> spl_object_id => export id */
    private array $ids = [];

    /** @var array<int, \Closure> */
    private array $fns = [];

    /** @var array<int, object> */
    private array $impls = [];

    /** @var array<int, list<string>> impl id => interfaces */
    private array $declared = [];

    /** @var list<mixed> */
    private array $out = [];

    /** @var list<array{list: list<array<string, mixed>>, pos: int}> */
    private array $queues = [];

    /**
     * @param list<array<string, mixed>> $events
     * @param array<int|string, list<string>> $impls
     * @return list<mixed>
     */
    public function run(array $events, array $impls = []): array
    {
        foreach ($impls as $k => $interfaces) {
            $this->declared[(int) $k] = $interfaces;
        }
        foreach ($events as $event) {
            $this->event($event);
        }

        return $this->out;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function event(array $event): void
    {
        $this->queues[] = ['list' => $event['cb'] ?? [], 'pos' => 0];
        try {
            $result = $this->exec($event);
            $output = $this->export($result);
        } catch (Fault $fault) {
            throw $fault;
        } catch (\Throwable $error) {
            $output = $this->error($error);
        }
        $queue = \array_pop($this->queues);
        $left = \count($queue['list']) - $queue['pos'];
        if ($left > 0) {
            $this->out[] = ['$cb_missing' => $left];
        }
        $this->out[] = $output;
    }

    /**
     * @return array<string, mixed>
     */
    public function error(\Throwable $error): array
    {
        return ['$error' => ['class' => $error::class, 'message' => $error->getMessage()], 'id' => $this->register($error)];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function exec(array $event): mixed
    {
        if (isset($event['new'])) {
            $class = self::class($event['new']);

            return new $class(...$this->args($event['args'] ?? []));
        }
        if (isset($event['call'])) {
            $target = $this->decode($event['on']);
            $method = (string) $event['call'];

            return $target->{$method}(...$this->args($event['args'] ?? []));
        }
        if (isset($event['static'])) {
            $class = self::class($event['class']);
            $method = (string) $event['static'];

            return $class::{$method}(...$this->args($event['args'] ?? []));
        }
        if (isset($event['get'])) {
            $target = $this->decode($event['on']);
            $property = (string) $event['get'];

            return $target->{$property};
        }
        if (isset($event['const'])) {
            return \constant(self::class($event['class']) . '::' . $event['const']);
        }
        if (isset($event['is'])) {
            $class = self::class($event['is']);

            return $this->decode($event['on']) instanceof $class;
        }
        if (\array_key_exists('implements', $event)) {
            $interfaces = \array_values(\class_implements($this->decode($event['implements'])) ?: []);
            \sort($interfaces);

            return \array_map(self::relative(...), $interfaces);
        }
        if (isset($event['same'])) {
            $a = $this->decode($event['same'][0]);
            $b = $this->decode($event['same'][1]);
            // Value objects have no identity to compare: their state is.
            if (\is_object($a) && \is_object($b) && self::isValue($a) && self::isValue($b)) {
                return $this->shape($a) === $this->shape($b);
            }

            return $a === $b;
        }
        if (\array_key_exists('clone', $event)) {
            $object = $this->decode($event['clone']);

            return clone $object;
        }
        if (\array_key_exists('class', $event)) {
            return self::relative(\get_class($this->decode($event['class'])));
        }

        throw new Fault('unknown event: ' . \json_encode($event));
    }

    /**
     * @param array<int|string, mixed> $args
     * @return array<int|string, mixed>
     */
    private function args(array $args): array
    {
        $out = [];
        foreach ($args as $key => $value) {
            $out[$key] = $this->decode($value);
        }

        return $out;
    }

    public static function class(string $name): string
    {
        return \str_starts_with($name, '\\') ? \substr($name, 1) : self::NS . $name;
    }

    public static function relative(string $class): string
    {
        return \str_starts_with($class, self::NS) ? \substr($class, \strlen(self::NS)) : '\\' . $class;
    }

    public function decode(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\count($value) === 1) {
            $key = \array_key_first($value);
            switch ($key) {
                case '$o':
                    return $this->objects[(int) $value['$o']] ?? throw new Fault('unknown object ' . $value['$o']);
                case '$fn':
                    return $this->closure((int) $value['$fn']);
                case '$impl':
                    return $this->impl((int) $value['$impl']);
                case '$enum':
                    return \constant(self::class((string) $value['$enum']));
                case '$map':
                    $out = [];
                    foreach ($value['$map'] as [$k, $v]) {
                        $out[$k] = $this->decode($v);
                    }

                    return $out;
                case '$arrayobject':
                    return new \ArrayObject($this->decode($value['$arrayobject']));
                case '$generator':
                    $items = $this->decode($value['$generator']);

                    return (static function (array $items) {
                        foreach ($items as $key => $item) {
                            yield $key => $item;
                        }
                    })($items);
                case '$throwable':
                    $class = self::class((string) $value['$throwable']['class']);

                    return new $class((string) $value['$throwable']['message'], (int) $value['$throwable']['code']);
                case '$std':
                    $object = new \stdClass();
                    foreach ((array) $value['$std'] as $k => $v) {
                        $object->{$k} = $this->decode($v);
                    }

                    return $object;
            }
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->decode($v);
        }

        return $out;
    }

    public function export(mixed $value): mixed
    {
        return $this->exportValue($value, true);
    }

    /**
     * The exported form without object numbers (and without numbering
     * anything): what two values are compared by.
     */
    public function shape(mixed $value): mixed
    {
        return $this->exportValue($value, false);
    }

    private function exportValue(mixed $value, bool $number): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->exportValue($v, $number);
            }

            return $out;
        }
        if (!\is_object($value)) {
            return $value;
        }
        if ($value instanceof \UnitEnum) {
            return ['$enum' => self::relative($value::class) . '::' . $value->name];
        }
        if ($value instanceof \stdClass) {
            $out = [];
            foreach ((array) $value as $k => $v) {
                $out[$k] = $this->exportValue($v, $number);
            }

            return ['$std' => $out];
        }
        foreach ($this->impls as $k => $impl) {
            if ($impl === $value) {
                return ['$impl' => $k];
            }
        }
        foreach ($this->fns as $k => $fn) {
            if ($fn === $value) {
                return ['$fn' => $k];
            }
        }
        if (self::isValue($value)) {
            $out = ['$value' => self::relative($value::class)];
            if ($number) {
                $out['id'] = $this->register($value);
            }
            $out['props'] = $this->exportValue(self::props($value), $number);

            return $out;
        }

        return $number
            ? ['$object' => self::relative($value::class), 'id' => $this->register($value)]
            : ['$object' => self::relative($value::class)];
    }

    /** @var array<string, bool> */
    private static array $valueClasses = [];

    /**
     * Value objects (queries, statements, tokens, AST nodes, exceptions:
     * every readonly class) are compared by their state, and numbered anew
     * each time they appear. Other objects (builders, schemas, blueprints)
     * keep one number: their identity is part of their behaviour.
     */
    public static function isValue(object $object): bool
    {
        $class = $object::class;

        return self::$valueClasses[$class] ??= $object instanceof \Utopia\Query\Query
            || $object instanceof \Throwable
            || ((new \ReflectionClass($object))->isReadOnly() && \str_starts_with($class, self::NS));
    }

    /**
     * @return array<string, mixed>
     */
    private static function props(object $object): array
    {
        if ($object instanceof \Utopia\Query\Query) {
            return [
                'method' => $object->getMethod(),
                'attribute' => $object->getAttribute(),
                'values' => $object->getValues(),
                'alias' => $object->getAlias(),
                'onArray' => $object->onArray(),
                'attributeType' => $object->getAttributeType(),
            ];
        }
        if ($object instanceof \Throwable) {
            return ['message' => $object->getMessage(), 'code' => $object->getCode()];
        }

        return \get_object_vars($object);
    }

    public function register(object $object): int
    {
        $key = \spl_object_id($object);
        if (!self::isValue($object) && isset($this->ids[$key]) && ($this->objects[$this->ids[$key]] ?? null) === $object) {
            return $this->ids[$key];
        }
        $id = \count($this->objects) + 1;
        $this->objects[$id] = $object;
        $this->ids[$key] = $id;

        return $id;
    }

    /**
     * The id an object was last exported with, or null.
     */
    public function idOf(object $object): ?int
    {
        $id = $this->ids[\spl_object_id($object)] ?? null;

        return $id !== null && ($this->objects[$id] ?? null) === $object ? $id : null;
    }

    private function closure(int $k): \Closure
    {
        return $this->fns[$k] ??= function (mixed ...$args) use ($k): mixed {
            return $this->invoke('fn:' . $k, $args);
        };
    }

    private function impl(int $k): object
    {
        if (isset($this->impls[$k])) {
            return $this->impls[$k];
        }
        $interfaces = $this->declared[$k] ?? throw new Fault("undeclared test object {$k}");
        $methods = [];
        foreach ($interfaces as $interface) {
            foreach ((new \ReflectionClass(self::class($interface)))->getMethods() as $method) {
                $methods[$method->getName()] ??= $method;
            }
        }
        $code = '';
        foreach ($methods as $name => $method) {
            $params = [];
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->hasType() ? self::type($parameter->getType()) . ' ' : '';
                $params[] = $type . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName()
                    . ($parameter->isDefaultValueAvailable() ? ' = ' . \var_export($parameter->getDefaultValue(), true) : '');
            }
            $return = $method->hasReturnType() ? ': ' . self::type($method->getReturnType()) : '';
            $body = $return === ': void'
                ? "\$this->replay->invoke('impl:{$k}:{$name}', \\func_get_args());"
                : "return \$this->replay->invoke('impl:{$k}:{$name}', \\func_get_args());";
            $code .= "public function {$name}(" . \implode(', ', $params) . "){$return} { {$body} }\n";
        }
        $implements = \implode(', ', \array_map(fn (string $i) => '\\' . self::class($i), $interfaces));
        $object = eval("return new class(\$this) implements {$implements} { public function __construct(private \\Tests\\Compat\\Query\\Replay \$replay) {}\n{$code}};");

        return $this->impls[$k] = $object;
    }

    private static function type(?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            $builtin = $type->isBuiltin() || \in_array($name, ['self', 'static'], true);

            return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . ($builtin ? $name : '\\' . $name);
        }
        if ($type instanceof \ReflectionUnionType) {
            return \implode('|', \array_map(fn ($t) => self::type($t), $type->getTypes()));
        }

        return (string) $type;
    }

    /**
     * A call from the library into the test: the next invocation the
     * current event expects.
     *
     * @param list<mixed> $args
     */
    public function invoke(string $callee, array $args): mixed
    {
        $this->out[] = ['$cb' => $callee, 'args' => $this->export($args)];
        $top = \count($this->queues) - 1;
        $record = $top >= 0 ? ($this->queues[$top]['list'][$this->queues[$top]['pos']] ?? null) : null;
        if ($record === null || self::callee($record) !== $callee) {
            $this->out[] = ['$cb_unexpected' => $callee];

            return null;
        }
        $this->queues[$top]['pos']++;
        foreach ($record['do'] ?? [] as $event) {
            $this->event($event);
        }

        return $this->decode($record['ret'] ?? null);
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function callee(array $record): string
    {
        return isset($record['fn']) ? 'fn:' . $record['fn'] : 'impl:' . $record['impl'] . ':' . $record['m'];
    }
}
