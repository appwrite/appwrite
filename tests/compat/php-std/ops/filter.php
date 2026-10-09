<?php

// filter.*: filter_var() with the validation filters and FILTER_DEFAULT,
// their flags and options. Reports the result and the last warning
// (silenced with @ and read back with error_get_last()).

return [
    'filter.filter_var' => static function (array $a) {
        error_clear_last();
        $result = @filter_var($a['value'] ?? null, $a['filter'] ?? FILTER_DEFAULT, $a['options'] ?? 0);

        return ['result' => $result, 'warning' => error_get_last()['message'] ?? null];
    },
];
