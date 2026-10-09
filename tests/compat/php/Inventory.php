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
