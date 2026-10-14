<?php

// mb.*: mbstring functions on UTF-8 (ext/mbstring). The arguments are the
// function's named parameters, passed straight through; the encoding is
// PHP's internal one (UTF-8) unless an operation passes $encoding.

return [
    'mb.strlen' => fn (array $a) => mb_strlen(...$a),
    'mb.substr' => fn (array $a) => mb_substr(...$a),
    'mb.strtolower' => fn (array $a) => mb_strtolower(...$a),
    'mb.strimwidth' => fn (array $a) => mb_strimwidth($a['string'], 0, $a['width']),
    'mb.strpos' => fn (array $a) => mb_strpos(...$a),
    'mb.check_encoding' => fn (array $a) => mb_check_encoding(...$a),
    'mb.scrub' => fn (array $a) => mb_scrub(...$a),
    'mb.convert_encoding' => fn (array $a) => mb_convert_encoding(...$a),
];
