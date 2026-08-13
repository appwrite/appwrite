<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases;

use PHPUnit\Framework\TestCase;

final class ActionParameterNamesTest extends TestCase
{
    private const ALLOWED_INHERITED_RENAMES = [
        'column' => 'attribute',
        'relatedTableId' => 'relatedCollectionId',
        'rowId' => 'documentId',
        'rows' => 'documents',
        'rowSecurity' => 'documentSecurity',
        'tableId' => 'collectionId',
        'total' => 'includeTotal',
    ];

    public function testInheritedActionCallbacksDoNotHideUnexpectedParameterRenames(): void
    {
        $errors = [];

        foreach ($this->phpFiles($this->root() . '/src/Appwrite/Platform/Modules/Databases/Http') as $file) {
            $source = \file_get_contents($file);
            if ($source === false || !\str_contains($source, '->callback($this->action(...))')) {
                continue;
            }

            if ($this->declaresAction($source)) {
                continue;
            }

            $parent = $this->parentPath($source);
            if ($parent === null || !\is_file($parent)) {
                continue;
            }

            $parentSource = \file_get_contents($parent);
            if ($parentSource === false) {
                continue;
            }

            $parentParameters = $this->actionParameters($parentSource);
            if ($parentParameters === []) {
                continue;
            }

            foreach ($this->declaredParameters($source) as $index => $parameter) {
                $parentParameter = $parentParameters[$index] ?? null;
                if ($parentParameter === null || $parameter === $parentParameter) {
                    continue;
                }

                if ((self::ALLOWED_INHERITED_RENAMES[$parameter] ?? null) === $parentParameter) {
                    continue;
                }

                $errors[] = \sprintf(
                    '%s inherits action() but declares param #%d as $%s; parent expects $%s. Override action() and delegate explicitly, or add an intentional allowlist entry.',
                    $this->relative($file),
                    $index + 1,
                    $parameter,
                    $parentParameter,
                );
            }
        }

        $this->assertSame([], $errors);
    }

    /**
     * @return string[]
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }

    private function declaresAction(string $source): bool
    {
        return \preg_match('/function\s+action\s*\(/', $source) === 1;
    }

    private function parentPath(string $source): ?string
    {
        if (\preg_match('/class\s+\w+\s+extends\s+(?<parent>\\?\w+)/', $source, $match) !== 1) {
            return null;
        }

        $parent = $match['parent'];
        $uses = $this->uses($source);
        $fqcn = $uses[$parent] ?? null;

        if ($fqcn === null) {
            return null;
        }

        $prefix = 'Appwrite\\';
        if (!\str_starts_with($fqcn, $prefix)) {
            return null;
        }

        return $this->root() . '/src/Appwrite/' . \str_replace('\\', '/', \substr($fqcn, \strlen($prefix))) . '.php';
    }

    /**
     * @return array<string, string>
     */
    private function uses(string $source): array
    {
        $uses = [];
        \preg_match_all('/^use\s+(?<fqcn>[^;]+);/m', $source, $matches);

        foreach ($matches['fqcn'] as $fqcn) {
            $parts = \preg_split('/\s+as\s+/i', \trim($fqcn));
            if ($parts === false) {
                continue;
            }

            if (\count($parts) === 2) {
                $uses[$parts[1]] = $parts[0];
                continue;
            }

            $segments = \explode('\\', $parts[0]);
            $uses[\end($segments)] = $parts[0];
        }

        return $uses;
    }

    /**
     * @return string[]
     */
    private function declaredParameters(string $source): array
    {
        \preg_match_all("/->param\\('(?<name>[^']+)'/", $source, $matches);

        return $matches['name'];
    }

    /**
     * @return string[]
     */
    private function actionParameters(string $source): array
    {
        if (\preg_match('/function\s+action\s*\((?<parameters>.*?)\)\s*:/s', $source, $match) !== 1) {
            return [];
        }

        $parameters = [];
        foreach ($this->splitArguments($match['parameters']) as $argument) {
            if (\preg_match('/\$(?<name>[A-Za-z_][A-Za-z0-9_]*)/', $argument, $argumentMatch) === 1) {
                $parameters[] = $argumentMatch['name'];
            }
        }

        return $parameters;
    }

    /**
     * @return string[]
     */
    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (\str_split($arguments) as $character) {
            if ($character === '(' || $character === '[') {
                ++$depth;
            }

            if ($character === ')' || $character === ']') {
                --$depth;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $character;
        }

        if (\trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 5);
    }

    private function relative(string $path): string
    {
        return \substr($path, \strlen($this->root()) + 1);
    }
}
