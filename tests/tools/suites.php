<?php

declare(strict_types=1);

// Validate PHPUnit's source inventory without booting the application or running data providers.
// Usage: php tests/tools/suites.php phpunit.xml [suite-prefix|--groups]
require getcwd() . '/vendor/autoload.php';

use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

try {
    $configuration = realpath($argv[1] ?? 'phpunit.xml');
    if ($configuration === false) {
        throw new RuntimeException('PHPUnit configuration does not exist');
    }
    $root = dirname($configuration);
    $xml = simplexml_load_file($configuration, options: LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException('Cannot read PHPUnit configuration');
    }
    $suites = [];
    $membership = [];
    $groups = [];
    foreach ($xml->testsuites->testsuite as $suite) {
        $name = (string) $suite['name'];
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name) || isset($suites[$name])) {
            throw new RuntimeException('Invalid or duplicate suite name: ' . $name);
        }
        $suites[$name] = [];
        $groups[$name] = [];
        $excluded = [];
        foreach ($suite->exclude as $path) {
            $resolved = realpath($root . '/' . $path);
            if ($resolved === false) {
                throw new RuntimeException('Stale PHPUnit exclusion: ' . $path);
            }
            $excluded[] = $resolved;
        }
        foreach ($suite->children() as $entry) {
            if (!in_array($entry->getName(), ['directory', 'file'], true)) {
                continue;
            }
            $groups[$name] = array_values(array_unique([...$groups[$name], ...array_filter(array_map('trim', explode(',', (string) $entry['groups'])))]));
            $path = realpath($root . '/' . $entry);
            if ($path === false) {
                throw new RuntimeException('Missing PHPUnit path: ' . $entry);
            }
            $files = is_dir($path)
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
                : [new SplFileInfo($path)];
            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $filePath = $file->getRealPath();
                if ($entry->getName() === 'directory'
                    && (!str_ends_with($file->getFilename(), (string) ($entry['suffix'] ?? 'Test.php'))
                        || !str_starts_with($file->getFilename(), (string) ($entry['prefix'] ?? '')))) {
                    continue;
                }
                foreach ($excluded as $exclude) {
                    if ($filePath === $exclude || str_starts_with($filePath, $exclude . '/')) {
                        continue 2;
                    }
                }
                if (isset($membership[$filePath])) {
                    throw new RuntimeException('Test selected twice: ' . $filePath . ' (' . $membership[$filePath] . ', ' . $name . ')');
                }
                $membership[$filePath] = $name;
                $suites[$name][] = $filePath;
            }
        }
        if ($suites[$name] === []) {
            throw new RuntimeException('Empty PHPUnit suite: ' . $name);
        }
    }

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    $classes = [];
    foreach (['unit', 'e2e'] as $directory) {
        $path = $root . '/tests/' . $directory;
        if (!is_dir($path)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            // Scanner fixtures deliberately contain invalid test shapes and are not executable tests.
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Fixtures/')) {
                continue;
            }
            $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse(file_get_contents($file->getPathname())) ?? []);
            foreach ($finder->findInstanceOf($nodes, Class_::class) as $class) {
                if ($class->isAnonymous()) {
                    continue;
                }
                $classes[$class->namespacedName->toString()] = [
                    'parent' => $class->extends?->toString(),
                    'abstract' => $class->isAbstract(),
                    'file' => $file->getRealPath(),
                ];
            }
        }
    }
    foreach ($classes as $name => $class) {
        if ($class['abstract']) {
            continue;
        }
        $parent = $class['parent'];
        $seen = [];
        while ($parent !== null && $parent !== TestCase::class && isset($classes[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $parent = $classes[$parent]['parent'];
        }
        if ($parent !== TestCase::class && ($parent === null || !is_subclass_of($parent, TestCase::class))) {
            continue;
        }
        if (!str_ends_with($class['file'], 'Test.php')) {
            throw new RuntimeException('Undiscoverable test class ' . $name . ': rename ' . $class['file'] . ' to *Test.php');
        }
        $parts = explode('\\', $name);
        if (end($parts) !== basename($class['file'], '.php')) {
            throw new RuntimeException('Test class/file mismatch: ' . $name . ' in ' . $class['file']);
        }
        if (!isset($membership[$class['file']])) {
            throw new RuntimeException('No PHPUnit suite selects ' . $class['file']);
        }
    }
    $names = array_keys($suites);
    sort($names);
    ksort($groups);
    $withGroups = ($argv[2] ?? '') === '--groups';
    $prefix = $withGroups ? '' : ($argv[2] ?? '');
    $names = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, $prefix)));
    if ($names === []) {
        throw new RuntimeException('No suites match prefix: ' . $prefix);
    }
    echo json_encode($withGroups ? $groups : $names, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
