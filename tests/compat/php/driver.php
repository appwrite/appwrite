<?php

/**
 * PHP side of the compat protocol (see crates/tools/compat/src/driver.rs):
 * JSON lines on stdin, one reply per line on stdout. Started by `bin/compat`.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/Adapter.php';
require_once __DIR__ . '/Bench.php';
require_once __DIR__ . '/Benchmarks.php';
require_once __DIR__ . '/Codec.php';
require_once __DIR__ . '/Fault.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Task.php';

use Tests\Compat\Bench;
use Tests\Compat\Benchmarks;
use Tests\Compat\Codec;
use Tests\Compat\Fault;
use Tests\Compat\Inventory;
use Tests\Compat\Session;

$operations = [];
$benchmarks = [];
foreach (glob(\dirname(__DIR__) . '/*/Adapter.php') ?: [] as $file) {
    $lib = basename(\dirname($file));
    if ($lib === 'php') {
        continue;
    }
    require_once $file;
    $class = 'Tests\\Compat\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $lib))) . '\\Adapter';
    $adapter = new $class();
    $operations[$lib] = $adapter->operations();
    $benchmarks[$lib] = $adapter instanceof Benchmarks ? $adapter->benchmarks() : [];
}

$session = new Session();

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    $id = null;
    $op = '';
    ob_start();
    try {
        $request = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
        $id = $request->id ?? null;
        $op = (string) ($request->op ?? '');
        $lib = (string) ($request->lib ?? '');
        $args = Codec::decode($request->args ?? new \stdClass());
        if ($args instanceof \stdClass) {
            $args = [];
        }

        $result = match ($op) {
            '$hello' => [
                'runtime' => 'php',
                'version' => PHP_VERSION,
                'opcache' => (bool) ini_get('opcache.enable_cli'),
                'jit' => (string) ini_get('opcache.jit'),
                'os' => (static function (): string {
                    foreach (explode("\n", (string) @file_get_contents('/etc/os-release')) as $line) {
                        if (str_starts_with($line, 'PRETTY_NAME=')) {
                            return trim(substr($line, 12), '"');
                        }
                    }

                    return PHP_OS_FAMILY;
                })(),
                'kernel' => php_uname('r'),
                'libs' => array_map(static fn (array $ops) => array_keys($ops), $operations),
            ],
            '$config' => (function () use (&$session, $args) {
                $session = new Session((string) ($args['ns'] ?? 'compat'), (array) ($args['services'] ?? []));

                return true;
            })(),
            '$reset' => (function () use (&$session) {
                $session = new Session($session->ns, $session->services);

                return true;
            })(),
            '$inventory' => Inventory::take(array_values((array) ($args['src'] ?? []))),
            // Test files (`*Test.php`, `*Tests.php`) under directories of the image, for coverage.
            '$tests' => Inventory::tests(array_values(array_map(strval(...), (array) ($args['dirs'] ?? [])))),
            // Package directories of the image, for task discovery (`compat coverage`).
            '$programs' => Inventory::programs(array_values(array_map(strval(...), (array) ($args['dirs'] ?? [])))),
            // PHP sources under directories of the image, for code size (`compat crates`).
            '$sources' => Inventory::sources(array_values(array_map(strval(...), (array) ($args['dirs'] ?? [])))),
            '$docs' => Inventory::docs(array_values(array_map(strval(...), (array) ($args['symbols'] ?? [])))),
            '$equal' => ($args['a'] ?? null) === ($args['b'] ?? null),
            // Lists a library's benchmarks, or times one (see Bench.php).
            '$bench' => isset($args['name'])
                ? Bench::measure((($benchmarks[$lib][$args['name']] ?? throw new Fault("{$lib}: no benchmark `{$args['name']}`"))[1])($session))
                : array_map(static fn (array $b) => $b[0], $benchmarks[$lib] ?? []),
            default => ($operations[$lib][$op] ?? throw new Fault("{$lib}: unknown operation `{$op}`"))($args, $session),
        };
        $reply = ['id' => $id, 'ok' => Codec::encode($result)];
    } catch (Fault $fault) {
        $reply = ['id' => $id, 'fault' => $fault->getMessage()];
    } catch (\Throwable $error) {
        $reply = ['id' => $id, 'err' => ['class' => $error::class, 'message' => Codec::encode($error->getMessage())]];
    }
    $stray = ob_get_clean();
    if ($stray !== '' && $stray !== false) {
        fwrite(STDERR, "[stdout from {$op}] {$stray}\n");
    }
    try {
        $out = Codec::line($reply);
    } catch (\Throwable $error) {
        $out = Codec::line(['id' => $id, 'fault' => 'cannot encode the result: ' . $error->getMessage()]);
    }
    fwrite(STDOUT, $out);
    fflush(STDOUT);
}
