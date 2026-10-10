<?php

// types.*: the PHP value model (php_std::types): arrays (keys, next free
// index, unset then set), juggling and comparison of structured values, and
// the request-model conversions. Values cross as Typed (see ../Typed.php).

use Tests\Compat\PhpStd\Typed;

require_once __DIR__ . '/../Typed.php';

return [
    'types.value' => fn (array $a) => Typed::dump(Typed::decode($a['v'])),
    // The request model of JSON (`Value::from_json`): the driver decodes `v`
    // like Utopia\Http\Request (objects are arrays, except empty ones, which
    // stay stdClass); the Rust side gets the JSON itself.
    'types.from_json' => fn (array $a) => Typed::dump($a['v']),
    // JSON of a value whose strings are UTF-8 and floats finite (`Value::to_json`).
    'types.to_json' => fn (array $a) => json_decode(json_encode(Typed::decode($a['v']), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
    'types.juggle' => function (array $a) {
        $v = Typed::decode($a['v']);
        try {
            $string = @(string) $v;
        } catch (\Error $e) {
            $string = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
        }

        return [
            'type' => gettype($v),
            'truthy' => (bool) $v,
            'string' => $string,
            'double' => Typed::dump(@(float) $v),
        ];
    },
    'types.compare' => function (array $a) {
        $x = Typed::decode($a['a']);
        $y = Typed::decode($a['b']);

        return [
            'loose' => @($x == $y),
            'strict' => $x === $y,
            'cmp' => @($x <=> $y),
            'rcmp' => @($y <=> $x),
            'lt' => @($x < $y),
            'gt' => @($x > $y),
        ];
    },
    // Array semantics: assignments, appends (next free index), unset then
    // set, lookups (string keys normalised like `$a["7"]`).
    'types.array_ops' => function (array $a) {
        $array = Typed::decode($a['a']);
        if (!\is_array($array)) {
            throw new \Tests\Compat\Fault('`a` must be an array');
        }
        $results = [];
        foreach ($a['ops'] as $step) {
            $key = $step['k'] ?? null;
            $results[] = match ($step['op']) {
                'set' => (function () use (&$array, $key, $step) {
                    $array[$key] = Typed::decode($step['v'] ?? null);

                    return;
                })(),
                'push' => (function () use (&$array, $step) {
                    try {
                        $array[] = Typed::decode($step['v'] ?? null);
                    } catch (\Error $e) {
                        return ['$error' => $e->getMessage()];
                    }

                    return array_key_last($array);
                })(),
                'unset' => (function () use (&$array, $key) {
                    unset($array[$key]);

                    return;
                })(),
                'get' => Typed::dump($array[$key] ?? null),
                'exists' => array_key_exists($key, $array),
                default => throw new \Tests\Compat\Fault('unknown step'),
            };
        }

        return ['array' => Typed::dump($array), 'results' => $results, 'list' => array_is_list($array)];
    },
];
