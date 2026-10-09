<?php

// mb.*: mbstring functions on UTF-8 (ext/mbstring). The arguments are the
// function's named parameters, passed straight through; the encoding is
// PHP's internal one (UTF-8) unless an operation passes $encoding.

return [
    'mb.strlen' => fn (array $a) => mb_strlen(...$a),
    'mb.substr' => fn (array $a) => mb_substr(...$a),
    'mb.strcut' => fn (array $a) => mb_strcut(...$a),
    'mb.str_split' => fn (array $a) => mb_str_split(...$a),
    'mb.strtolower' => fn (array $a) => mb_strtolower(...$a),
    'mb.strtoupper' => fn (array $a) => mb_strtoupper(...$a),
    'mb.convert_case' => fn (array $a) => mb_convert_case(...$a),
    'mb.ucfirst' => fn (array $a) => mb_ucfirst(...$a),
    'mb.lcfirst' => fn (array $a) => mb_lcfirst(...$a),
    'mb.strpos' => fn (array $a) => mb_strpos(...$a),
    'mb.strrpos' => fn (array $a) => mb_strrpos(...$a),
    'mb.stripos' => fn (array $a) => mb_stripos(...$a),
    'mb.strripos' => fn (array $a) => mb_strripos(...$a),
    'mb.strstr' => fn (array $a) => mb_strstr(...$a),
    'mb.strrchr' => fn (array $a) => mb_strrchr(...$a),
    'mb.stristr' => fn (array $a) => mb_stristr(...$a),
    'mb.strrichr' => fn (array $a) => mb_strrichr(...$a),
    'mb.substr_count' => fn (array $a) => mb_substr_count(...$a),
    'mb.str_pad' => fn (array $a) => mb_str_pad(...$a),
    'mb.trim' => fn (array $a) => mb_trim(...$a),
    'mb.ltrim' => fn (array $a) => mb_ltrim(...$a),
    'mb.rtrim' => fn (array $a) => mb_rtrim(...$a),
    'mb.check_encoding' => fn (array $a) => mb_check_encoding(...$a),
    'mb.scrub' => fn (array $a) => mb_scrub(...$a),
    'mb.convert_encoding' => fn (array $a) => mb_convert_encoding(...$a),
    'mb.ord' => fn (array $a) => mb_ord(...$a),
    'mb.chr' => fn (array $a) => mb_chr(...$a),
];
