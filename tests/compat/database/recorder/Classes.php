<?php

namespace Tests\Compat\Database\Recorder;

/**
 * Generates the recording subclasses (RecordingDatabase, RecordingMirror, the
 * recording adapters and RecordingAuthorization): every public, non-static,
 * non-final method is overridden to go through Recorder::call with the same
 * signature. RecordingProfiler wraps the Profiler a recording Database hands
 * out, its calls recorded as `db.profiler`.
 */
final class Classes
{
    public static function load(): void
    {
        $code = 'namespace Tests\\Compat\\Database\\Recorder;' . "\n"
            . self::subclass(\Utopia\Database\Database::class, 'RecordingDatabase', 'database', ['getAdapter', 'getAuthorization'])
            . self::subclass(\Utopia\Database\Adapter\Postgres::class, 'RecordingPostgres', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\Pool::class, 'RecordingPool', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\Redis::class, 'RecordingRedis', 'adapter', [])
            . self::subclass(\Utopia\Database\Mirror::class, 'RecordingMirror', 'database', ['getAdapter', 'getAuthorization'])
            . self::subclass(\Utopia\Database\Adapter\SQLite::class, 'RecordingSQLite', 'adapter', [])
            . self::subclass(\Tests\Compat\Database\Fixture\EmulatedSQLite::class, 'RecordingEmulatedSQLite', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\Memory::class, 'RecordingMemory', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\MariaDB::class, 'RecordingMariaDB', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\MySQL::class, 'RecordingMySQL', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\Mongo::class, 'RecordingMongo', 'adapter', [])
            . self::subclass(\Utopia\Database\Validator\Authorization::class, 'RecordingAuthorization', 'authorization', ['isValid', 'getDescription', 'isArray', 'getType', 'restore'])
            . self::proxy(\Utopia\Database\Profiler::class, 'RecordingProfiler', 'profiler')
            . <<<'PHP'
                /**
                 * A Memory adapter the tests construct themselves (`new
                 * Memory()` in their source is rewritten to this): a detached
                 * database of its own.
                 */
                class PeerMemory extends RecordingMemory
                {
                    public function __construct()
                    {
                        parent::__construct();
                        Recorder::freshAdapter($this, 'memory');
                    }
                }

                /**
                 * A Database the tests construct themselves (`new Database(...)`
                 * in their source is rewritten to this): recorded as a peer.
                 */
                class PeerDatabase extends RecordingDatabase
                {
                    public ?int $opened = null;

                    public function __construct(\Utopia\Database\Adapter $adapter, \Utopia\Cache\Cache $cache, array $filters = [])
                    {
                        Recorder::$depth++;
                        try {
                            parent::__construct($adapter, $cache, $filters);
                        } finally {
                            Recorder::$depth--;
                        }
                        Recorder::peer($this, $adapter, $cache, $filters);
                    }
                }
                PHP;
        eval($code);
    }

    /**
     * @param class-string $parent
     * @param list<string> $skip
     */
    private static function subclass(string $parent, string $name, string $kind, array $skip): string
    {
        $out = "class {$name} extends \\{$parent}\n{\n";
        foreach ((new \ReflectionClass($parent))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $m = $method->getName();
            if ($method->isConstructor() || $method->isFinal() || $method->isAbstract() || str_starts_with($m, '__') || \in_array($m, $skip, true)) {
                continue;
            }
            // func_get_args() would break the references of by-reference parameters.
            foreach ($method->getParameters() as $p) {
                if ($p->isPassedByReference()) {
                    continue 2;
                }
            }
            if ($method->isStatic()) {
                if ($parent === \Utopia\Database\Database::class && $m === 'addFilter') {
                    $out .= "    public static function addFilter(string \$name, callable \$encode, callable \$decode): void\n    {\n"
                        . "        Recorder::addFilter(\$name, \$encode, \$decode, fn (...\$x) => parent::addFilter(...\$x));\n    }\n";
                }
                continue;
            }
            $params = implode(', ', array_map(self::parameter(...), $method->getParameters()));
            $returns = $method->hasReturnType() ? ': ' . self::type($method->getReturnType(), $parent) : '';
            $void = $method->hasReturnType() && \in_array((string) $method->getReturnType(), ['void', 'never'], true);
            $call = "Recorder::call(\$this, '{$kind}', '{$m}', \\func_get_args(), fn (...\$x) => parent::{$m}(...\$x))";
            $out .= "    public function {$m}({$params}){$returns}\n    {\n        " . ($void ? "{$call};" : "return {$call};") . "\n    }\n";
        }

        return $out . "}\n";
    }

    /**
     * A subclass of `$parent` wrapping an instance of it: every public,
     * non-static method goes to the wrapped one through Recorder::call
     * (returning the wrapper where the wrapped one returns itself).
     *
     * @param class-string $parent
     */
    private static function proxy(string $parent, string $name, string $kind): string
    {
        $out = "class {$name} extends \\{$parent}\n{\n    public function __construct(public readonly \\{$parent} \$inner)\n    {\n    }\n";
        foreach ((new \ReflectionClass($parent))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $m = $method->getName();
            if ($method->isConstructor() || $method->isStatic() || $method->isFinal() || str_starts_with($m, '__')) {
                continue;
            }
            $params = implode(', ', array_map(self::parameter(...), $method->getParameters()));
            $returns = $method->hasReturnType() ? ': ' . self::type($method->getReturnType(), $parent) : '';
            $void = $method->hasReturnType() && \in_array((string) $method->getReturnType(), ['void', 'never'], true);
            $call = "Recorder::call(\$this, '{$kind}', '{$m}', \\func_get_args(), fn (...\$x) => (\$r = \$this->inner->{$m}(...\$x)) === \$this->inner ? \$this : \$r)";
            $out .= "    public function {$m}({$params}){$returns}\n    {\n        " . ($void ? "{$call};" : "return {$call};") . "\n    }\n";
        }

        return $out . "}\n";
    }

    private static function parameter(\ReflectionParameter $p): string
    {
        $parent = $p->getDeclaringClass()?->getName() ?? '';
        $out = $p->hasType() ? self::type($p->getType(), $parent) . ' ' : '';
        if ($p->isPassedByReference()) {
            $out .= '&';
        }
        if ($p->isVariadic()) {
            $out .= '...';
        }
        $out .= '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            if ($p->isDefaultValueConstant()) {
                $constant = (string) $p->getDefaultValueConstantName();
                if (str_starts_with($constant, 'self::') || str_starts_with($constant, 'static::')) {
                    $constant = $parent . '::' . substr($constant, strpos($constant, '::') + 2);
                }
                $out .= ' = \\' . ltrim($constant, '\\');
            } else {
                $default = $p->getDefaultValue();
                $out .= ' = ' . ($default instanceof \UnitEnum ? '\\' . $default::class . '::' . $default->name : var_export($default, true));
            }
        }

        return $out;
    }

    private static function type(?\ReflectionType $type, string $self): string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn ($t) => $t instanceof \ReflectionIntersectionType ? '(' . self::type($t, $self) . ')' : self::type($t, $self), $type->getTypes()));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn ($t) => self::type($t, $self), $type->getTypes()));
        }
        /** @var \ReflectionNamedType $type */
        $name = $type->getName();
        $nullable = $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '';
        if ($name === 'self') {
            $name = '\\' . $self;
        } elseif (!$type->isBuiltin() && $name !== 'static') {
            $name = '\\' . $name;
        }

        return $nullable . $name;
    }
}
