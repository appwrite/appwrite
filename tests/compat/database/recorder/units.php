<?php

/**
 * Ports utopia-php/database's unit tests to compat cases by recording them.
 *
 *   bin/compat-php tests/compat/database/recorder/units.php [<path filter>[,<path filter>...]]
 *
 * Every `*Test.php` class under the package's tests/unit (those whose path
 * contains a filter, when given) runs outside PHPUnit's runner, test by test
 * (setUp, the test with each data set, tearDown), from a copy of its source
 * (and of every Tests\Unit file it loads) that Rewriter makes call the
 * library through the recorder. What test code does with the library becomes
 * steps: `object.new`, `object.call` (the `object.*` vocabulary of
 * ops/objects.php), each test a case `<Class>::<method>[#<data set>]` of
 * `cases/Unit-<path under tests/unit>.json`, self-contained (its own steps and
 * the earlier ones they refer to). A test that drives the library through
 * something a case cannot carry (a PHPUnit mock, a test's own subclass of a
 * library class, an anonymous class, code the library calls back that is not
 * a callback argument) is left out, with the reason, in
 * recorder/out/units.json (`unportable`), which spec.d/units.json's
 * `tests_waived` summarises per class.
 */

namespace Tests\Compat\Database\Recorder;

use Tests\Compat\Codec;

\ini_set('memory_limit', '-1');

$root = \dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
require_once $root . '/tests/compat/php/Fault.php';
require_once $root . '/tests/compat/php/Session.php';
require_once $root . '/tests/compat/php/Codec.php';
require_once $root . '/tests/compat/database/Values.php';
require_once $root . '/tests/compat/database/ops/core.php';
require_once __DIR__ . '/Recorder.php';
require_once __DIR__ . '/Classes.php';
require_once __DIR__ . '/Cases.php';
require_once __DIR__ . '/Rewriter.php';
require_once __DIR__ . '/Profile.php';

const UNITS = '/tmp/cxrecorder-units/';
$tests = $root . '/vendor/utopia-php/database/tests/unit/';

// Tests\Unit classes load from rewritten copies.
\spl_autoload_register(function (string $class) use ($tests) {
    if (!str_starts_with($class, 'Tests\\Unit\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, \strlen('Tests\\Unit\\'))) . '.php';
    $file = $tests . $relative;
    if (!is_file($file)) {
        return;
    }
    $copy = UNITS . $relative;
    @mkdir(\dirname($copy), 0777, true);
    file_put_contents($copy, Rewriter::rewrite(str_replace('__DIR__', var_export(\dirname($file), true), (string) file_get_contents($file))));
    require_once $copy;
});

Classes::load();

\PHPUnit\TextUI\Configuration\Registry::init(
    (new \PHPUnit\TextUI\CliArguments\Builder())->fromParameters([]),
    \PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create(),
);

$filters = isset($argv[1]) && $argv[1] !== '' ? explode(',', $argv[1]) : [];

$files = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = substr($file->getPathname(), \strlen($tests));
    if (!str_ends_with($path, 'Test.php') || str_starts_with($path, 'PHPStan/')) {
        continue;
    }
    // A filter ending in .php names one file; others match paths containing them.
    if ($filters !== [] && array_filter($filters, fn (string $f) => str_ends_with($f, '.php') ? $path === $f : str_contains($path, $f)) === []) {
        continue;
    }
    $files[] = $path;
}
sort($files);

/** @var list<array{name: string, file: string, method: string, status: string, message: string}> $recorded */
$recorded = [];
$unportable = [];
$skipped = [];
foreach ($files as $path) {
    $class = 'Tests\\Unit\\' . str_replace('/', '\\', substr($path, 0, -4));
    try {
        if (!class_exists($class)) {
            $unportable[$path]['*'] = 'no class ' . $class;
            continue;
        }
    } catch (\Throwable $e) {
        $unportable[$path]['*'] = 'the class does not load: ' . $e->getMessage();
        fwrite(STDERR, "unloadable {$path}: {$e->getMessage()}\n");
        continue;
    }
    $reflection = new \ReflectionClass($class);
    if ($reflection->isAbstract() || !$reflection->isSubclassOf(\PHPUnit\Framework\TestCase::class)) {
        continue;
    }
    $call = static fn (object $instance, string $method) => (fn () => $this->{$method}())->call($instance);
    if ($reflection->hasMethod('setUpBeforeClass')) {
        Recorder::$on = true;
        Recorder::$test = -1;
        try {
            $class::setUpBeforeClass();
        } catch (\Throwable $e) {
            fwrite(STDERR, "setUpBeforeClass of {$path} failed: {$e->getMessage()}\n");
        }
        Recorder::$on = false;
    }
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();
        if ($method->getDeclaringClass()->getName() !== $class && !str_starts_with($method->getDeclaringClass()->getName(), 'Tests\\Unit\\')) {
            continue;
        }
        $isTest = str_starts_with($name, 'test') || $method->getAttributes(\PHPUnit\Framework\Attributes\Test::class) !== [];
        if (!$isTest || $method->isStatic()) {
            continue;
        }
        $provider = null;
        foreach ($method->getAttributes(\PHPUnit\Framework\Attributes\DataProvider::class) as $attribute) {
            $provider = (string) $attribute->getArguments()[0];
        }
        $sets = ['' => []];
        if ($provider !== null) {
            // The provider's own steps (objects its sets hold) come into the
            // cases of the sets that use them.
            Recorder::$on = true;
            Recorder::$test = -1;
            try {
                $sets = [];
                $data = (new \ReflectionMethod($class, $provider))->isStatic() ? $class::$provider() : (new $class($provider))->{$provider}();
                foreach ($data as $key => $set) {
                    $sets[$key] = $set;
                }
            } catch (\Throwable $e) {
                $unportable[$path][$name] = 'its data provider failed: ' . $e->getMessage();
                continue;
            } finally {
                Recorder::$on = false;
            }
        }
        foreach ($sets as $key => $data) {
            $label = $name . ($provider !== null ? '#' . $key : '');
            $index = \count($recorded);
            $test = ['name' => $label, 'file' => $path, 'method' => $name, 'status' => 'passed', 'message' => ''];
            $instance = new $class($name);
            Recorder::$testProblems = [];
            Recorder::$on = true;
            Recorder::$test = $index;
            try {
                $call($instance, 'setUp');
                $instance->{$name}(...array_values((array) $data));
            } catch (\PHPUnit\Framework\SkippedTest $e) {
                $test['status'] = 'skipped';
                $test['message'] = $e->getMessage();
            } catch (\PHPUnit\Framework\IncompleteTest $e) {
                $test['status'] = 'incomplete';
                $test['message'] = $e->getMessage();
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $test['status'] = 'failed';
                $test['message'] = $e->getMessage();
            } catch (\Throwable $e) {
                $test['status'] = 'threw';
                $test['message'] = $e::class . ': ' . $e->getMessage();
            }
            try {
                $call($instance, 'tearDown');
            } catch (\Throwable $e) {
                fwrite(STDERR, "tearDown failed: {$e->getMessage()}\n");
            }
            Recorder::formats();
            Recorder::$on = false;
            Recorder::$test = -1;
            Recorder::release($index);
            $recorded[] = $test;
            if (Recorder::$testProblems !== []) {
                $unportable[$path][$label] = implode('; ', array_unique(Recorder::$testProblems));
                $recorded[$index]['status'] = 'unportable';
            }
            fwrite(STDERR, sprintf("%-10s %s::%s %s\n", $recorded[$index]['status'], $path, $label, $recorded[$index]['status'] === 'passed' ? '' : substr($recorded[$index]['status'] === 'unportable' ? $unportable[$path][$label] : $test['message'], 0, 200)));
        }
    }
}

$profile = new Profile('units', 'Tests\\Unit', '', 'Unit-', '', new \stdClass(), []);
$portable = array_map(fn (array $t) => $t['status'] === 'unportable' ? ['skip' => true] + $t : $t, $recorded);
$cases = new Cases($profile, Recorder::$stream, $portable, new \stdClass(), '', [], true);
$written = $cases->write($root . '/tests/compat/database/cases', null);
foreach (array_merge(Recorder::$problems, $cases->problems) as $problem) {
    fwrite(STDERR, "problem: {$problem}\n");
}
@mkdir(__DIR__ . '/out');
$out = __DIR__ . '/out/units' . ($filters === [] ? '' : '-' . substr(md5(implode(',', $filters)), 0, 8)) . '.json';
file_put_contents($out, json_encode(Codec::encode(['tests' => $recorded, 'unportable' => $unportable]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
fwrite(STDOUT, json_encode($written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
