<?php

/**
 * Writes the `covers` of each op in tests/compat/query/spec.json: the library
 * symbols the op's cases call (tests/compat/query/covers.json, written by
 * record.php), plus the symbols listed under `extra` in that file for the
 * hand-written cases.
 *
 *   php tests/compat/query/record/covers.php
 */

$root = dirname(__DIR__, 4);
$specFile = $root . '/tests/compat/query/spec.json';
$spec = json_decode((string) file_get_contents($specFile), true, flags: JSON_THROW_ON_ERROR);
$covers = json_decode((string) file_get_contents($root . '/tests/compat/query/covers.json'), true, flags: JSON_THROW_ON_ERROR);
$byOp = [];
foreach ($covers as $file => $entry) {
    foreach ($entry['symbols'] as $symbol) {
        $byOp[$entry['op']][$symbol] = true;
    }
}
foreach ($spec['ops'] as $op => &$definition) {
    if (!isset($byOp[$op]) && ($definition['covers'] ?? []) !== []) {
        continue;
    }
    $list = array_keys($byOp[$op] ?? []);
    sort($list);
    $definition['covers'] = $list;
}
unset($definition);
foreach (['generators', 'waivers', 'tests_waived', 'deviations'] as $key) {
    if (isset($spec[$key]) && $spec[$key] === []) {
        $spec[$key] = new stdClass();
    }
}
file_put_contents($specFile, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
