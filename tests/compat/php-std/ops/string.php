<?php

// string.*: byte-string functions (ext/standard/string.c, ext/ctype). The
// arguments are the function's named parameters, passed straight through.

return [
    'string.strtolower' => fn (array $a) => strtolower(...$a),
    'string.strtoupper' => fn (array $a) => strtoupper(...$a),
    'string.ucfirst' => fn (array $a) => ucfirst(...$a),
    'string.lcfirst' => fn (array $a) => lcfirst(...$a),
    'string.ucwords' => fn (array $a) => ucwords(...$a),
    'string.trim' => fn (array $a) => trim(...$a),
    'string.ltrim' => fn (array $a) => ltrim(...$a),
    'string.rtrim' => fn (array $a) => rtrim(...$a),
    'string.str_pad' => fn (array $a) => str_pad(...$a),
    'string.substr' => fn (array $a) => substr(...$a),
    'string.strpos' => fn (array $a) => strpos(...$a),
    'string.stripos' => fn (array $a) => stripos(...$a),
    'string.strrpos' => fn (array $a) => strrpos(...$a),
    'string.strripos' => fn (array $a) => strripos(...$a),
    'string.str_contains' => fn (array $a) => str_contains(...$a),
    'string.str_starts_with' => fn (array $a) => str_starts_with(...$a),
    'string.str_ends_with' => fn (array $a) => str_ends_with(...$a),
    'string.strstr' => fn (array $a) => strstr(...$a),
    'string.stristr' => fn (array $a) => stristr(...$a),
    'string.strrchr' => fn (array $a) => strrchr(...$a),
    'string.strpbrk' => fn (array $a) => strpbrk(...$a),
    'string.substr_count' => fn (array $a) => substr_count(...$a),
    'string.strspn' => fn (array $a) => strspn(...$a),
    'string.strcspn' => fn (array $a) => strcspn(...$a),
    'string.substr_replace' => fn (array $a) => substr_replace(...$a),
    'string.substr_compare' => fn (array $a) => substr_compare(...$a),
    'string.strcmp' => fn (array $a) => strcmp(...$a),
    'string.strcasecmp' => fn (array $a) => strcasecmp(...$a),
    'string.strncmp' => fn (array $a) => strncmp(...$a),
    'string.strncasecmp' => fn (array $a) => strncasecmp(...$a),
    'string.str_replace' => function (array $a) {
        $result = str_replace($a['search'], $a['replace'], $a['subject'], $count);

        return [$result, $count];
    },
    'string.str_ireplace' => function (array $a) {
        $result = str_ireplace($a['search'], $a['replace'], $a['subject'], $count);

        return [$result, $count];
    },
    'string.strtr' => fn (array $a) => strtr(...$a),
    'string.explode' => fn (array $a) => explode(...$a),
    'string.implode' => fn (array $a) => implode(...$a),
    'string.implode_values' => fn (array $a) => implode(...$a),
    'string.strval' => fn (array $a) => strval(...$a),
    'string.str_split' => fn (array $a) => str_split(...$a),
    'string.chunk_split' => fn (array $a) => chunk_split(...$a),
    'string.wordwrap' => fn (array $a) => wordwrap(...$a),
    'string.nl2br' => fn (array $a) => nl2br(...$a),
    'string.str_repeat' => fn (array $a) => str_repeat(...$a),
    'string.strrev' => fn (array $a) => strrev(...$a),
    'string.ord' => fn (array $a) => ord(...$a),
    'string.chr' => fn (array $a) => chr(...$a),
    'string.quotemeta' => fn (array $a) => quotemeta(...$a),
    'string.addcslashes' => fn (array $a) => addcslashes(...$a),
    'string.stripcslashes' => fn (array $a) => stripcslashes(...$a),
    'string.ctype_alnum' => fn (array $a) => ctype_alnum($a['text']),
    'string.ctype_alpha' => fn (array $a) => ctype_alpha($a['text']),
    'string.ctype_cntrl' => fn (array $a) => ctype_cntrl($a['text']),
    'string.ctype_digit' => fn (array $a) => ctype_digit($a['text']),
    'string.ctype_graph' => fn (array $a) => ctype_graph($a['text']),
    'string.ctype_lower' => fn (array $a) => ctype_lower($a['text']),
    'string.ctype_print' => fn (array $a) => ctype_print($a['text']),
    'string.ctype_punct' => fn (array $a) => ctype_punct($a['text']),
    'string.ctype_space' => fn (array $a) => ctype_space($a['text']),
    'string.ctype_upper' => fn (array $a) => ctype_upper($a['text']),
    'string.ctype_xdigit' => fn (array $a) => ctype_xdigit($a['text']),
];
