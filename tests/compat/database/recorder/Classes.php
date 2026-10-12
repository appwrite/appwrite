<?php

namespace Tests\Compat\Database\Recorder;

/**
 * Generates the recording subclasses (RecordingDatabase, RecordingPostgres,
 * RecordingMariaDB, RecordingMySQL, RecordingAuthorization): every public, non-static, non-final method is
 * overridden to go through Recorder::call with the same signature.
 */
final class Classes
{
    public static function load(): void
    {
        $code = 'namespace Tests\\Compat\\Database\\Recorder;' . "\n"
            . self::subclass(\Utopia\Database\Database::class, 'RecordingDatabase', 'database', ['getAdapter', 'getAuthorization'])
            . self::subclass(\Utopia\Database\Adapter\Postgres::class, 'RecordingPostgres', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\MariaDB::class, 'RecordingMariaDB', 'adapter', [])
            . self::subclass(\Utopia\Database\Adapter\MySQL::class, 'RecordingMySQL', 'adapter', [])
            . self::subclass(\Utopia\Database\Validator\Authorization::class, 'RecordingAuthorization', 'authorization', ['isValid', 'getDescription', 'isArray', 'getType'])
            . <<<'PHP'
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
                        Recorder::peer($this, $adapter, $cache);
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
                $out .= ' = ' . var_export($p->getDefaultValue(), true);
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
            return implode('|', array_map(fn ($t) => self::type($t, $self), $type->getTypes()));
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
