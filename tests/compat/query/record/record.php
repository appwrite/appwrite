<?php

/**
 * Ports packages/query/tests to compat cases: runs every test with its calls
 * on the library recorded (see Rewriter and Rec), and writes one case per
 * test (and data set) to tests/compat/query/cases/<Dir>.<Test>.json.
 *
 * Tests in tests/compat/query/record/Extra (namespace
 * Utopia\Query\Tests\Extra) call the API the PHP tests leave out; they are
 * recorded the same way, as cases/Extra.<Test>.json.
 *
 *   bin/compat-php tests/compat/query/record/record.php [<test file relative to packages/query/tests, or Extra/<Test>.php>...]
 *
 * Expectations are not written here: `bin/compat record query` replays the
 * cases on PHP and stores the results.
 */

namespace Tests\Compat\Query\Record;

require __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../php/Fault.php';
require_once __DIR__ . '/../Replay.php';
require_once __DIR__ . '/Rec.php';
require_once __DIR__ . '/Rewriter.php';
require_once __DIR__ . '/Offline.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

$root = \realpath(__DIR__ . '/../../../..');
$tests = $root . '/packages/query/tests';
$out = $root . '/tests/compat/query/cases';

\spl_autoload_register(static function (string $class) use ($tests): void {
    $prefix = 'Utopia\\Query\\Tests\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    if ($relative === 'E2E/IntegrationTestCase') {
        require __DIR__ . '/IntegrationTestCase.php';

        return;
    }
    $file = source($relative, $tests);
    if (!\is_file($file)) {
        return;
    }
    $code = Rewriter::rewrite((string) \file_get_contents($file), \str_starts_with($relative, 'E2E/'));
    $tmp = \sys_get_temp_dir() . '/compat-query-record/' . $relative . '.php';
    @\mkdir(\dirname($tmp), 0777, true);
    \file_put_contents($tmp, $code);
    require $tmp;
}, true, true);

/** The file of a test, relative to packages/query/tests (or Extra/ for the extra tests). */
function source(string $relative, string $tests): string
{
    return \str_starts_with($relative, 'Extra/') ? __DIR__ . '/' . $relative . '.php' : $tests . '/' . $relative . '.php';
}

$files = \array_slice($argv, 1);
if ($files === []) {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (\str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = \substr($file->getPathname(), \strlen($tests) + 1);
        }
    }
    foreach (\glob(__DIR__ . '/Extra/*Test.php') ?: [] as $file) {
        $files[] = 'Extra/' . \basename($file);
    }
    \sort($files);
}

/** The compat op a test file's cases use, by area. */
function op(string $relative): string
{
    $relative = \preg_replace('#^E2E/#', '', $relative);
    if (\str_starts_with($relative, 'Extra/')) {
        return match (true) {
            \str_starts_with($relative, 'Extra/Builder') => 'builder',
            \str_starts_with($relative, 'Extra/Schema') => 'schema',
            \str_starts_with($relative, 'Extra/Visitor') => 'ast',
            default => 'query',
        };
    }

    return match (true) {
        \str_starts_with($relative, 'Builder/') => 'builder',
        \str_starts_with($relative, 'Schema/') => 'schema',
        \str_starts_with($relative, 'AST/') => 'ast',
        \str_starts_with($relative, 'Tokenizer/') => 'tokenizer',
        \str_starts_with($relative, 'Classifier/') => 'classifier',
        \str_starts_with($relative, 'Hook/') => 'hook',
        default => 'query',
    };
}

$summary = [];
$masks = \json_decode((string) \file_get_contents(__DIR__ . '/masks.json'), true, flags: JSON_THROW_ON_ERROR);
/** @var array<string, array<string, true>> test file => symbols its cases call */
$covers = [];
foreach ($files as $relative) {
    $class = 'Utopia\\Query\\Tests\\' . \str_replace('/', '\\', \substr($relative, 0, -4));
    $reflection = new \ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }
    $soft = \str_starts_with($relative, 'E2E/');
    $cases = [];
    $failures = [];
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if (!\str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $performance = false;
        foreach ($method->getAttributes(Group::class) as $attribute) {
            if (($attribute->getArguments()[0] ?? '') === 'performance') {
                $performance = true;
            }
        }
        if ($performance) {
            continue;
        }
        $sets = ["" => []];
        $providerMethod = null;
        foreach ($method->getAttributes(DataProvider::class) as $attribute) {
            $providerMethod = $reflection->getMethod($attribute->getArguments()[0]);
        }
        // The provider runs inside each data set's recording: the objects it
        // builds are then numbered in the program.
        $provide = static function () use ($providerMethod, $reflection, $method): array {
            $data = $providerMethod->isStatic() ? $providerMethod->invoke(null) : $providerMethod->invoke($reflection->newInstance($method->getName()));
            $sets = [];
            foreach ($data as $key => $set) {
                $sets[$key] = $set;
            }

            return $sets;
        };
        if ($providerMethod !== null) {
            Rec::begin();
            $sets = $provide();
        }
        foreach (\array_keys($sets) as $key) {
            $name = $method->getName() . ($providerMethod === null ? '' : (\is_int($key) ? " with data set #{$key}" : " with data set \"{$key}\""));
            Rec::begin();
            Rec::$soft = $soft;
            $set = $providerMethod === null ? [] : $provide()[$key];
            $status = 'ok';
            $message = '';
            $test = $reflection->newInstance($method->getName());
            \set_error_handler(static function (int $no, string $str) use ($soft): bool {
                return $soft;
            });
            try {
                \Closure::bind(fn () => $this->setUp(), $test, TestCase::class)();
                $method->invokeArgs($test, \array_values($set));
                \Closure::bind(fn () => $this->tearDown(), $test, TestCase::class)();
            } catch (\PHPUnit\Framework\SkippedTest $e) {
                $status = 'skipped';
                $message = $e->getMessage();
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $status = 'fail';
                $message = $e->getMessage();
            } catch (\Throwable $e) {
                $expected = \Closure::bind(fn () => $this->expectedException ?? null, $test, TestCase::class)();
                if ($expected !== null && $e instanceof $expected) {
                    $status = 'ok';
                } else {
                    $status = 'error';
                    $message = $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
                }
            }
            \restore_error_handler();
            $trace = Rec::end();
            if (Rec::$problems !== []) {
                $status = $status === 'ok' ? 'problem' : $status;
                $message .= ' [' . \implode('; ', \array_unique(Rec::$problems)) . ']';
            }
            if ($status !== 'ok') {
                $failures[] = "{$name}: {$status} {$message}";
            }
            if ($trace['events'] === [] || $status === 'skipped') {
                continue;
            }
            $args = ['program' => $trace['events']];
            if ($trace['impls'] !== []) {
                $args['impls'] = $trace['impls'];
            }
            $case = ['name' => $name, 'op' => op($relative), 'args' => $args];
            // Outputs that legitimately differ (see masks.json) are masked.
            if (isset($masks[$relative . '#' . $name])) {
                $case['mask'] = $masks[$relative . '#' . $name];
            }
            $cases[] = $case;
            foreach (\array_keys(Rec::$symbols) as $symbol) {
                $covers[$relative][$symbol] = true;
            }
            Rec::$symbols = [];
        }
    }
    // A file whose cases call no symbol of the inventory replaces its old entry too.
    $covers[$relative] ??= [];
    $target = $out . '/' . \str_replace('/', '.', \substr($relative, 0, -4)) . '.json';
    $previous = \is_file($target) ? \json_decode((string) \file_get_contents($target), true) : null;
    // Keep expectations recorded earlier for programs that did not change.
    if (\is_array($previous)) {
        $expects = [];
        foreach ($previous['cases'] ?? [] as $case) {
            if (isset($case['expect'])) {
                $expects[$case['name']] = [$case['args'], $case['expect']];
            }
        }
        foreach ($cases as &$case) {
            if (isset($expects[$case['name']]) && $expects[$case['name']][0] == $case['args']) {
                $case['expect'] = $expects[$case['name']][1];
            }
        }
        unset($case);
    }
    $json = \json_encode(
        ['source' => (\str_starts_with($relative, 'Extra/') ? 'tests/compat/query/record/' : 'packages/query/tests/') . $relative, 'cases' => $cases],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    \file_put_contents($target, $json . "\n");
    $summary[] = \sprintf('%-60s %4d cases %3d not clean', $relative, \count($cases), \count($failures));
    foreach ($failures as $failure) {
        $summary[] = '    ' . $failure;
    }
}
// The symbols each file's cases call: tests/compat/query/record/covers.php
// turns them into the `covers` of each op in spec.json.
$coversFile = $root . '/tests/compat/query/covers.json';
$all = \is_file($coversFile) ? \json_decode((string) \file_get_contents($coversFile), true) : [];
foreach ($covers as $file => $symbols) {
    $list = \array_keys($symbols);
    \sort($list);
    $all[$file] = ['op' => op($file), 'symbols' => $list];
}
\ksort($all);
\file_put_contents($coversFile, \json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo \implode("\n", $summary), "\n";
