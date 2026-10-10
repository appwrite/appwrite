<?php

namespace Tests\Compat;

/**
 * The public API of a PHP library, by reflection: every public, concrete
 * method a class or trait declares itself (`Class::method`) and every
 * function (`Namespace\function()`). Interfaces, abstract methods, engine
 * built-ins and object plumbing (__destruct, __clone, serialization hooks)
 * carry no behaviour of their own and are left out.
 */
final class Inventory
{
    private const PLUMBING = [
        '__destruct', '__clone', '__serialize', '__unserialize', '__sleep', '__wakeup', '__set_state', '__debugInfo',
    ];

    /**
     * @param list<string> $dirs Source directories, relative to the repository root.
     * @return list<string>
     */
    public static function take(array $dirs): array
    {
        $symbols = [];
        foreach ($dirs as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                [$classes, $functions] = self::declarations((string) file_get_contents($file->getPathname()));
                foreach ($classes as $class) {
                    foreach (self::methods($class) as $method) {
                        $symbols[] = $method;
                    }
                }
                foreach ($functions as $function) {
                    $symbols[] = $function . '()';
                }
            }
        }
        sort($symbols);

        return array_values(array_unique($symbols));
    }

    /**
     * Signature, docblock and location of each symbol (`Class::method` or
     * `function()`), for `bin/compat report`. Symbols that do not resolve
     * are left out.
     *
     * @param list<string> $symbols
     * @return array<string, array{signature: string, static: bool, params: list<array<string, mixed>>, returns: string, doc: string, classDoc: string, file: string, line: int}>
     */
    public static function docs(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $symbol) {
            try {
                if (\str_ends_with($symbol, '()')) {
                    $function = new \ReflectionFunction(\substr($symbol, 0, -2));
                    $classDoc = '';
                } else {
                    [$class, $method] = \explode('::', $symbol, 2) + [1 => ''];
                    $function = new \ReflectionMethod($class, $method);
                    $classDoc = (string) $function->getDeclaringClass()->getDocComment();
                }
            } catch (\Throwable) {
                continue;
            }
            $file = (string) $function->getFileName();
            $out[$symbol] = [
                'signature' => self::signature($function),
                'static' => $function instanceof \ReflectionMethod && $function->isStatic(),
                'params' => self::parameters($function),
                'returns' => $function->hasReturnType() ? (string) $function->getReturnType() : '',
                'doc' => (string) $function->getDocComment(),
                'classDoc' => $classDoc,
                'file' => \str_starts_with($file, '/usr/src/code/') ? \substr($file, \strlen('/usr/src/code/')) : $file,
                'line' => (int) $function->getStartLine(),
            ];
        }

        return $out;
    }

    /**
     * Each parameter: name, declared type, default (as written) and flags.
     *
     * @return list<array<string, mixed>>
     */
    private static function parameters(\ReflectionFunctionAbstract $function): array
    {
        $out = [];
        foreach ($function->getParameters() as $parameter) {
            $default = null;
            try {
                if ($parameter->isDefaultValueAvailable()) {
                    $default = $parameter->isDefaultValueConstant()
                        ? (string) $parameter->getDefaultValueConstantName()
                        : self::literal($parameter->getDefaultValue());
                }
            } catch (\Throwable) {
            }
            $out[] = [
                'name' => $parameter->getName(),
                'type' => $parameter->hasType() ? (string) $parameter->getType() : '',
                'default' => $default,
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
                'reference' => $parameter->isPassedByReference(),
            ];
        }

        return $out;
    }

    private static function signature(\ReflectionFunctionAbstract $function): string
    {
        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $text = $parameter->hasType() ? $parameter->getType() . ' ' : '';
            $text .= ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
            try {
                if ($parameter->isDefaultValueAvailable()) {
                    $default = $parameter->isDefaultValueConstant()
                        ? (string) $parameter->getDefaultValueConstantName()
                        : $parameter->getDefaultValue();
                    $text .= ' = ' . (\is_string($default) && $parameter->isDefaultValueConstant() ? $default : self::literal($default));
                }
            } catch (\Throwable) {
            }
            $parameters[] = $text;
        }
        $modifiers = $function instanceof \ReflectionMethod
            ? \implode(' ', \Reflection::getModifierNames($function->getModifiers())) . ' '
            : '';
        $return = $function->hasReturnType() ? ': ' . $function->getReturnType() : '';

        return $modifiers . 'function ' . $function->getName() . '(' . \implode(', ', $parameters) . ')' . $return;
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            $value === [] => '[]',
            \is_array($value) => '[…]',
            \is_object($value) => 'new ' . $value::class . '(…)',
            default => \var_export($value, true),
        };
    }

    /**
     * @return list<string>
     */
    private static function methods(string $class): array
    {
        try {
            if (!class_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                return [];
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "inventory: cannot load {$class}: {$e->getMessage()}\n");

            return [];
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isInterface()) {
            return [];
        }
        $out = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (
                $method->getDeclaringClass()->getName() !== $reflection->getName()
                || $method->isAbstract()
                || $method->isInternal()
                || \in_array($method->getName(), self::PLUMBING, true)
            ) {
                continue;
            }
            $out[] = $reflection->getName() . '::' . $method->getName();
        }

        return $out;
    }

    /**
     * Class-like and function names a file declares.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function declarations(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $namespace = '';
        $classes = [];
        $functions = [];
        $depth = 0;
        $classDepth = null;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(T_NAMESPACE)) {
                $name = '';
                for ($j = $i + 1; $j < $count && !$tokens[$j]->is([';', '{']); $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $name .= $tokens[$j]->text;
                    }
                }
                $namespace = $name;
                continue;
            }
            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if ($token->is('}')) {
                $depth--;
                if ($classDepth !== null && $depth === $classDepth) {
                    $classDepth = null;
                }
                continue;
            }
            if ($token->is([T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE])) {
                // `Foo::class` and anonymous classes are not declarations.
                $previous = self::previous($tokens, $i);
                if ($previous !== null && $previous->is([T_DOUBLE_COLON, T_NEW])) {
                    continue;
                }
                $next = self::next($tokens, $i);
                if ($next !== null && $next->is(T_STRING)) {
                    $classes[] = ltrim($namespace . '\\' . $next->text, '\\');
                    $classDepth ??= $depth;
                }
                continue;
            }
            if ($token->is(T_FUNCTION) && $classDepth === null) {
                $next = self::next($tokens, $i);
                if ($next !== null && $next->is(T_STRING)) {
                    $functions[] = ltrim($namespace . '\\' . $next->text, '\\');
                }
            }
        }

        return [$classes, $functions];
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function next(array $tokens, int $i): ?\PhpToken
    {
        for ($j = $i + 1; $j < \count($tokens); $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function previous(array $tokens, int $i): ?\PhpToken
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
