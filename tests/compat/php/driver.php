<?php

/**
 * PHP side of the compat protocol (see crates/tools/compat/src/driver.rs):
 * JSON lines on stdin, one reply per line on stdout. Started by `bin/compat`.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/Adapter.php';
require_once __DIR__ . '/Codec.php';
require_once __DIR__ . '/Fault.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Session.php';

use Tests\Compat\Codec;
use Tests\Compat\Fault;
use Tests\Compat\Inventory;
use Tests\Compat\Session;

$operations = [];
foreach (glob(\dirname(__DIR__) . '/*/Adapter.php') ?: [] as $file) {
    $lib = basename(\dirname($file));
    if ($lib === 'php') {
        continue;
    }
    require_once $file;
    $class = 'Tests\\Compat\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $lib))) . '\\Adapter';
    $operations[$lib] = (new $class())->operations();
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
            '$docs' => Inventory::docs(array_values(array_map(strval(...), (array) ($args['symbols'] ?? [])))),
            '$equal' => ($args['a'] ?? null) === ($args['b'] ?? null),
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
