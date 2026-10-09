<?php

// filter.*: filter_var() with the validation and sanitizing filters and
// FILTER_CALLBACK, their flags and options. Reports the result, the last
// warning (silenced with @ and read back with error_get_last()) and the
// values FILTER_CALLBACK passed to its callable.
//
// An "options" entry of '$callback' stands for a callable: a closure that
// records what it receives and returns, for its n-th call, "<n>", n, false
// and null in turn. Any other entry is passed as given.

return [
    'filter.filter_var' => static function (array $a) {
        error_clear_last();
        $calls = [];
        $options = $a['options'] ?? 0;
        if (\is_array($options) && ($options['options'] ?? null) === '$callback') {
            $options['options'] = static function ($value) use (&$calls) {
                $calls[] = $value;
                $n = \count($calls);

                return [null, '<' . $n . '>', $n, false][$n % 4];
            };
        }
        $result = @filter_var($a['value'] ?? null, $a['filter'] ?? FILTER_DEFAULT, $options);

        return ['result' => $result, 'warning' => error_get_last()['message'] ?? null, 'calls' => $calls];
    },
];
