<?php

// value.*: type juggling, exactly as the engine does it.

return [
    'value.is_numeric' => fn (array $a) => is_numeric($a['v']),
    'value.to_number' => fn (array $a) => is_numeric($a['v']) ? $a['v'] + 0 : null,
    'value.to_string' => fn (array $a) => \is_array($a['v']) || \is_object($a['v']) ? null : (string) $a['v'],
    'value.truthy' => fn (array $a) => (bool) $a['v'],
    'value.empty' => fn (array $a) => empty($a['v']),
    'value.is_array' => fn (array $a) => \is_array($a['v']),
    'value.loose_eq' => fn (array $a) => $a['a'] == $a['b'],
    'value.loose_str_eq' => fn (array $a) => (string) $a['a'] == (string) $a['b'],
    'value.le_numbers' => fn (array $a) => $a['a'] <= $a['b'],
    'value.sort_strings' => function (array $a) {
        $values = array_map(fn (mixed $v): string => (string) $v, array_values((array) $a['v']));
        sort($values);

        return $values;
    },
];
