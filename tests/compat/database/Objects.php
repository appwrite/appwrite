<?php

namespace Tests\Compat\Database;

use Tests\Compat\Fault;
use Tests\Compat\Session;

/**
 * The objects the `object.*` operations store (`as`) and refer to
 * (`{"$obj": <name>}`), per session, and the class and argument decoding
 * they share with `database.*` (see ops/objects.php).
 */
final class Objects
{
    /** @var \WeakMap<Session, array<string, mixed>>|null each session's stored objects */
    private static ?\WeakMap $tables = null;

    /**
     * @return array<string, mixed>
     */
    public static function &table(Session $s): array
    {
        self::$tables ??= new \WeakMap();
        if (!isset(self::$tables[$s])) {
            self::$tables[$s] = [];
        }
        $table = &self::$tables[$s];

        return $table;
    }

    public static function store(Session $s, mixed $as, mixed $value): void
    {
        if ($as === null) {
            return;
        }
        $table = &self::table($s);
        $table[(string) $as] = $value;
    }

    /**
     * `{"$obj": <name>}` replaced by the stored object, anywhere in a value.
     */
    public static function resolve(mixed $value, Session $s): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\count($value) === 1 && \is_string($value['$obj'] ?? null)) {
            $table = &self::table($s);
            if (!\array_key_exists($value['$obj'], $table)) {
                throw new Fault("no stored object `{$value['$obj']}`");
            }

            return $table[$value['$obj']];
        }
        // A callback's steps resolve their own references when they run.
        if (array_keys($value) === ['steps'] && \is_array($value['steps']) && array_is_list($value['steps'])) {
            return $value;
        }

        return array_map(fn ($v) => self::resolve($v, $s), $value);
    }

    /**
     * The class a step names.
     */
    public static function class(mixed $name): string
    {
        if (!\is_string($name) || $name === '') {
            throw new Fault('`class` names a class of the library');
        }
        $class = Values::className($name);
        if (!class_exists($class) && !enum_exists($class) && !interface_exists($class)) {
            throw new Fault("no class `{$name}`");
        }

        return $class;
    }

    /**
     * Builds `$class` with named arguments decoded for its constructor
     * (callbacks as Core::invoke takes them: then a Called).
     *
     * @param array<string, mixed> $args
     */
    public static function make(string $class, array $args, Session $s): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            if ($args !== []) {
                throw new Fault("{$class} takes no constructor arguments");
            }

            return $reflection->newInstance();
        }
        if (!$constructor->isPublic()) {
            throw new Fault("{$class}'s constructor is not public: build it with a factory (object.call)");
        }
        return Core::invoke($class, '__construct', $args, $s);
    }

    /**
     * The object a step calls: `{"$obj": <name>}` or a value tag.
     */
    public static function target(mixed $spec, Session $s): object
    {
        $value = self::resolve($spec, $s);
        if (!\is_object($value)) {
            $value = Values::decode($value);
        }
        if (!\is_object($value)) {
            throw new Fault('`this` is {"$obj": <name>} or an object tag');
        }

        return $value;
    }
}
