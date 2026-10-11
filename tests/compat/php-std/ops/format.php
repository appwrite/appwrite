<?php

// format.*: number formatting and casts (ext/standard/formatted_print.c,
// math.c, type.c, array.c). The arguments are the function's named
// parameters, passed straight through; sprintf's variadic $values is a list.

return [
    'format.sprintf' => fn (array $a) => sprintf($a['format'], ...$a['values']),
    'format.vsprintf' => fn (array $a) => vsprintf(...$a),
    'format.number_format' => fn (array $a) => number_format(...$a),
    'format.round' => fn (array $a) => round(...$a),
    'format.floor' => fn (array $a) => floor(...$a),
    'format.ceil' => fn (array $a) => ceil(...$a),
    'format.abs' => fn (array $a) => abs(...$a),
    'format.fmod' => fn (array $a) => fmod(...$a),
    'format.fdiv' => fn (array $a) => fdiv(...$a),
    'format.intdiv' => fn (array $a) => intdiv(...$a),
    'format.intval' => fn (array $a) => intval(...$a),
    'format.floatval' => fn (array $a) => floatval(...$a),
    'format.boolval' => fn (array $a) => boolval(...$a),
    'format.max' => fn (array $a) => max(...$a['values']),
    'format.min' => fn (array $a) => min(...$a['values']),
    // A direct two-argument call, which PHP compiles to its frameless form.
    'format.min_pair' => fn (array $a) => min((float) $a['a'], (float) $a['b']),
    'format.max_pair' => fn (array $a) => max((float) $a['a'], (float) $a['b']),
    'format.pow' => fn (array $a) => (float) $a['base'] ** (float) $a['exponent'],
    'format.max_array' => fn (array $a) => max(...$a),
    'format.min_array' => fn (array $a) => min(...$a),
    'format.arith' => fn (array $a) => match ($a['op']) {
        '+' => $a['a'] + $a['b'],
        '-' => $a['a'] - $a['b'],
        '*' => $a['a'] * $a['b'],
        '/' => $a['a'] / $a['b'],
        default => throw new \Tests\Compat\Fault('unknown operator'),
    },
    'format.array_sum' => fn (array $a) => array_sum(...$a),
];
